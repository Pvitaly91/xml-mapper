<?php

namespace App\Services\Feeds;

use App\Contracts\Feeds\FeedBuildServiceInterface;
use App\Models\FeedGeneration;
use App\Models\FeedGenerationPreviewLink;
use App\Models\FeedGenerationSignoff;
use App\Models\FeedProfile;
use App\Models\FeedProfileCutover;
use App\Models\OpsRun;
use App\Models\User;
use App\Services\Ops\EnvironmentContextService;
use App\Services\Ops\OpsRunService;
use App\Services\Ops\ProductionPreflightService;
use App\Services\Promotion\PromotionStatusService;
use App\Services\Source\SourceConnectionTestService;
use App\Services\Source\SourceSyncWorkflowService;
use RuntimeException;
use Throwable;

class FeedRehearsalService
{
    public function __construct(
        private readonly EnvironmentContextService $environmentContextService,
        private readonly ProductionPreflightService $preflightService,
        private readonly SourceConnectionTestService $connectionTestService,
        private readonly SourceSyncWorkflowService $sourceSyncWorkflowService,
        private readonly FeedPilotReadinessService $pilotReadinessService,
        private readonly FeedBuildServiceInterface $feedBuildService,
        private readonly FeedReleaseService $releaseService,
        private readonly FeedPreviewLinkService $previewLinkService,
        private readonly FeedQaBundleService $qaBundleService,
        private readonly FeedSignoffService $signoffService,
        private readonly FeedSmokeCheckService $smokeCheckService,
        private readonly FeedFirstPullVerificationService $firstPullVerificationService,
        private readonly FeedReleaseAuditService $auditService,
        private readonly PilotNotificationService $notificationService,
        private readonly OpsRunService $opsRunService,
        private readonly PromotionStatusService $promotionStatusService,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function run(FeedProfile $feedProfile, array $options = [], ?User $user = null): array
    {
        $environment = $this->environmentContextService->summary();

        if ($environment['is_production'] && ! config('feed_mediator.rehearsal.allow_on_production', false)) {
            throw new RuntimeException('Launch rehearsal is blocked on production. Use staging or enable the explicit rehearsal override.');
        }

        $run = $this->opsRunService->start(OpsRun::TYPE_REHEARSAL, $feedProfile->shop, $feedProfile, $user, [
            'options' => $options,
            'environment' => $environment,
        ]);
        $steps = [];
        $warnings = [];
        $blocking = [];
        $generation = $feedProfile->latestGeneration;
        $canaryPreviewLink = null;
        $rollbackPreviewLink = null;
        $qaBundle = null;
        $rehearsalSmoke = null;
        $rehearsalVerification = null;
        $rollbackSmoke = null;

        try {
            $preflight = $this->preflightService->run($user, $environment['is_staging'] ? 'staging' : 'local');
            $steps[] = $this->step('preflight_staging', $preflight['status'], $preflight['blocking_issues'], $preflight['warnings']);
            $blocking = array_merge($blocking, $preflight['blocking_issues']);
            $warnings = array_merge($warnings, $preflight['warnings']);

            $connection = $feedProfile->sourceConnection()->firstOrFail();
            $connectionTest = $this->connectionTestService->test($connection);
            $steps[] = $this->step('source_test_connection', $connectionTest->status, $connectionTest->status === 'ok' ? [] : [$connectionTest->message]);

            if ($connectionTest->status !== 'ok') {
                $blocking[] = $connectionTest->message;
            }

            if (($options['with_sync'] ?? false) === true) {
                $import = $this->sourceSyncWorkflowService->run($connection, false);
                $steps[] = $this->step('initial_sync', $import->status, $import->status === 'failed' ? [$import->error_message ?: 'Source sync failed.'] : []);

                if ($import->status === 'failed') {
                    $blocking[] = $import->error_message ?: 'Source sync failed.';
                }
            }

            $pilot = $this->pilotReadinessService->summarize($feedProfile->fresh());
            $steps[] = $this->step(
                'unresolved_mapping_summary',
                ($pilot['mappings_complete']['ok'] ?? false) ? 'ok' : 'warning',
                [],
                ($pilot['mappings_complete']['ok'] ?? false) ? [] : ['Mappings are not fully reconciled yet.']
            );

            if (($options['with_build'] ?? false) === true || ! $generation instanceof FeedGeneration) {
                $generation = $this->feedBuildService->build($feedProfile->fresh());
            }

            if ($generation instanceof FeedGeneration) {
                $generation = $this->releaseService->markCandidate($generation->fresh(), $user, 'Staging rehearsal candidate.');
                $steps[] = $this->step('build_candidate', 'ok');
            } else {
                $blocking[] = 'No generation is available for rehearsal candidate.';
                $steps[] = $this->step('build_candidate', 'failed', ['No generation is available for rehearsal candidate.']);
            }

            if ($generation instanceof FeedGeneration) {
                $qaBundle = $this->qaBundleService->generate($generation, $user, 'Staging rehearsal QA bundle.');
                $steps[] = $this->step('generate_qa_bundle', 'ok');
                $signoff = $this->signoffService->current($generation);

                if (! $signoff instanceof FeedGenerationSignoff) {
                    $signoff = $this->signoffService->record(
                        $generation,
                        FeedGenerationSignoff::STATUS_INTERNAL_APPROVED,
                        $user,
                        $user?->name,
                        'Recorded during staging rehearsal.',
                        'Staging rehearsal sign-off.'
                    );
                }

                $steps[] = $this->step('record_signoff', 'ok', [], [], [
                    'status' => $signoff->status,
                ]);

                if (($options['with_preview'] ?? true) === true) {
                    $canaryPreviewLink = $this->previewLinkService->create(
                        $generation,
                        (int) config('feed_mediator.rehearsal.preview_ttl_minutes', 240),
                        $user,
                        'Staging canary preview artifact.',
                        ['target' => 'canary']
                    );
                    $steps[] = $this->step('generate_preview_link', 'ok', [], [], [
                        'preview_link_id' => $canaryPreviewLink->id,
                        'url' => $this->previewLinkService->urlFor($canaryPreviewLink),
                    ]);
                }
            }

            if ($canaryPreviewLink instanceof FeedGenerationPreviewLink) {
                $this->auditService->record($feedProfile, $generation, 'rehearsal_canary_published', $user, 'Staging canary artifact prepared.', [
                    'preview_link_id' => $canaryPreviewLink->id,
                ]);
                $steps[] = $this->step('publish_rehearsal', 'ok');
            }

            if (($options['with_smoke'] ?? false) === true && $canaryPreviewLink instanceof FeedGenerationPreviewLink) {
                $rehearsalSmoke = $this->smokeCheckService->runCanary($canaryPreviewLink, 'manual', $user, 'Staging rehearsal smoke check.');
                $steps[] = $this->step('smoke_check', $rehearsalSmoke->status, $rehearsalSmoke->errors ?? [], $rehearsalSmoke->warnings ?? []);

                if ($rehearsalSmoke->status === 'failed') {
                    $blocking = array_merge($blocking, $rehearsalSmoke->errors ?? []);
                }

                $rehearsalVerification = $this->firstPullVerificationService->runCanary(
                    $canaryPreviewLink,
                    'manual',
                    $user,
                    'Staging rehearsal first-pull verification.'
                );
                $steps[] = $this->step('first_pull_verification', $rehearsalVerification->status, $rehearsalVerification->errors ?? [], $rehearsalVerification->warnings ?? []);

                if ($rehearsalVerification->status === 'failed') {
                    $blocking = array_merge($blocking, $rehearsalVerification->errors ?? []);
                }
            }

            if (($options['with_rollback_check'] ?? false) === true) {
                $rollbackTarget = $feedProfile->publishedGeneration;

                if ($rollbackTarget instanceof FeedGeneration) {
                    $rollbackPreviewLink = $this->previewLinkService->create(
                        $rollbackTarget,
                        (int) config('feed_mediator.rehearsal.preview_ttl_minutes', 240),
                        $user,
                        'Rollback rehearsal preview artifact.',
                        ['target' => 'rollback_rehearsal']
                    );
                    $rollbackSmoke = $this->smokeCheckService->runCanary($rollbackPreviewLink, 'manual', $user, 'Rollback rehearsal smoke check.');
                    $steps[] = $this->step('rollback_rehearsal', $rollbackSmoke->status, $rollbackSmoke->errors ?? [], $rollbackSmoke->warnings ?? [], [
                        'preview_link_id' => $rollbackPreviewLink->id,
                    ]);
                } else {
                    $warnings[] = 'Rollback rehearsal skipped because there is no published generation yet.';
                    $steps[] = $this->step('rollback_rehearsal', 'warning', [], ['Rollback rehearsal skipped because there is no published generation yet.']);
                }
            }
        } catch (Throwable $exception) {
            $blocking[] = $exception->getMessage();
            $steps[] = $this->step('unexpected_failure', 'failed', [$exception->getMessage()]);
        }

        $status = $blocking !== []
            ? 'failed'
            : ($warnings !== [] ? 'blocked' : 'passed');
        $summary = [
            'status' => $status,
            'current_step' => $steps === [] ? null : end($steps)['key'],
            'steps_total' => count($steps),
            'warnings_total' => count($warnings),
            'blocking_total' => count($blocking),
        ];
        $run = $this->opsRunService->finish(
            $run,
            $status === 'passed' ? OpsRun::STATUS_SUCCEEDED : ($status === 'blocked' ? OpsRun::STATUS_WARNING : OpsRun::STATUS_FAILED),
            $summary,
            [
                'steps' => $steps,
                'warnings' => array_values(array_unique($warnings)),
                'blocking_issues' => array_values(array_unique($blocking)),
                'generation_id' => $generation?->id,
                'preview_link_id' => $canaryPreviewLink?->id,
                'rollback_preview_link_id' => $rollbackPreviewLink?->id,
                'qa_bundle_path' => $qaBundle['path'] ?? null,
                'rehearsal_smoke_check_id' => $rehearsalSmoke?->id,
                'rehearsal_first_pull_verification_id' => $rehearsalVerification?->id,
                'rollback_smoke_check_id' => $rollbackSmoke?->id,
            ]
        );
        $this->auditService->record(
            $feedProfile,
            $generation,
            $status === 'passed' ? 'rehearsal_passed' : 'rehearsal_failed',
            $user,
            'Staging rehearsal finished.',
            [
                'ops_run_id' => $run->id,
                'status' => $status,
                'blocking_issues' => array_values(array_unique($blocking)),
                'warnings' => array_values(array_unique($warnings)),
            ]
        );
        $this->notificationService->notifyFeedProfileAdmins(
            $feedProfile,
            $status === 'passed' ? 'feed.rehearsal_passed' : 'feed.rehearsal_failed',
            $status === 'passed' ? 'Staging rehearsal passed' : 'Staging rehearsal requires attention',
            $status === 'passed'
                ? 'The staging rehearsal completed successfully.'
                : 'The staging rehearsal finished with blocking issues or warnings.',
            [
                'feed_profile_id' => $feedProfile->id,
                'generation_id' => $generation?->id,
                'ops_run_id' => $run->id,
                'blocking_issues' => array_values(array_unique($blocking)),
                'warnings' => array_values(array_unique($warnings)),
            ],
            $status === 'passed' ? 'info' : 'warning',
            $generation
        );

        return [
            'run' => $run,
            'generation' => $generation,
            'preview_link' => $canaryPreviewLink,
            'preview_url' => $canaryPreviewLink ? $this->previewLinkService->urlFor($canaryPreviewLink) : null,
            'rollback_preview_link' => $rollbackPreviewLink,
            'rollback_preview_url' => $rollbackPreviewLink ? $this->previewLinkService->urlFor($rollbackPreviewLink) : null,
            'qa_bundle' => $qaBundle,
            'steps' => $steps,
            'warnings' => array_values(array_unique($warnings)),
            'blocking_issues' => array_values(array_unique($blocking)),
            'status' => $status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function summarize(FeedProfile $feedProfile): array
    {
        $latest = $feedProfile->opsRuns()->where('type', OpsRun::TYPE_REHEARSAL)->latest('started_at')->first();
        $generationId = $latest?->meta['generation_id'] ?? null;
        $generation = $generationId ? $feedProfile->generations()->find($generationId) : $feedProfile->latestGeneration;
        $previewLinkId = $latest?->meta['preview_link_id'] ?? null;
        $rollbackPreviewLinkId = $latest?->meta['rollback_preview_link_id'] ?? null;
        $previewLink = $previewLinkId ? $generation?->previewLinks()->find($previewLinkId) : null;
        $rollbackPreviewLink = $rollbackPreviewLinkId ? FeedGenerationPreviewLink::query()->find($rollbackPreviewLinkId) : null;

        return [
            'latest' => $latest,
            'history' => $feedProfile->opsRuns()
                ->where('type', OpsRun::TYPE_REHEARSAL)
                ->latest('started_at')
                ->limit(10)
                ->get(),
            'generation' => $generation,
            'preview_link' => $previewLink,
            'preview_url' => $previewLink?->isActive() ? $this->previewLinkService->urlFor($previewLink) : null,
            'rollback_preview_link' => $rollbackPreviewLink,
            'rollback_preview_url' => $rollbackPreviewLink?->isActive() ? $this->previewLinkService->urlFor($rollbackPreviewLink) : null,
            'status' => $latest?->summary['status'] ?? 'not_started',
            'current_step' => $latest?->summary['current_step'] ?? null,
            'steps' => $latest?->meta['steps'] ?? [],
            'warnings' => $latest?->meta['warnings'] ?? [],
            'blocking_issues' => $latest?->meta['blocking_issues'] ?? [],
            'qa_bundle_path' => $latest?->meta['qa_bundle_path'] ?? null,
            'rehearsal_candidate' => $generation?->release_status === FeedGeneration::RELEASE_STATUS_CANDIDATE,
            'rehearsal_publish_result' => $previewLink instanceof FeedGenerationPreviewLink ? 'ready' : 'n/a',
            'rehearsal_smoke_result' => $generation?->smokeChecks()
                ->where('meta->target', 'canary')
                ->latest('checked_at')
                ->first(),
            'rehearsal_rollback_result' => $rollbackPreviewLink ? 'checked' : 'n/a',
            'promotion' => $this->promotionStatusService->summarize($feedProfile),
        ];
    }

    /**
     * @param  list<string>  $errors
     * @param  list<string>  $warnings
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function step(string $key, string $status, array $errors = [], array $warnings = [], array $meta = []): array
    {
        return [
            'key' => $key,
            'status' => match ($status) {
                'ok', 'succeeded', FeedProfileCutover::STATUS_CANDIDATE_READY => 'ok',
                'warning', 'blocked' => 'warning',
                'failed' => 'failed',
                default => $status,
            },
            'errors' => array_values(array_filter($errors)),
            'warnings' => array_values(array_filter($warnings)),
            'meta' => $meta,
        ];
    }
}
