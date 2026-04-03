<?php

namespace App\Services\Pilot;

use App\Contracts\Feeds\FeedBuildServiceInterface;
use App\Models\FeedbackRecord;
use App\Models\FeedGeneration;
use App\Models\FeedGenerationSignoff;
use App\Models\FeedGenerationSmokeCheck;
use App\Models\FeedProfile;
use App\Models\PilotRun;
use App\Models\PilotRunEvent;
use App\Models\PromotionRun;
use App\Models\PromotionSnapshot;
use App\Models\SourceConnection;
use App\Models\SourceImport;
use App\Models\User;
use App\Services\Feeds\FeedbackImportService;
use App\Services\Feeds\FeedbackRemediationWorkbenchService;
use App\Services\Feeds\FeedbackSlaService;
use App\Services\Feeds\FeedFirstPullVerificationService;
use App\Services\Feeds\FeedHypercareService;
use App\Services\Feeds\FeedPreviewLinkService;
use App\Services\Feeds\FeedQaBundleService;
use App\Services\Feeds\FeedReleaseReadinessService;
use App\Services\Feeds\FeedReleaseService;
use App\Services\Feeds\FeedRehearsalService;
use App\Services\Feeds\FeedSignoffService;
use App\Services\Feeds\FeedStabilityService;
use App\Services\Feeds\PilotNotificationService;
use App\Services\Ops\EnvironmentContextService;
use App\Services\Ops\SecretsRotationService;
use App\Services\Promotion\PromotionService;
use App\Services\Source\SourceConnectionTestService;
use App\Services\Source\SourceSyncWorkflowService;
use RuntimeException;
use Throwable;

class PilotExecutionService
{
    public function __construct(
        private readonly PilotRunStateMachine $stateMachine,
        private readonly PilotReadinessScoreService $readinessScoreService,
        private readonly EnvironmentContextService $environmentContextService,
        private readonly FeedRehearsalService $rehearsalService,
        private readonly PromotionService $promotionService,
        private readonly SourceConnectionTestService $connectionTestService,
        private readonly SourceSyncWorkflowService $sourceSyncWorkflowService,
        private readonly FeedBuildServiceInterface $feedBuildService,
        private readonly FeedQaBundleService $feedQaBundleService,
        private readonly FeedPreviewLinkService $previewLinkService,
        private readonly FeedSignoffService $signoffService,
        private readonly FeedReleaseService $feedReleaseService,
        private readonly FeedReleaseReadinessService $feedReleaseReadinessService,
        private readonly FeedFirstPullVerificationService $firstPullVerificationService,
        private readonly FeedbackImportService $feedbackImportService,
        private readonly FeedbackRemediationWorkbenchService $feedbackWorkbenchService,
        private readonly FeedbackSlaService $feedbackSlaService,
        private readonly FeedHypercareService $feedHypercareService,
        private readonly FeedStabilityService $feedStabilityService,
        private readonly SecretsRotationService $secretsRotationService,
        private readonly PilotFixtureLibrary $fixtureLibrary,
        private readonly PilotNotificationService $notificationService,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public function plan(FeedProfile $feedProfile, array $options = [], ?User $user = null): PilotRun
    {
        $feedProfile->loadMissing(['shop', 'sourceConnection.latestImport', 'latestGeneration', 'publishedGeneration']);
        $existing = PilotRun::query()
            ->where('feed_profile_id', $feedProfile->id)
            ->whereNotIn('state', PilotRun::terminalStates())
            ->latest('id')
            ->first();

        if ($existing instanceof PilotRun) {
            return $this->refreshOperationalState($existing->fresh($this->relations()));
        }

        $environment = $this->environmentContextService->summary();
        $sourceSnapshot = $this->resolveSourceSnapshot($feedProfile, $user);
        $sourceConnection = $feedProfile->sourceConnection;
        $run = PilotRun::create([
            'shop_id' => $feedProfile->shop_id,
            'feed_profile_id' => $feedProfile->id,
            'source_connection_id' => $feedProfile->source_connection_id,
            'source_snapshot_id' => $sourceSnapshot?->id,
            'candidate_generation_id' => $feedProfile->latestGeneration?->id,
            'published_generation_id' => $feedProfile->publishedGeneration?->id,
            'initiated_by_user_id' => $user?->id,
            'owner_user_id' => $user?->id,
            'environment_class' => $environment['class'],
            'environment_label' => $environment['label'],
            'state' => PilotRun::STATE_PLANNED,
            'current_step' => PilotRun::STEP_STAGING_REHEARSAL,
            'note' => $options['note'] ?? null,
            'summary' => [
                'execution' => [
                    'planned_at' => now()->toIso8601String(),
                ],
                'references' => [
                    'source_snapshot_id' => $sourceSnapshot?->id,
                ],
                'sections' => [],
            ],
            'meta' => [
                'planning' => [
                    'source_connection' => [
                        'last_connection_check_status' => $sourceConnection?->last_connection_check_status,
                        'last_sync_status' => $sourceConnection?->last_sync_status,
                        'secret_state' => $sourceConnection?->promotionSecretState(),
                        'secret_rebind_required' => $sourceConnection?->promotionSecretRebindRequired() ?? false,
                    ],
                ],
                'options' => $this->normalizeOptions($options),
            ],
            'started_at' => now(),
        ]);

        $this->recordEvent(
            $run,
            PilotRunEvent::TYPE_TRANSITION,
            PilotRunEvent::STATUS_INFO,
            'Pilot run planned',
            'Pilot execution has been planned and is ready for staging rehearsal.',
            $user,
            PilotRun::STEP_STAGING_REHEARSAL,
            null,
            PilotRun::STATE_PLANNED,
            [
                'source_snapshot_id' => $sourceSnapshot?->id,
                'source_snapshot_checksum' => $sourceSnapshot?->checksum,
            ]
        );

        return $this->refreshOperationalState($run->fresh($this->relations()));
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function run(FeedProfile $feedProfile, array $options = [], ?User $user = null): PilotRun
    {
        $run = $this->plan($feedProfile, $options, $user);

        return $this->runUntilPauseOrCompletion($run, $options, $user);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function runUntilPauseOrCompletion(PilotRun $run, array $options = [], ?User $user = null): PilotRun
    {
        $iterations = 0;
        $run = $this->refreshOperationalState($run->fresh($this->relations()));

        while (! $run->isTerminal() && $run->state !== PilotRun::STATE_BLOCKED) {
            if (++$iterations > 16) {
                break;
            }

            $result = $this->executeNextStep($run, $options, $user);
            $run = $result['run'];

            if (! $result['progressed']) {
                break;
            }
        }

        return $this->refreshOperationalState($run->fresh($this->relations()));
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{run:PilotRun,progressed:bool,step:?string}
     */
    public function executeNextStep(PilotRun $run, array $options = [], ?User $user = null): array
    {
        $run = $this->refreshOperationalState($this->assignOwner($run->fresh($this->relations()), $user));

        if ($run->isTerminal() || $run->state === PilotRun::STATE_BLOCKED) {
            return [
                'run' => $run,
                'progressed' => false,
                'step' => null,
            ];
        }

        $step = $this->stateMachine->defaultStepForState($run->state);

        if ($step === null) {
            return [
                'run' => $run,
                'progressed' => false,
                'step' => null,
            ];
        }

        $options = $this->normalizeOptions($options, $run);

        return match ($step) {
            PilotRun::STEP_STAGING_REHEARSAL => $this->executeStagingRehearsal($run, $options, $user),
            PilotRun::STEP_PROMOTION => $this->executePromotion($run, $options, $user),
            PilotRun::STEP_SOURCE_VERIFICATION => $this->executeSourceVerification($run, $user),
            PilotRun::STEP_SYNC => $this->executeInitialSync($run, $options, $user),
            PilotRun::STEP_CANDIDATE_BUILD => $this->executeCandidateBuild($run, $options, $user),
            PilotRun::STEP_QA => $this->executeQaPreparation($run, $user),
            PilotRun::STEP_SIGNOFF => $this->executeSignoff($run, $user),
            PilotRun::STEP_PUBLISH => $this->executePublish($run, $options, $user),
            PilotRun::STEP_RELEASE_VERIFICATION => $this->executeReleaseVerification($run, $user),
            PilotRun::STEP_FEEDBACK => $this->executeFeedbackReview($run, $options, $user),
            PilotRun::STEP_HYPERCARE => $this->executeHypercareActivation($run, $user),
            PilotRun::STEP_CLOSEOUT => $this->executeCloseout($run, $user),
            default => [
                'run' => $run,
                'progressed' => false,
                'step' => $step,
            ],
        };
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function resume(PilotRun $run, array $options = [], ?User $user = null): PilotRun
    {
        $run = $run->fresh($this->relations());

        if ($run->isTerminal()) {
            throw new RuntimeException('A terminal pilot run cannot be resumed.');
        }

        if (! in_array($run->state, [PilotRun::STATE_BLOCKED, PilotRun::STATE_FAILED], true)) {
            return $this->runUntilPauseOrCompletion($run, $options, $user);
        }

        $retryState = data_get($run->summary, 'resume.retry_state');
        $allowed = (bool) data_get($run->summary, 'resume.allowed', false);

        if (! $allowed || ! is_string($retryState) || $retryState === '') {
            throw new RuntimeException('This pilot run cannot be resumed safely without a reset.');
        }

        $run = $this->transition(
            $run,
            $retryState,
            $user,
            data_get($run->summary, 'resume.step', $this->stateMachine->defaultStepForState($retryState)),
            'Pilot run resumed',
            'Pilot execution resumed after operator intervention.',
            PilotRunEvent::STATUS_INFO,
            [
                'resumed_from' => $run->state,
            ]
        );

        $run = $this->mergeSummary($run, [
            'resume' => [
                'resumed_at' => now()->toIso8601String(),
            ],
            'blocker' => null,
        ]);

        return $this->runUntilPauseOrCompletion($run, $options, $user);
    }

    public function abort(PilotRun $run, string $reason, ?User $user = null): PilotRun
    {
        if (blank($reason)) {
            throw new RuntimeException('Abort reason is required.');
        }

        $run = $run->fresh($this->relations());

        if ($run->isTerminal()) {
            return $run;
        }

        $run = $this->transition(
            $run,
            PilotRun::STATE_ABORTED,
            $user,
            $run->current_step,
            'Pilot run aborted',
            'Pilot execution was aborted. Existing evidence and history were preserved.',
            PilotRunEvent::STATUS_WARNING,
            [
                'reason' => $reason,
            ],
            $reason
        );

        $run = $this->mergeSummary($run, [
            'abort' => [
                'reason' => $reason,
                'evidence_preserved' => true,
                'operator_next_steps' => [
                    'Review the evidence pack and execution log for the aborted attempt.',
                    'Open a new pilot run when the blocking cause is resolved.',
                ],
            ],
        ]);

        $this->notify($run, 'feed.pilot_aborted', 'Pilot run aborted', $reason, 'warning');

        return $this->refreshOperationalState($run);
    }

    public function addEvent(PilotRun $run, string $type, string $message, ?User $user = null): PilotRunEvent
    {
        if (! in_array($type, [PilotRunEvent::TYPE_NOTE, PilotRunEvent::TYPE_INCIDENT, PilotRunEvent::TYPE_OVERRIDE], true)) {
            throw new RuntimeException('Unsupported pilot event type.');
        }

        return $this->recordEvent(
            $run->fresh($this->relations()),
            $type,
            $type === PilotRunEvent::TYPE_INCIDENT ? PilotRunEvent::STATUS_WARNING : PilotRunEvent::STATUS_INFO,
            ucfirst($type).' added',
            $message,
            $user,
            $run->current_step
        );
    }

    public function latestOpenRun(FeedProfile $feedProfile): ?PilotRun
    {
        return PilotRun::query()
            ->where('feed_profile_id', $feedProfile->id)
            ->whereNotIn('state', PilotRun::terminalStates())
            ->latest('id')
            ->first();
    }

    public function refreshOperationalState(PilotRun $run): PilotRun
    {
        $run->loadMissing($this->relations());
        $score = $this->readinessScoreService->score($run->feedProfile, $run);
        $nextStep = $run->state === PilotRun::STATE_BLOCKED
            ? [
                'step' => data_get($run->summary, 'resume.step'),
                'label' => $this->stateMachine->labelForStep(data_get($run->summary, 'resume.step')),
            ]
            : $this->stateMachine->nextStepForState($run->state);

        $summary = array_replace_recursive($run->summary ?? [], [
            'execution' => [
                'state_label' => $this->stateMachine->labelForState($run->state),
                'current_step_label' => $this->stateMachine->labelForStep($run->current_step),
                'next_step' => $nextStep['step'] ?? null,
                'next_step_label' => $nextStep['label'] ?? null,
                'updated_at' => now()->toIso8601String(),
            ],
            'readiness' => $score,
            'resume' => array_replace_recursive($this->resumeRules($run), (array) data_get($run->summary, 'resume', [])),
            'abort' => $this->abortRules($run),
        ]);

        $run->forceFill([
            'summary' => $summary,
            'current_step' => $run->current_step ?: ($nextStep['step'] ?? $run->current_step),
        ])->save();

        return $run->fresh($this->relations());
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{run:PilotRun,progressed:bool,step:string}
     */
    private function executeStagingRehearsal(PilotRun $run, array $options, ?User $user): array
    {
        if ($run->state === PilotRun::STATE_PLANNED) {
            $run = $this->transition(
                $run,
                PilotRun::STATE_STAGING_REHEARSAL_PENDING,
                $user,
                PilotRun::STEP_STAGING_REHEARSAL,
                'Staging rehearsal started',
                'Pilot execution is running the staging rehearsal.',
                PilotRunEvent::STATUS_INFO
            );
        }

        try {
            $result = $this->rehearsalService->run($run->feedProfile, [
                'with_sync' => (bool) ($options['with_sync'] ?? false),
                'with_build' => (bool) ($options['with_build'] ?? false),
                'with_preview' => true,
                'with_smoke' => true,
                'with_rollback_check' => $run->feedProfile->publishedGeneration instanceof FeedGeneration,
            ], $user);
        } catch (Throwable $exception) {
            return $this->failedResult(
                $run,
                'rehearsal_failed',
                $exception,
                PilotRun::STEP_STAGING_REHEARSAL,
                PilotRun::STATE_STAGING_REHEARSAL_PENDING,
                $user
            );
        }

        $run = $this->mergeRunReferences($run, [
            'candidate_generation_id' => $result['generation']?->id,
        ], [
            'sections' => [
                'rehearsal' => [
                    'status' => $result['status'],
                    'ops_run_id' => $result['run']?->id,
                    'generation_id' => $result['generation']?->id,
                    'preview_url' => $result['preview_url'],
                    'qa_bundle' => $result['qa_bundle'],
                    'steps' => $result['steps'],
                    'warnings' => $result['warnings'],
                    'blocking_issues' => $result['blocking_issues'],
                ],
            ],
        ], [
            'rehearsal' => [
                'ops_run_id' => $result['run']?->id,
                'preview_url' => $result['preview_url'],
                'qa_bundle_path' => $result['qa_bundle']['path'] ?? null,
            ],
        ]);

        if ($result['status'] !== 'passed') {
            return $result['status'] === 'blocked'
                ? $this->blockedResult(
                    $run,
                    'staging_rehearsal_blocked',
                    $result['blocking_issues'][0] ?? 'Staging rehearsal requires operator attention.',
                    PilotRun::STEP_STAGING_REHEARSAL,
                    PilotRun::STATE_STAGING_REHEARSAL_PENDING,
                    [
                        'Review rehearsal blocking issues in the rehearsal screen.',
                        'Fix source, mapping, or conformance issues and resume the pilot run.',
                    ],
                    $user,
                    ['rehearsal' => $result]
                )
                : $this->failedResult(
                    $run,
                    'staging_rehearsal_failed',
                    $result['blocking_issues'][0] ?? 'Staging rehearsal failed.',
                    PilotRun::STEP_STAGING_REHEARSAL,
                    PilotRun::STATE_STAGING_REHEARSAL_PENDING,
                    $user,
                    ['rehearsal' => $result]
                );
        }

        $run = $this->transition(
            $run,
            PilotRun::STATE_STAGING_REHEARSAL_PASSED,
            $user,
            PilotRun::STEP_STAGING_REHEARSAL,
            'Staging rehearsal passed',
            'Pilot rehearsal completed successfully.',
            PilotRunEvent::STATUS_OK
        );
        $run = $this->transition(
            $run,
            PilotRun::STATE_PROMOTION_PENDING,
            $user,
            PilotRun::STEP_PROMOTION,
            'Promotion checkpoint opened',
            'Pilot execution is ready for promotion dry-run and apply.',
            PilotRunEvent::STATUS_INFO
        );

        return [
            'run' => $run,
            'progressed' => true,
            'step' => PilotRun::STEP_STAGING_REHEARSAL,
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{run:PilotRun,progressed:bool,step:string}
     */
    private function executePromotion(PilotRun $run, array $options, ?User $user): array
    {
        $snapshot = $run->sourceSnapshot ?: $this->resolveSourceSnapshot($run->feedProfile, $user);

        if (! $snapshot instanceof PromotionSnapshot) {
            return $this->blockedResult(
                $run,
                'promotion_snapshot_missing',
                'No staging snapshot is available for promotion.',
                PilotRun::STEP_PROMOTION,
                PilotRun::STATE_PROMOTION_PENDING,
                [
                    'Generate or import a staging promotion snapshot in Promotion Center.',
                    'Resume the pilot run after the snapshot is available.',
                ],
                $user
            );
        }

        $run = $this->mergeRunReferences($run, ['source_snapshot_id' => $snapshot->id]);

        try {
            $dryRun = $this->promotionService->dryRunSnapshot(
                $snapshot,
                $run->feedProfile,
                (string) ($options['promotion_strategy'] ?? PromotionRun::STRATEGY_SAFE_MERGE),
                $user,
                'Pilot promotion dry-run'
            );
        } catch (Throwable $exception) {
            return $this->failedResult(
                $run,
                'promotion_dry_run_failed',
                $exception,
                PilotRun::STEP_PROMOTION,
                PilotRun::STATE_PROMOTION_PENDING,
                $user
            );
        }

        if ($dryRun->status === PromotionRun::STATUS_BLOCKED) {
            return $this->blockedResult(
                $run,
                'promotion_drift',
                $dryRun->errors[0] ?? 'Promotion dry-run found blocking drift.',
                PilotRun::STEP_PROMOTION,
                PilotRun::STATE_PROMOTION_PENDING,
                [
                    'Review the promotion diff and resolve incompatible drift.',
                    'Retry promotion apply after drift is resolved.',
                ],
                $user,
                [
                    'promotion' => [
                        'dry_run_id' => $dryRun->id,
                        'dry_run_status' => $dryRun->status,
                    ],
                ]
            );
        }

        try {
            $apply = $this->promotionService->applySnapshot(
                $snapshot,
                $run->feedProfile,
                (string) ($options['promotion_strategy'] ?? PromotionRun::STRATEGY_SAFE_MERGE),
                $user,
                'Pilot promotion apply'
            );
        } catch (Throwable $exception) {
            return $this->failedResult(
                $run,
                'promotion_apply_failed',
                $exception,
                PilotRun::STEP_PROMOTION,
                PilotRun::STATE_PROMOTION_PENDING,
                $user,
                [
                    'promotion' => [
                        'dry_run_id' => $dryRun->id,
                    ],
                ]
            );
        }

        $run = $this->mergeSummary($run, [
            'sections' => [
                'promotion' => [
                    'source_snapshot_id' => $snapshot->id,
                    'source_snapshot_checksum' => $snapshot->checksum,
                    'dry_run_id' => $dryRun->id,
                    'dry_run_status' => $dryRun->status,
                    'dry_run_report' => data_get($dryRun->summary, 'report'),
                    'apply_run_id' => $apply->id,
                    'apply_status' => $apply->status,
                    'apply_report' => data_get($apply->summary, 'report'),
                    'secret_rebind' => $apply->summary['secret_rebind'] ?? $dryRun->summary['secret_rebind'] ?? null,
                ],
            ],
        ]);

        if ($apply->status === PromotionRun::STATUS_BLOCKED) {
            return $this->blockedResult(
                $run,
                'promotion_drift',
                $apply->errors[0] ?? 'Promotion apply was blocked.',
                PilotRun::STEP_PROMOTION,
                PilotRun::STATE_PROMOTION_PENDING,
                [
                    'Resolve promotion conflicts or source-shape incompatibilities.',
                    'Retry the promotion step after drift is cleared.',
                ],
                $user,
                [
                    'promotion' => [
                        'apply_run_id' => $apply->id,
                        'apply_status' => $apply->status,
                    ],
                ]
            );
        }

        if ($apply->status === PromotionRun::STATUS_FAILED) {
            return $this->failedResult(
                $run,
                'promotion_apply_failed',
                $apply->errors[0] ?? 'Promotion apply failed.',
                PilotRun::STEP_PROMOTION,
                PilotRun::STATE_PROMOTION_PENDING,
                $user,
                [
                    'promotion' => [
                        'apply_run_id' => $apply->id,
                    ],
                ]
            );
        }

        $run = $this->transition(
            $run,
            PilotRun::STATE_PROMOTION_APPLIED,
            $user,
            PilotRun::STEP_PROMOTION,
            'Promotion applied',
            'Promotion dry-run and apply completed.',
            $apply->status === PromotionRun::STATUS_WARNING ? PilotRunEvent::STATUS_WARNING : PilotRunEvent::STATUS_OK,
            [
                'promotion_apply_run_id' => $apply->id,
            ]
        );

        $secretRebindRequired = (bool) data_get($apply->summary, 'secret_rebind.required', false);
        $plannedSecretRebindRequired = (bool) data_get($run->meta, 'planning.source_connection.secret_rebind_required', false);

        if (! $secretRebindRequired && $plannedSecretRebindRequired) {
            $connection = $run->feedProfile->sourceConnection?->fresh();

            if ($connection instanceof SourceConnection) {
                $connection->savePromotionMeta([
                    'secret_state' => 'not_validated',
                    'secret_rebind_required' => true,
                    'source_snapshot_checksum' => $snapshot->checksum,
                    'applied_at' => now()->toIso8601String(),
                ]);
            }

            $secretRebindRequired = true;
        }

        if ($secretRebindRequired) {
            $run = $this->transition(
                $run,
                PilotRun::STATE_SECRET_REBIND_PENDING,
                $user,
                PilotRun::STEP_SOURCE_VERIFICATION,
                'Secret rebind pending',
                'Promotion apply requires target source secrets to be rebound and validated.',
                PilotRunEvent::STATUS_WARNING,
                [
                    'secret_rebind' => $apply->summary['secret_rebind'] ?? [],
                ]
            );
        }

        return [
            'run' => $run,
            'progressed' => true,
            'step' => PilotRun::STEP_PROMOTION,
        ];
    }

    /**
     * @return array{run:PilotRun,progressed:bool,step:string}
     */
    private function executeSourceVerification(PilotRun $run, ?User $user): array
    {
        $connection = $run->feedProfile->sourceConnection;

        if (! $connection instanceof SourceConnection) {
            return $this->blockedResult(
                $run,
                'source_connection_missing',
                'The feed profile does not have a source connection.',
                PilotRun::STEP_SOURCE_VERIFICATION,
                $run->state,
                [
                    'Attach a valid source connection to the feed profile.',
                    'Resume the pilot run after the source connection is configured.',
                ],
                $user
            );
        }

        $secretSummary = $this->secretsRotationService->summarize($connection);

        if ($connection->promotionSecretRebindRequired()) {
            return $this->blockedResult(
                $run,
                'secret_rebind_missing',
                'Source secrets were not validated after promotion apply.',
                PilotRun::STEP_SOURCE_VERIFICATION,
                PilotRun::STATE_SECRET_REBIND_PENDING,
                [
                    'Re-enter or validate the target source secret.',
                    'Run Test connection in Source Connections and then resume the pilot run.',
                ],
                $user,
                [
                    'secret_rebind' => $secretSummary,
                ]
            );
        }

        $check = $this->connectionTestService->test($connection->fresh());
        $run = $this->mergeSummary($run, [
            'sections' => [
                'secret_rebind' => $secretSummary,
                'source_verification' => [
                    'status' => $check->status,
                    'message' => $check->message,
                    'meta' => $check->meta,
                ],
            ],
        ]);

        if ($check->status !== SourceConnection::CHECK_STATUS_OK) {
            return $this->blockedResult(
                $run,
                'source_verification_failed',
                $check->message,
                PilotRun::STEP_SOURCE_VERIFICATION,
                $run->state === PilotRun::STATE_SECRET_REBIND_PENDING ? PilotRun::STATE_SECRET_REBIND_PENDING : PilotRun::STATE_PROMOTION_APPLIED,
                [
                    'Fix source credentials or connectivity issues.',
                    'Resume the pilot run after the connection test passes.',
                ],
                $user
            );
        }

        $run = $this->transition(
            $run,
            PilotRun::STATE_SOURCE_VERIFIED,
            $user,
            PilotRun::STEP_SOURCE_VERIFICATION,
            'Source verified',
            'The promoted target source configuration has been verified.',
            PilotRunEvent::STATUS_OK
        );

        return [
            'run' => $run,
            'progressed' => true,
            'step' => PilotRun::STEP_SOURCE_VERIFICATION,
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{run:PilotRun,progressed:bool,step:string}
     */
    private function executeInitialSync(PilotRun $run, array $options, ?User $user): array
    {
        $connection = $run->feedProfile->sourceConnection;

        if (! $connection instanceof SourceConnection) {
            return $this->blockedResult(
                $run,
                'source_connection_missing',
                'Cannot sync without a source connection.',
                PilotRun::STEP_SYNC,
                PilotRun::STATE_SOURCE_VERIFIED,
                ['Attach a source connection and resume the pilot run.'],
                $user
            );
        }

        $latestImport = $connection->latestImport;
        $reuseExistingImport = ! ($options['with_sync'] ?? false)
            && $latestImport instanceof SourceImport
            && $latestImport->status === SourceImport::STATUS_NORMALIZED;

        try {
            $import = $reuseExistingImport
                ? $latestImport
                : $this->sourceSyncWorkflowService->run($connection->fresh(), false);
        } catch (Throwable $exception) {
            return $this->failedResult(
                $run,
                'initial_sync_failed',
                $exception,
                PilotRun::STEP_SYNC,
                PilotRun::STATE_SOURCE_VERIFIED,
                $user
            );
        }

        if (! $import instanceof SourceImport || $import->status !== SourceImport::STATUS_NORMALIZED) {
            return $this->blockedResult(
                $run,
                'initial_sync_failed',
                $import?->error_message ?: 'Source sync did not complete successfully.',
                PilotRun::STEP_SYNC,
                PilotRun::STATE_SOURCE_VERIFIED,
                [
                    'Review the source sync logs and fix fetch or normalization issues.',
                    'Resume the pilot run after a successful sync.',
                ],
                $user
            );
        }

        $run = $this->mergeMeta($run, [
            'sync' => [
                'source_import_id' => $import->id,
                'reused_existing_import' => $reuseExistingImport,
            ],
        ]);
        $run = $this->mergeSummary($run, [
            'sections' => [
                'sync' => [
                    'source_import_id' => $import->id,
                    'status' => $import->status,
                    'finished_at' => $import->finished_at?->toIso8601String(),
                    'summary' => $import->meta['summary'] ?? $import->meta,
                    'reused_existing_import' => $reuseExistingImport,
                ],
            ],
        ]);
        $run = $this->transition(
            $run,
            PilotRun::STATE_INITIAL_SYNC_COMPLETED,
            $user,
            PilotRun::STEP_SYNC,
            'Initial sync completed',
            $reuseExistingImport
                ? 'A fresh normalized import was already available and reused.'
                : 'A source sync completed successfully for the pilot run.',
            PilotRunEvent::STATUS_OK
        );

        return [
            'run' => $run,
            'progressed' => true,
            'step' => PilotRun::STEP_SYNC,
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{run:PilotRun,progressed:bool,step:string}
     */
    private function executeCandidateBuild(PilotRun $run, array $options, ?User $user): array
    {
        $latestImportId = data_get($run->meta, 'sync.source_import_id');
        $reuseGeneration = ! ($options['with_build'] ?? false)
            && $run->feedProfile->latestGeneration instanceof FeedGeneration
            && in_array($run->feedProfile->latestGeneration->status, [FeedGeneration::STATUS_BUILT, FeedGeneration::STATUS_PUBLISHED], true)
            && ($latestImportId === null || $run->feedProfile->latestGeneration->source_import_id === $latestImportId);

        try {
            $generation = $reuseGeneration
                ? $run->feedProfile->latestGeneration
                : $this->feedBuildService->build($run->feedProfile, is_numeric($latestImportId) ? (int) $latestImportId : null);
        } catch (Throwable $exception) {
            return $this->failedResult(
                $run,
                'candidate_build_failed',
                $exception,
                PilotRun::STEP_CANDIDATE_BUILD,
                PilotRun::STATE_INITIAL_SYNC_COMPLETED,
                $user
            );
        }

        if (! $generation instanceof FeedGeneration) {
            return $this->failedResult(
                $run,
                'candidate_build_failed',
                'No generation was produced for the pilot candidate build.',
                PilotRun::STEP_CANDIDATE_BUILD,
                PilotRun::STATE_INITIAL_SYNC_COMPLETED,
                $user
            );
        }

        if ($generation->release_status === FeedGeneration::RELEASE_STATUS_BUILT) {
            $generation = $this->feedReleaseService->markCandidate($generation->fresh(), $user, 'Pilot candidate build checkpoint');
        }

        $run = $this->mergeRunReferences($run, [
            'candidate_generation_id' => $generation->id,
        ], [
            'sections' => [
                'candidate_generation' => [
                    'generation_id' => $generation->id,
                    'status' => $generation->status,
                    'release_status' => $generation->release_status,
                    'checksum' => $generation->checksum,
                    'built_at' => $generation->built_at?->toIso8601String(),
                    'summary' => $generation->meta['summary'] ?? [],
                    'reused_existing_generation' => $reuseGeneration,
                ],
            ],
        ]);
        $run = $this->transition(
            $run,
            PilotRun::STATE_CANDIDATE_BUILT,
            $user,
            PilotRun::STEP_CANDIDATE_BUILD,
            'Candidate built',
            'Pilot candidate generation is ready for QA preparation.',
            PilotRunEvent::STATUS_OK
        );

        return [
            'run' => $run,
            'progressed' => true,
            'step' => PilotRun::STEP_CANDIDATE_BUILD,
        ];
    }

    /**
     * @return array{run:PilotRun,progressed:bool,step:string}
     */
    private function executeQaPreparation(PilotRun $run, ?User $user): array
    {
        $generation = $run->candidateGeneration;

        if (! $generation instanceof FeedGeneration) {
            return $this->blockedResult(
                $run,
                'candidate_missing',
                'Build a candidate generation before QA preparation.',
                PilotRun::STEP_QA,
                PilotRun::STATE_CANDIDATE_BUILT,
                ['Build the candidate generation and resume the pilot run.'],
                $user
            );
        }

        try {
            $qaBundle = $this->feedQaBundleService->generate($generation->fresh(), $user, 'Pilot QA bundle');
            $previewLink = $this->previewLinkService->create(
                $generation->fresh(),
                1440,
                $user,
                'Pilot QA preview',
                ['target' => 'pilot']
            );
        } catch (Throwable $exception) {
            return $this->failedResult(
                $run,
                'qa_preparation_failed',
                $exception,
                PilotRun::STEP_QA,
                PilotRun::STATE_CANDIDATE_BUILT,
                $user
            );
        }

        $run = $this->mergeMeta($run, [
            'qa' => [
                'qa_bundle_path' => $qaBundle['path'],
                'preview_link_id' => $previewLink->id,
                'preview_url' => $this->previewLinkService->urlFor($previewLink),
            ],
        ]);
        $run = $this->mergeSummary($run, [
            'sections' => [
                'qa' => [
                    'qa_bundle' => $qaBundle,
                    'preview_link_id' => $previewLink->id,
                    'preview_url' => $this->previewLinkService->urlFor($previewLink),
                    'current_signoff' => $this->signoffService->current($generation)?->toArray(),
                ],
            ],
        ]);
        $run = $this->transition(
            $run,
            PilotRun::STATE_QA_READY,
            $user,
            PilotRun::STEP_QA,
            'QA ready',
            'QA bundle and preview URL are prepared for pilot review.',
            PilotRunEvent::STATUS_OK
        );

        return [
            'run' => $run,
            'progressed' => true,
            'step' => PilotRun::STEP_QA,
        ];
    }

    /**
     * @return array{run:PilotRun,progressed:bool,step:string}
     */
    private function executeSignoff(PilotRun $run, ?User $user): array
    {
        $generation = $run->candidateGeneration;

        if (! $generation instanceof FeedGeneration) {
            return $this->blockedResult(
                $run,
                'candidate_missing',
                'A candidate generation is required for sign-off.',
                PilotRun::STEP_SIGNOFF,
                PilotRun::STATE_QA_READY,
                ['Build or select a candidate generation and resume the pilot run.'],
                $user
            );
        }

        $signoff = $this->signoffService->current($generation);
        $evaluation = $this->signoffService->evaluate($run->feedProfile, $generation);
        $requiredStatus = (string) ($evaluation['required_status'] ?? FeedGenerationSignoff::STATUS_INTERNAL_APPROVED);

        if (! ($evaluation['allowed'] ?? false)) {
            if (
                in_array($requiredStatus, [FeedGenerationSignoff::STATUS_INTERNAL_APPROVED], true)
                && $signoff?->status !== FeedGenerationSignoff::STATUS_REJECTED
            ) {
                $signoff = $this->signoffService->record(
                    $generation,
                    FeedGenerationSignoff::STATUS_INTERNAL_APPROVED,
                    $user,
                    $user?->name,
                    'Recorded by pilot execution orchestration.',
                    'Pilot sign-off checkpoint'
                );
                $evaluation = $this->signoffService->evaluate($run->feedProfile->fresh(), $generation->fresh());
            } else {
                return $this->blockedResult(
                    $run,
                    'signoff_missing',
                    $evaluation['reasons'][0] ?? 'Required sign-off is missing.',
                    PilotRun::STEP_SIGNOFF,
                    PilotRun::STATE_QA_READY,
                    [
                        'Open the release center or generation details and record the required sign-off.',
                        'Resume the pilot run after sign-off is complete.',
                    ],
                    $user
                );
            }
        }

        if (! in_array($generation->release_status, [FeedGeneration::RELEASE_STATUS_APPROVED, FeedGeneration::RELEASE_STATUS_PUBLISHED], true)) {
            $generation = $this->feedReleaseService->approve($generation->fresh(), $user, 'Pilot sign-off completed');
        }

        $run = $this->mergeSummary($run, [
            'sections' => [
                'signoff' => [
                    'required' => $evaluation['required'] ?? false,
                    'required_status' => $requiredStatus,
                    'current' => $this->signoffService->current($generation)?->toArray(),
                    'generation_release_status' => $generation->release_status,
                ],
            ],
        ]);
        $run = $this->mergeRunReferences($run, [
            'candidate_generation_id' => $generation->id,
        ]);
        $run = $this->transition(
            $run,
            PilotRun::STATE_SIGNOFF_COMPLETED,
            $user,
            PilotRun::STEP_SIGNOFF,
            'Sign-off completed',
            'Pilot sign-off and generation approval are complete.',
            PilotRunEvent::STATUS_OK
        );
        $run = $this->transition(
            $run,
            PilotRun::STATE_PUBLISH_PENDING,
            $user,
            PilotRun::STEP_PUBLISH,
            'Publish checkpoint opened',
            'Pilot run is ready to publish the approved candidate.',
            PilotRunEvent::STATUS_INFO
        );

        return [
            'run' => $run,
            'progressed' => true,
            'step' => PilotRun::STEP_SIGNOFF,
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{run:PilotRun,progressed:bool,step:string}
     */
    private function executePublish(PilotRun $run, array $options, ?User $user): array
    {
        if (! ($options['with_publish'] ?? false)) {
            $run = $this->mergeSummary($run, [
                'execution' => [
                    'manual_publish_required' => true,
                ],
            ]);

            return [
                'run' => $run,
                'progressed' => false,
                'step' => PilotRun::STEP_PUBLISH,
            ];
        }

        $generation = $run->candidateGeneration;

        if (! $generation instanceof FeedGeneration) {
            return $this->blockedResult(
                $run,
                'candidate_missing',
                'An approved candidate generation is required before publish.',
                PilotRun::STEP_PUBLISH,
                PilotRun::STATE_PUBLISH_PENDING,
                ['Approve a candidate generation and resume the pilot run.'],
                $user
            );
        }

        $readiness = $this->feedReleaseReadinessService->evaluate($run->feedProfile, $generation);

        if (($readiness['blocking_issues'] ?? []) !== []) {
            return $this->blockedResult(
                $run,
                $this->publishBlockerCode($readiness),
                $readiness['blocking_issues'][0],
                PilotRun::STEP_PUBLISH,
                PilotRun::STATE_PUBLISH_PENDING,
                array_values(array_unique($readiness['next_steps'] ?? [
                    'Resolve release readiness blockers before publishing.',
                ])),
                $user,
                [
                    'publish' => [
                        'readiness' => $readiness,
                    ],
                ]
            );
        }

        try {
            $published = $this->feedReleaseService->publish(
                $run->feedProfile->fresh(),
                $generation->fresh(),
                false,
                'Pilot publish execution',
                $user
            );
        } catch (Throwable $exception) {
            return $this->failedResult(
                $run,
                'publish_failed',
                $exception,
                PilotRun::STEP_PUBLISH,
                PilotRun::STATE_PUBLISH_PENDING,
                $user
            );
        }

        $latestSmokeCheck = $published->smokeChecks()->latest('checked_at')->first();
        $latestFirstPull = $run->feedProfile->fresh()->firstPullVerifications()
            ->where('feed_generation_id', $published->id)
            ->latest('verified_at')
            ->first();

        $run = $this->mergeRunReferences($run, [
            'published_generation_id' => $published->id,
        ], [
            'sections' => [
                'publish' => [
                    'generation_id' => $published->id,
                    'published_at' => $published->published_at?->toIso8601String(),
                    'published_path' => $published->published_path,
                    'checksum' => $published->checksum,
                    'publish_guard' => $published->meta['publish_guard'] ?? null,
                    'auto_smoke_check_id' => $latestSmokeCheck?->id,
                    'auto_first_pull_id' => $latestFirstPull?->id,
                    'hypercare_window_id' => $run->feedProfile->fresh()->currentHypercareWindow?->id,
                ],
            ],
        ]);
        $run = $this->transition(
            $run,
            PilotRun::STATE_PUBLISHED,
            $user,
            PilotRun::STEP_PUBLISH,
            'Published',
            'Pilot candidate has been published to the production feed URL.',
            PilotRunEvent::STATUS_OK
        );

        return [
            'run' => $run,
            'progressed' => true,
            'step' => PilotRun::STEP_PUBLISH,
        ];
    }

    /**
     * @return array{run:PilotRun,progressed:bool,step:string}
     */
    private function executeReleaseVerification(PilotRun $run, ?User $user): array
    {
        $published = $run->publishedGeneration ?: $run->feedProfile->publishedGeneration;

        if (! $published instanceof FeedGeneration) {
            return $this->blockedResult(
                $run,
                'publish_missing',
                'A published generation is required before first-pull verification.',
                PilotRun::STEP_RELEASE_VERIFICATION,
                PilotRun::STATE_PUBLISHED,
                ['Publish the approved candidate and resume the pilot run.'],
                $user
            );
        }

        try {
            $verification = $this->firstPullVerificationService->run(
                $run->feedProfile->fresh(),
                $published->fresh(),
                FeedGenerationSmokeCheck::TRIGGER_COMMAND,
                $user,
                'Pilot release verification'
            );
        } catch (Throwable $exception) {
            return $this->failedResult(
                $run,
                'release_verification_failed',
                $exception,
                PilotRun::STEP_RELEASE_VERIFICATION,
                PilotRun::STATE_PUBLISHED,
                $user
            );
        }

        $published = $published->fresh();
        $smokeCheck = $published->smokeChecks()->latest('checked_at')->first();
        $run = $this->mergeSummary($run, [
            'sections' => [
                'smoke_check' => $smokeCheck?->toArray(),
                'first_pull_verification' => $verification->toArray(),
            ],
        ]);

        if ($smokeCheck?->status === FeedGenerationSmokeCheck::STATUS_FAILED) {
            return $this->blockedResult(
                $run,
                'smoke_failure',
                $smokeCheck->errors[0] ?? 'Smoke check failed after publish.',
                PilotRun::STEP_RELEASE_VERIFICATION,
                PilotRun::STATE_PUBLISHED,
                [
                    'Review the published feed and smoke check errors.',
                    'Use rollback if the merchant-facing feed is unsafe.',
                    'Resume the pilot run after smoke checks pass.',
                ],
                $user,
                [
                    'verification' => [
                        'smoke_check_id' => $smokeCheck->id,
                    ],
                ]
            );
        }

        if ($verification->status === 'failed') {
            return $this->blockedResult(
                $run,
                'first_pull_failure',
                $verification->errors[0] ?? 'First-pull verification failed after publish.',
                PilotRun::STEP_RELEASE_VERIFICATION,
                PilotRun::STATE_PUBLISHED,
                [
                    'Inspect the published feed response and first-pull errors.',
                    'Use rollback if the live generation is unsafe.',
                    'Resume the pilot run after first-pull verification passes.',
                ],
                $user,
                [
                    'verification' => [
                        'first_pull_verification_id' => $verification->id,
                    ],
                ]
            );
        }

        $run = $this->transition(
            $run,
            PilotRun::STATE_FIRST_PULL_VERIFIED,
            $user,
            PilotRun::STEP_RELEASE_VERIFICATION,
            'First-pull verified',
            'Production smoke and first-pull verification passed.',
            $verification->status === 'warning' ? PilotRunEvent::STATUS_WARNING : PilotRunEvent::STATUS_OK
        );

        return [
            'run' => $run,
            'progressed' => true,
            'step' => PilotRun::STEP_RELEASE_VERIFICATION,
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{run:PilotRun,progressed:bool,step:string}
     */
    private function executeFeedbackReview(PilotRun $run, array $options, ?User $user): array
    {
        $published = $run->publishedGeneration ?: $run->feedProfile->publishedGeneration;

        if (! $published instanceof FeedGeneration) {
            return [
                'run' => $run,
                'progressed' => false,
                'step' => PilotRun::STEP_FEEDBACK,
            ];
        }

        $imports = [];

        if ($options['with_feedback_fixtures'] ?? false) {
            foreach (['csv', 'json'] as $format) {
                $result = $this->feedbackImportService->importContent(
                    $run->feedProfile,
                    $format,
                    $this->fixtureLibrary->feedbackContent($format),
                    $this->fixtureLibrary->feedbackFilename($format),
                    false,
                    $user,
                    $published
                );

                if (isset($result['import'])) {
                    $imports[] = $result['import']->id;
                }
            }

            foreach ((array) data_get($this->fixtureLibrary->expectedJson('feedback_remediation_plan'), 'resolutions', []) as $resolution) {
                $records = FeedbackRecord::query()
                    ->where('feed_profile_id', $run->feed_profile_id)
                    ->where('offer_id', (string) ($resolution['offer_id'] ?? ''))
                    ->orderBy('id')
                    ->get();

                foreach ($records as $record) {
                    $this->feedbackWorkbenchService->updateResolution(
                        $record,
                        (string) ($resolution['resolution_status'] ?? FeedbackRecord::RESOLUTION_FIXED),
                        (string) ($resolution['note'] ?? 'Pilot fixture remediation'),
                        $user
                    );
                }
            }
        }

        $feedback = $this->feedbackSlaService->summarize($run->feedProfile, $run->feedProfile->currentHypercareWindow);
        $run = $this->mergeSummary($run, [
            'sections' => [
                'feedback' => [
                    'imports_total' => $feedback['imports_total'] ?? 0,
                    'pending_backlog' => $feedback['pending_backlog'] ?? 0,
                    'rejected_total' => $feedback['rejected_total'] ?? 0,
                    'warning_total' => $feedback['warning_total'] ?? 0,
                    'grouped_reasons' => $feedback['grouped_reasons'] ?? [],
                    'fixture_import_ids' => $imports,
                ],
                'remediation' => [
                    'fixed' => $feedback['fixed'] ?? 0,
                    'wont_fix' => $feedback['wont_fix'] ?? 0,
                    'excluded' => $feedback['excluded'] ?? 0,
                    'in_progress' => $feedback['in_progress'] ?? 0,
                ],
            ],
        ]);

        if ($run->state !== PilotRun::STATE_FEEDBACK_REVIEW_ACTIVE) {
            $run = $this->transition(
                $run,
                PilotRun::STATE_FEEDBACK_REVIEW_ACTIVE,
                $user,
                PilotRun::STEP_FEEDBACK,
                'Feedback review active',
                'Pilot run moved into feedback review and remediation.',
                PilotRunEvent::STATUS_INFO
            );

            return [
                'run' => $run,
                'progressed' => true,
                'step' => PilotRun::STEP_FEEDBACK,
            ];
        }

        return [
            'run' => $this->refreshOperationalState($run),
            'progressed' => false,
            'step' => PilotRun::STEP_FEEDBACK,
        ];
    }

    /**
     * @return array{run:PilotRun,progressed:bool,step:string}
     */
    private function executeHypercareActivation(PilotRun $run, ?User $user): array
    {
        $feedback = $this->feedbackSlaService->summarize($run->feedProfile, $run->feedProfile->currentHypercareWindow);

        if (($feedback['imports_total'] ?? 0) === 0 || ($feedback['pending_backlog'] ?? 0) > 0) {
            return [
                'run' => $this->refreshOperationalState($run),
                'progressed' => false,
                'step' => PilotRun::STEP_HYPERCARE,
            ];
        }

        $window = $this->feedHypercareService->current($run->feedProfile);

        if (! $window && $run->publishedGeneration instanceof FeedGeneration) {
            $window = $this->feedHypercareService->ensureActiveAfterPublish(
                $run->feedProfile->fresh(['publishedGeneration', 'latestGeneration']),
                $run->publishedGeneration,
                $user,
                'Pilot hypercare activation'
            );
        }

        $run = $this->mergeMeta($run, [
            'hypercare' => [
                'window_id' => $window?->id,
            ],
        ]);
        $run = $this->mergeSummary($run, [
            'sections' => [
                'hypercare' => [
                    'window_id' => $window?->id,
                    'status' => $window?->status,
                    'started_at' => $window?->started_at?->toIso8601String(),
                    'planned_end_at' => $window?->planned_end_at?->toIso8601String(),
                ],
            ],
        ]);
        $run = $this->transition(
            $run,
            PilotRun::STATE_HYPERCARE_ACTIVE,
            $user,
            PilotRun::STEP_HYPERCARE,
            'Hypercare active',
            'Feedback backlog is clear and hypercare monitoring remains active.',
            PilotRunEvent::STATUS_OK
        );

        return [
            'run' => $run,
            'progressed' => true,
            'step' => PilotRun::STEP_HYPERCARE,
        ];
    }

    /**
     * @return array{run:PilotRun,progressed:bool,step:string}
     */
    private function executeCloseout(PilotRun $run, ?User $user): array
    {
        $window = $this->feedHypercareService->current($run->feedProfile);

        if ($window === null) {
            $run = $this->transition(
                $run,
                PilotRun::STATE_COMPLETED,
                $user,
                PilotRun::STEP_CLOSEOUT,
                'Pilot completed',
                'Hypercare was already closed and the pilot run is complete.',
                PilotRunEvent::STATUS_OK
            );

            $this->notify($run, 'feed.pilot_completed', 'Pilot completed', 'Pilot execution completed successfully.', 'info');

            return [
                'run' => $run,
                'progressed' => true,
                'step' => PilotRun::STEP_CLOSEOUT,
            ];
        }

        try {
            $result = $this->feedHypercareService->close($window, 'Pilot closeout', $user);
        } catch (Throwable $exception) {
            return $this->blockedResult(
                $run,
                'hypercare_closeout_blocked',
                $exception->getMessage(),
                PilotRun::STEP_CLOSEOUT,
                PilotRun::STATE_HYPERCARE_ACTIVE,
                [
                    'Resolve all critical incidents before hypercare closeout.',
                    'Resume the pilot run after hypercare blockers are cleared.',
                ],
                $user
            );
        }

        $stability = $this->feedStabilityService->evaluate($run->feedProfile, $result['window']);
        $run = $this->mergeSummary($run, [
            'sections' => [
                'hypercare' => array_merge((array) data_get($run->summary, 'sections.hypercare', []), [
                    'closed_at' => $result['window']->actual_end_at?->toIso8601String(),
                    'closeout_report' => $result['report'],
                    'stability' => $stability,
                ]),
            ],
        ]);
        $run = $this->transition(
            $run,
            PilotRun::STATE_COMPLETED,
            $user,
            PilotRun::STEP_CLOSEOUT,
            'Pilot completed',
            'Pilot hypercare closeout finished and evidence is ready.',
            PilotRunEvent::STATUS_OK
        );

        $this->notify($run, 'feed.pilot_completed', 'Pilot completed', 'Pilot execution completed successfully.', 'info');

        return [
            'run' => $run,
            'progressed' => true,
            'step' => PilotRun::STEP_CLOSEOUT,
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function transition(
        PilotRun $run,
        string $state,
        ?User $user,
        ?string $step,
        string $title,
        ?string $message,
        string $status,
        array $meta = [],
        ?string $blockingReason = null
    ): PilotRun {
        $fromState = $run->state;
        $run->forceFill([
            'state' => $state,
            'current_step' => $step,
            'blocking_reason' => $blockingReason,
            'finished_at' => in_array($state, PilotRun::terminalStates(), true) ? now() : null,
        ])->save();

        $this->recordEvent(
            $run->fresh($this->relations()),
            PilotRunEvent::TYPE_TRANSITION,
            $status,
            $title,
            $message,
            $user,
            $step,
            $fromState,
            $state,
            $meta,
            $blockingReason
        );

        return $this->refreshOperationalState($run->fresh($this->relations()));
    }

    /**
     * @param  array<string, mixed>  $summaryChanges
     * @param  array<string, mixed>  $metaChanges
     */
    private function mergeRunReferences(PilotRun $run, array $referenceChanges, array $summaryChanges = [], array $metaChanges = []): PilotRun
    {
        $run->fill(array_filter($referenceChanges, static fn ($value) => $value !== null))->save();
        $run = $run->fresh($this->relations());

        if ($summaryChanges !== []) {
            $run = $this->mergeSummary($run, $summaryChanges);
        }

        if ($metaChanges !== []) {
            $run = $this->mergeMeta($run, $metaChanges);
        }

        return $run;
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function mergeSummary(PilotRun $run, array $changes): PilotRun
    {
        $run->forceFill([
            'summary' => array_replace_recursive($run->summary ?? [], $changes),
        ])->save();

        return $run->fresh($this->relations());
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function mergeMeta(PilotRun $run, array $changes): PilotRun
    {
        $run->forceFill([
            'meta' => array_replace_recursive($run->meta ?? [], $changes),
        ])->save();

        return $run->fresh($this->relations());
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function normalizeOptions(array $options = [], ?PilotRun $run = null): array
    {
        return array_replace_recursive((array) data_get($run?->meta, 'options', []), [
            'with_sync' => (bool) ($options['with_sync'] ?? false),
            'with_build' => (bool) ($options['with_build'] ?? false),
            'with_publish' => (bool) ($options['with_publish'] ?? false),
            'with_feedback_fixtures' => (bool) ($options['with_feedback_fixtures'] ?? false),
            'promotion_strategy' => (string) ($options['promotion_strategy'] ?? PromotionRun::STRATEGY_SAFE_MERGE),
            'note' => $options['note'] ?? null,
        ], $options);
    }

    private function assignOwner(PilotRun $run, ?User $user): PilotRun
    {
        if ($user !== null && $run->owner_user_id === null) {
            $run->forceFill(['owner_user_id' => $user->id])->save();
        }

        return $run;
    }

    /**
     * @param  list<string>  $nextSteps
     * @param  array<string, mixed>  $meta
     * @return array{run:PilotRun,progressed:bool,step:string}
     */
    private function blockedResult(
        PilotRun $run,
        string $code,
        string $message,
        string $step,
        string $retryState,
        array $nextSteps,
        ?User $user,
        array $meta = []
    ): array {
        $run = $this->mergeSummary($run, [
            'blocker' => [
                'code' => $code,
                'message' => $message,
                'next_steps' => $nextSteps,
            ],
            'resume' => [
                'allowed' => true,
                'retry_state' => $retryState,
                'step' => $step,
                'safe_retry_steps' => [$step],
                'requires_reset_for' => [],
            ],
        ]);
        $run = $this->mergeMeta($run, $meta);
        $run = $this->transition(
            $run,
            PilotRun::STATE_BLOCKED,
            $user,
            $step,
            'Pilot run blocked',
            $message,
            PilotRunEvent::STATUS_BLOCKED,
            [
                'code' => $code,
                'next_steps' => $nextSteps,
            ],
            $message
        );

        $this->notify($run, 'feed.pilot_blocked', 'Pilot run blocked', $message, 'warning');

        return [
            'run' => $run,
            'progressed' => true,
            'step' => $step,
        ];
    }

    /**
     * @param  Throwable|string  $error
     * @param  array<string, mixed>  $meta
     * @return array{run:PilotRun,progressed:bool,step:string}
     */
    private function failedResult(
        PilotRun $run,
        string $code,
        Throwable|string $error,
        string $step,
        string $retryState,
        ?User $user,
        array $meta = []
    ): array {
        $message = $error instanceof Throwable ? $error->getMessage() : $error;
        $run = $this->mergeSummary($run, [
            'blocker' => [
                'code' => $code,
                'message' => $message,
                'next_steps' => [
                    'Inspect the execution log and fix the failing system step.',
                    'Resume the pilot run after the failure cause is removed.',
                ],
            ],
            'resume' => [
                'allowed' => true,
                'retry_state' => $retryState,
                'step' => $step,
                'safe_retry_steps' => [$step],
                'requires_reset_for' => [],
            ],
        ]);
        $run = $this->mergeMeta($run, array_replace_recursive($meta, [
            'failure' => [
                'code' => $code,
                'message' => $message,
                'exception' => $error instanceof Throwable ? $error::class : null,
            ],
        ]));
        $run = $this->transition(
            $run,
            PilotRun::STATE_FAILED,
            $user,
            $step,
            'Pilot run failed',
            $message,
            PilotRunEvent::STATUS_FAILED,
            [
                'code' => $code,
            ],
            $message
        );

        $this->notify($run, 'feed.pilot_failed', 'Pilot run failed', $message, 'error');

        return [
            'run' => $run,
            'progressed' => true,
            'step' => $step,
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function recordEvent(
        PilotRun $run,
        string $type,
        string $status,
        string $title,
        ?string $message,
        ?User $user = null,
        ?string $step = null,
        ?string $fromState = null,
        ?string $toState = null,
        array $meta = [],
        ?string $blockingReason = null
    ): PilotRunEvent {
        return PilotRunEvent::create([
            'pilot_run_id' => $run->id,
            'user_id' => $user?->id,
            'event_type' => $type,
            'step' => $step,
            'from_state' => $fromState,
            'to_state' => $toState,
            'status' => $status,
            'title' => $title,
            'message' => $message,
            'blocking_reason' => $blockingReason,
            'meta' => $meta,
            'occurred_at' => now(),
        ]);
    }

    private function resolveSourceSnapshot(FeedProfile $feedProfile, ?User $user = null): ?PromotionSnapshot
    {
        $snapshot = PromotionSnapshot::query()
            ->where('shop_id', $feedProfile->shop_id)
            ->where(function ($query) use ($feedProfile): void {
                $query->where('environment_class', 'staging')
                    ->orWhere('feed_profile_id', $feedProfile->id);
            })
            ->latest('generated_at')
            ->latest('id')
            ->first();

        if ($snapshot instanceof PromotionSnapshot) {
            return $snapshot;
        }

        if ($this->environmentContextService->summary()['is_production']) {
            return null;
        }

        return $this->promotionService->generateSnapshot(
            $feedProfile->fresh(['sourceConnection', 'shop']),
            $user,
            'staging',
            'Staging',
            $feedProfile->code.' pilot source snapshot'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function resumeRules(PilotRun $run): array
    {
        return match ($run->state) {
            PilotRun::STATE_BLOCKED, PilotRun::STATE_FAILED => [
                'allowed' => true,
                'retry_state' => data_get($run->summary, 'resume.retry_state'),
                'step' => data_get($run->summary, 'resume.step', $run->current_step),
                'safe_retry_steps' => (array) data_get($run->summary, 'resume.safe_retry_steps', [$run->current_step]),
                'requires_reset_for' => (array) data_get($run->summary, 'resume.requires_reset_for', []),
            ],
            default => [
                'allowed' => false,
                'retry_state' => null,
                'step' => $run->current_step,
                'safe_retry_steps' => [],
                'requires_reset_for' => [],
            ],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function abortRules(PilotRun $run): array
    {
        return [
            'allowed' => ! $run->isTerminal(),
            'evidence_preserved' => true,
            'effects' => [
                'Pilot state history remains available.',
                'Evidence pack can still be generated.',
                'Abort does not automatically roll back published configuration.',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $readiness
     */
    private function publishBlockerCode(array $readiness): string
    {
        if (! ($readiness['signoff']['allowed'] ?? true)) {
            return 'signoff_missing';
        }

        if (! ($readiness['publish_window']['allowed_now'] ?? true)) {
            return 'publish_window_blocked';
        }

        if (! ($readiness['checks']['critical_conformance']['ok'] ?? true)) {
            return 'critical_conformance_errors';
        }

        if (in_array(($readiness['promotion']['status'] ?? null), ['promotion_needed', 'incompatible'], true)) {
            return 'promotion_drift';
        }

        if (($readiness['promotion']['secret_rebind_pending'] ?? false) === true) {
            return 'secret_rebind_missing';
        }

        return 'release_readiness_blocked';
    }

    private function notify(PilotRun $run, string $event, string $title, string $message, string $severity): void
    {
        $this->notificationService->notifyFeedProfileAdmins(
            $run->feedProfile,
            $event,
            $title,
            $message,
            [
                'pilot_run_id' => $run->id,
                'state' => $run->state,
                'current_step' => $run->current_step,
            ],
            $severity,
            $run->publishedGeneration ?? $run->candidateGeneration
        );
    }

    /**
     * @return list<string>
     */
    private function relations(): array
    {
        return [
            'feedProfile.shop',
            'feedProfile.sourceConnection.latestImport',
            'feedProfile.latestGeneration',
            'feedProfile.publishedGeneration',
            'feedProfile.currentHypercareWindow',
            'sourceConnection.latestImport',
            'sourceSnapshot',
            'candidateGeneration',
            'publishedGeneration',
            'initiatedBy',
            'owner',
            'events.user',
        ];
    }
}
