<?php

namespace App\Services\Launch;

use App\Models\FeedFirstPullVerification;
use App\Models\FeedGeneration;
use App\Models\FeedGenerationSmokeCheck;
use App\Models\FeedbackRecord;
use App\Models\FeedProfile;
use App\Models\FeedReleaseEvent;
use App\Models\MerchantLaunch;
use App\Models\MerchantLaunchDefect;
use App\Models\MerchantLaunchObservation;
use App\Models\MerchantLaunchTuningAction;
use App\Models\OpsAlert;
use App\Models\OpsRun;
use App\Models\PilotRun;
use App\Models\PromotionRun;
use App\Models\User;
use App\Services\Feeds\FeedbackSlaService;
use App\Services\Feeds\FeedReleaseAuditService;
use App\Services\Feeds\FeedStabilityService;
use App\Services\Ops\EnvironmentContextService;
use App\Services\Ops\OpsAlertService;
use App\Services\Ops\OpsMaintenanceStatusService;
use Illuminate\Support\Collection;
use RuntimeException;

class MerchantLaunchService
{
    public function __construct(
        private readonly EnvironmentContextService $environmentContextService,
        private readonly FeedReleaseAuditService $auditService,
        private readonly FeedbackSlaService $feedbackSlaService,
        private readonly FeedStabilityService $feedStabilityService,
        private readonly OpsMaintenanceStatusService $opsMaintenanceStatusService,
        private readonly OpsAlertService $opsAlertService,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public function start(FeedProfile $feedProfile, array $options = [], ?User $user = null): MerchantLaunch
    {
        $feedProfile->loadMissing([
            'shop',
            'publishedGeneration',
            'latestGeneration',
            'currentHypercareWindow',
            'sourceConnection.latestImport',
        ]);

        $existing = $this->currentOpenLaunch($feedProfile);

        if ($existing instanceof MerchantLaunch) {
            return $this->refresh($existing);
        }

        $environment = $this->environmentContextService->summary();
        $pilotRun = $this->resolvePilotRun($feedProfile, $options['pilot_run_id'] ?? null);
        $promotionRun = $this->resolvePromotionRun($feedProfile, $options['promotion_run_id'] ?? null);
        $publishedGeneration = $feedProfile->publishedGeneration;
        $baseline = $this->baselineSeed($feedProfile, $pilotRun, $publishedGeneration);

        $feedbackOffsets = $this->feedbackOffsetSnapshot($feedProfile);

        $launch = MerchantLaunch::create([
            'shop_id' => $feedProfile->shop_id,
            'feed_profile_id' => $feedProfile->id,
            'pilot_run_id' => $pilotRun?->id,
            'promotion_run_id' => $promotionRun?->id,
            'published_generation_id' => $publishedGeneration?->id,
            'initiated_by_user_id' => $user?->id,
            'owner_user_id' => $user?->id,
            'environment_class' => $environment['class'],
            'environment_label' => $environment['label'],
            'state' => MerchantLaunch::STATE_PLANNED,
            'handover_state' => MerchantLaunch::HANDOVER_BLOCKED,
            'planned_start_at' => now(),
            'started_at' => now(),
            'actual_published_at' => $publishedGeneration?->published_at,
            'expected_ready_items' => $baseline['expected_ready_items'],
            'expected_published_count' => $baseline['expected_published_count'],
            'expected_first_pull_latency_ms' => $baseline['expected_first_pull_latency_ms'],
            'expected_feedback_total' => $baseline['expected_feedback_total'],
            'expected_rejection_total' => $baseline['expected_rejection_total'],
            'expected_sync_freshness_minutes' => $baseline['expected_sync_freshness_minutes'],
            'note' => $options['note'] ?? null,
            'summary' => [
                'execution' => [
                    'planned_at' => now()->toIso8601String(),
                    'state_label' => 'Planned',
                ],
                'baseline' => [
                    'seed' => $baseline,
                ],
                'handover' => [
                    'state' => MerchantLaunch::HANDOVER_BLOCKED,
                ],
            ],
            'meta' => [
                'references' => [
                    'pilot_run_id' => $pilotRun?->id,
                    'promotion_run_id' => $promotionRun?->id,
                    'published_generation_id' => $publishedGeneration?->id,
                ],
                'feedback_offsets' => $feedbackOffsets,
            ],
        ]);

        $this->audit(
            $launch,
            'live_launch_started',
            $user,
            $launch->note,
            [
                'pilot_run_id' => $pilotRun?->id,
                'promotion_run_id' => $promotionRun?->id,
                'published_generation_id' => $publishedGeneration?->id,
                'baseline' => $baseline,
            ]
        );

        return $this->refresh($launch);
    }

    public function refresh(MerchantLaunch $launch): MerchantLaunch
    {
        $launch = $launch->fresh($this->relations());
        $feedProfile = $launch->feedProfile;
        $feedProfile->loadMissing([
            'shop',
            'sourceConnection.latestImport',
            'publishedGeneration.smokeChecks',
            'latestGeneration',
            'currentHypercareWindow',
        ]);

        $publishedGeneration = $launch->publishedGeneration;

        if (! ($publishedGeneration instanceof FeedGeneration) && $feedProfile->publishedGeneration instanceof FeedGeneration) {
            $publishedGeneration = $feedProfile->publishedGeneration;
            $launch->published_generation_id = $publishedGeneration->id;
        }

        if ($launch->actual_published_at === null && $publishedGeneration?->published_at) {
            $launch->actual_published_at = $publishedGeneration->published_at;
        }

        $latestSmoke = $publishedGeneration?->smokeChecks()->latest('checked_at')->first();
        $latestFirstPull = $feedProfile->firstPullVerifications()
            ->when($launch->published_generation_id !== null, fn ($query) => $query->where('feed_generation_id', $launch->published_generation_id))
            ->latest('verified_at')
            ->first();
        $hypercare = $feedProfile->currentHypercareWindow ?: $feedProfile->hypercareWindows()->latest('id')->first();
        $feedback = $this->launchFeedbackSummary($launch, $feedProfile, $hypercare);
        $stability = $this->feedStabilityService->evaluate($feedProfile, $hypercare);
        $openAlerts = $this->opsAlertService->openAlertsForProfile($feedProfile, $hypercare);
        $criticalAlerts = $openAlerts->where('severity', OpsAlert::SEVERITY_CRITICAL)->values();
        $openDefects = $launch->defects()
            ->with(['feedItem.sourceProduct.sourceCategory', 'feedbackRecord', 'alert', 'observation', 'user'])
            ->latest('id')
            ->get()
            ->filter(fn (MerchantLaunchDefect $defect) => $defect->isOpen())
            ->values();
        $criticalDefects = $openDefects->filter(fn (MerchantLaunchDefect $defect) => $defect->isCritical())->values();
        $history = $this->history($launch);
        $rollbackEvent = $history->firstWhere('action', 'rolled_back');
        $publishFailed = $history->firstWhere('action', 'publish_failed');
        $deploy = $this->deploySummary($feedProfile);
        $baseline = $this->baselineSummary($launch, $feedProfile, $publishedGeneration, $latestSmoke, $latestFirstPull, $feedback);

        $this->syncDeviationAlerts($launch, $feedProfile, $publishedGeneration, $baseline);

        $checklist = $this->stabilizationChecklist(
            $launch,
            $publishedGeneration,
            $latestSmoke,
            $latestFirstPull,
            $feedback,
            $criticalAlerts,
            $criticalDefects,
            $baseline,
            $stability
        );
        $blockers = $this->blockers(
            $launch,
            $deploy,
            $publishedGeneration,
            $latestSmoke,
            $latestFirstPull,
            $feedback,
            $criticalAlerts,
            $criticalDefects,
            $baseline,
            $stability,
            $rollbackEvent !== null,
            $publishFailed !== null
        );
        $nextActions = $this->nextActions($launch, $blockers, $checklist, $criticalDefects, $criticalAlerts, $baseline);
        $handoverState = $launch->handover_state === MerchantLaunch::HANDOVER_DONE
            ? MerchantLaunch::HANDOVER_DONE
            : ($checklist['all_passed'] ? MerchantLaunch::HANDOVER_READY : MerchantLaunch::HANDOVER_BLOCKED);
        $state = $launch->state === MerchantLaunch::STATE_CLOSED
            ? MerchantLaunch::STATE_CLOSED
            : $this->resolveState(
                $launch,
                $deploy,
                $publishedGeneration,
                $latestSmoke,
                $latestFirstPull,
                $baseline,
                $criticalAlerts,
                $criticalDefects,
                $stability,
                $rollbackEvent !== null,
                $publishFailed !== null,
                $checklist,
                $handoverState
            );

        $summary = array_replace_recursive($launch->summary ?? [], [
            'execution' => [
                'state_label' => $this->stateLabel($state),
                'updated_at' => now()->toIso8601String(),
                'actual_published_at' => $launch->actual_published_at?->toIso8601String(),
                'actual_go_live_confirmed_at' => $launch->actual_go_live_confirmed_at?->toIso8601String(),
                'current_hypercare_status' => $hypercare?->status,
            ],
            'deploy' => $deploy,
            'baseline' => $baseline,
            'smoke' => $latestSmoke?->toArray(),
            'first_pull' => $latestFirstPull?->toArray(),
            'feedback' => $feedback,
            'stability' => $stability,
            'handover' => [
                'state' => $handoverState,
                'checklist' => $checklist,
            ],
            'blockers' => $blockers,
            'next_actions' => $nextActions,
            'observations' => [
                'total' => $launch->observations()->count(),
                'latest' => $launch->observations()->latest('observed_at')->first()?->toArray(),
            ],
            'defects' => [
                'open_total' => $openDefects->count(),
                'critical_open_total' => $criticalDefects->count(),
            ],
            'tuning' => [
                'total' => $launch->tuningActions()->count(),
                'latest' => $launch->tuningActions()->latest('applied_at')->first()?->toArray(),
            ],
        ]);

        $launch->forceFill([
            'state' => $state,
            'handover_state' => $handoverState,
            'summary' => $summary,
            'meta' => array_replace_recursive($launch->meta ?? [], [
                'references' => [
                    'published_generation_id' => $launch->published_generation_id,
                    'last_smoke_check_id' => $latestSmoke?->id,
                    'last_first_pull_id' => $latestFirstPull?->id,
                    'hypercare_window_id' => $hypercare?->id,
                ],
            ]),
        ])->save();

        return $launch->fresh($this->relations());
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(MerchantLaunch $launch): array
    {
        $launch = $this->refresh($launch);
        $feedProfile = $launch->feedProfile;
        $hypercare = $feedProfile->currentHypercareWindow ?: $feedProfile->hypercareWindows()->latest('id')->first();
        $observations = $launch->observations()->with(['user', 'generation', 'feedbackImport', 'feedItem', 'alert'])->latest('observed_at')->paginate(15, ['*'], 'obs')->withQueryString();
        $defects = $launch->defects()->with(['user', 'feedItem.sourceProduct', 'feedbackRecord', 'alert', 'observation'])->latest('opened_at')->paginate(15, ['*'], 'defects')->withQueryString();
        $history = $this->history($launch);

        return [
            'launch' => $launch,
            'feed_profile' => $feedProfile,
            'history' => $history,
            'baseline' => (array) data_get($launch->summary, 'baseline', []),
            'handover' => (array) data_get($launch->summary, 'handover', []),
            'blockers' => (array) data_get($launch->summary, 'blockers', []),
            'next_actions' => (array) data_get($launch->summary, 'next_actions', []),
            'observations' => $observations,
            'defects' => $defects,
            'open_alerts' => $this->opsAlertService->openAlertsForProfile($feedProfile, $hypercare),
            'feedback' => (array) data_get($launch->summary, 'feedback', []),
            'stability' => (array) data_get($launch->summary, 'stability', []),
            'tuning_actions' => $launch->tuningActions()->with('user')->latest('applied_at')->limit(20)->get(),
            'check' => $this->check($launch),
        ];
    }

    public function updateBaseline(MerchantLaunch $launch, array $payload, ?User $user = null): MerchantLaunch
    {
        $launch->forceFill([
            'expected_ready_items' => $payload['expected_ready_items'] ?? $launch->expected_ready_items,
            'expected_published_count' => $payload['expected_published_count'] ?? $launch->expected_published_count,
            'expected_first_pull_latency_ms' => $payload['expected_first_pull_latency_ms'] ?? $launch->expected_first_pull_latency_ms,
            'expected_feedback_total' => $payload['expected_feedback_total'] ?? $launch->expected_feedback_total,
            'expected_rejection_total' => $payload['expected_rejection_total'] ?? $launch->expected_rejection_total,
            'expected_sync_freshness_minutes' => $payload['expected_sync_freshness_minutes'] ?? $launch->expected_sync_freshness_minutes,
        ])->save();

        $this->audit($launch, 'live_launch_baseline_updated', $user, 'Launch baseline updated.', [
            'baseline' => [
                'expected_ready_items' => $launch->expected_ready_items,
                'expected_published_count' => $launch->expected_published_count,
                'expected_first_pull_latency_ms' => $launch->expected_first_pull_latency_ms,
                'expected_feedback_total' => $launch->expected_feedback_total,
                'expected_rejection_total' => $launch->expected_rejection_total,
                'expected_sync_freshness_minutes' => $launch->expected_sync_freshness_minutes,
            ],
        ]);

        return $this->refresh($launch);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function addObservation(MerchantLaunch $launch, array $payload, ?User $user = null): MerchantLaunchObservation
    {
        $launch = $this->refresh($launch);
        $observation = MerchantLaunchObservation::create([
            'merchant_launch_id' => $launch->id,
            'user_id' => $user?->id,
            'feed_generation_id' => $payload['feed_generation_id'] ?? $launch->published_generation_id,
            'feed_item_id' => $payload['feed_item_id'] ?? null,
            'feedback_import_id' => $payload['feedback_import_id'] ?? null,
            'ops_alert_id' => $payload['ops_alert_id'] ?? null,
            'type' => $payload['type'],
            'severity' => $payload['severity'] ?? MerchantLaunchObservation::SEVERITY_MEDIUM,
            'source' => $payload['source'] ?? 'operator',
            'note' => $payload['note'],
            'meta' => $payload['meta'] ?? [],
            'observed_at' => now(),
        ]);

        if (
            $launch->actual_go_live_confirmed_at === null
            && in_array($observation->type, [
                MerchantLaunchObservation::TYPE_MERCHANT_CONFIRMATION,
                MerchantLaunchObservation::TYPE_FIRST_PICKUP_CONFIRMED,
            ], true)
        ) {
            $launch->forceFill([
                'actual_go_live_confirmed_at' => $observation->observed_at,
            ])->save();
        }

        $this->audit($launch, 'live_launch_observation_added', $user, $observation->note, [
            'observation_id' => $observation->id,
            'type' => $observation->type,
            'severity' => $observation->severity,
            'source' => $observation->source,
        ]);

        $this->refresh($launch);

        return $observation->fresh(['user', 'generation', 'feedbackImport', 'feedItem', 'alert']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function addDefect(MerchantLaunch $launch, array $payload, ?User $user = null): MerchantLaunchDefect
    {
        $launch = $this->refresh($launch);
        $title = $payload['title'] ?? ucfirst(str_replace('_', ' ', (string) $payload['type']));
        $note = $payload['note'] ?? null;
        $defect = MerchantLaunchDefect::create([
            'merchant_launch_id' => $launch->id,
            'merchant_launch_observation_id' => $payload['merchant_launch_observation_id'] ?? null,
            'user_id' => $user?->id,
            'feed_generation_id' => $payload['feed_generation_id'] ?? $launch->published_generation_id,
            'feed_item_id' => $payload['feed_item_id'] ?? null,
            'feedback_record_id' => $payload['feedback_record_id'] ?? null,
            'ops_alert_id' => $payload['ops_alert_id'] ?? null,
            'type' => $payload['type'],
            'severity' => $payload['severity'] ?? MerchantLaunchDefect::SEVERITY_MEDIUM,
            'status' => $payload['status'] ?? MerchantLaunchDefect::STATUS_OPEN,
            'title' => $title,
            'note' => $note,
            'meta' => $payload['meta'] ?? [],
            'opened_at' => now(),
        ]);

        $this->audit($launch, 'live_launch_defect_opened', $user, $note ?: $title, [
            'defect_id' => $defect->id,
            'type' => $defect->type,
            'severity' => $defect->severity,
            'status' => $defect->status,
        ]);

        $this->refresh($launch);

        return $defect->fresh(['user', 'feedItem.sourceProduct', 'feedbackRecord', 'alert', 'observation']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function updateDefect(MerchantLaunchDefect $defect, array $payload, ?User $user = null): MerchantLaunchDefect
    {
        $defect->forceFill([
            'severity' => $payload['severity'] ?? $defect->severity,
            'status' => $payload['status'] ?? $defect->status,
            'resolution_note' => $payload['resolution_note'] ?? $defect->resolution_note,
            'resolved_at' => in_array($payload['status'] ?? $defect->status, [
                MerchantLaunchDefect::STATUS_RESOLVED,
                MerchantLaunchDefect::STATUS_WONT_FIX,
            ], true) ? now() : null,
        ])->save();

        $launch = $defect->launch;
        $this->audit($launch, 'live_launch_defect_updated', $user, $payload['resolution_note'] ?? $defect->title, [
            'defect_id' => $defect->id,
            'severity' => $defect->severity,
            'status' => $defect->status,
        ]);
        $this->refresh($launch);

        return $defect->fresh(['user', 'feedItem.sourceProduct', 'feedbackRecord', 'alert', 'observation']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function applyTuning(MerchantLaunch $launch, array $payload, ?User $user = null): MerchantLaunchTuningAction
    {
        $launch = $this->refresh($launch);
        $feedProfile = $launch->feedProfile->fresh();
        $settings = $feedProfile->exportSettings();
        $before = $settings;
        $type = (string) $payload['type'];
        $key = (string) ($payload['key'] ?? '');
        $value = $payload['value'] ?? null;

        if (blank($payload['reason'] ?? null)) {
            throw new RuntimeException('Tuning reason is required.');
        }

        match ($type) {
            MerchantLaunchTuningAction::TYPE_PUBLISH_GUARD => $this->applyPublishGuardTuning($settings, $key, $value),
            MerchantLaunchTuningAction::TYPE_EXCLUDED_CATEGORY => $settings['excluded_source_category_ids'] = $this->appendUniqueInt(
                (array) ($settings['excluded_source_category_ids'] ?? []),
                $value
            ),
            MerchantLaunchTuningAction::TYPE_EXCLUDED_VENDOR => $settings['excluded_vendors'] = $this->appendUniqueString(
                (array) ($settings['excluded_vendors'] ?? []),
                $value
            ),
            MerchantLaunchTuningAction::TYPE_MINIMUM_IMAGE_COUNT => $settings['override_minimum_pictures'] = max(1, (int) $value),
            MerchantLaunchTuningAction::TYPE_MINIMUM_PRICE => $settings['minimum_price_threshold'] = round((float) $value, 2),
            MerchantLaunchTuningAction::TYPE_FORCED_ATTRIBUTE_OVERRIDE => $settings['forced_attribute_overrides'] = $this->mergeOverride(
                (array) ($settings['forced_attribute_overrides'] ?? []),
                $key,
                $value
            ),
            MerchantLaunchTuningAction::TYPE_FORCED_VALUE_OVERRIDE => $settings['forced_value_overrides'] = $this->mergeOverride(
                (array) ($settings['forced_value_overrides'] ?? []),
                $key,
                $value
            ),
            default => throw new RuntimeException('Unsupported tuning type.'),
        };

        $feedProfile->update([
            'settings' => $settings,
        ]);

        $action = MerchantLaunchTuningAction::create([
            'merchant_launch_id' => $launch->id,
            'user_id' => $user?->id,
            'type' => $type,
            'mode' => $payload['mode'] ?? MerchantLaunchTuningAction::MODE_NORMAL,
            'reason' => (string) $payload['reason'],
            'summary' => [
                'key' => $key,
                'value' => $value,
                'before' => $before,
                'after' => $settings,
            ],
            'applied_at' => now(),
        ]);

        $this->audit($launch, 'live_launch_tuning_applied', $user, (string) $payload['reason'], [
            'tuning_action_id' => $action->id,
            'type' => $action->type,
            'mode' => $action->mode,
            'key' => $key,
            'value' => $value,
        ]);

        $this->refresh($launch);

        return $action->fresh(['user']);
    }

    public function handover(MerchantLaunch $launch, ?string $reason = null, ?User $user = null): MerchantLaunch
    {
        $launch = $this->refresh($launch);
        $check = $this->check($launch);

        if (! ($check['handover_ready'] ?? false)) {
            throw new RuntimeException('Launch handover is blocked: '.implode(' ', $check['critical_blockers'] ?? ['Resolve remaining blockers first.']));
        }

        $launch->forceFill([
            'handover_state' => MerchantLaunch::HANDOVER_DONE,
            'state' => MerchantLaunch::STATE_STABILIZED,
            'outcome' => 'stable_handover_completed',
            'summary' => array_replace_recursive($launch->summary ?? [], [
                'handover' => [
                    'state' => MerchantLaunch::HANDOVER_DONE,
                    'handed_over_at' => now()->toIso8601String(),
                    'reason' => $reason,
                ],
            ]),
        ])->save();

        $this->audit($launch, 'live_launch_handed_over', $user, $reason ?: 'Launch handed over to steady-state operations.', [
            'handover_state' => MerchantLaunch::HANDOVER_DONE,
        ]);

        return $this->refresh($launch);
    }

    public function close(MerchantLaunch $launch, string $reason, ?User $user = null): MerchantLaunch
    {
        if (blank($reason)) {
            throw new RuntimeException('Close reason is required.');
        }

        $launch = $this->refresh($launch);
        $check = $this->check($launch);

        if (($check['safe_to_close'] ?? false) !== true) {
            throw new RuntimeException('Launch cannot be closed safely: '.implode(' ', $check['critical_blockers'] ?? ['Resolve remaining blockers first.']));
        }

        $launch->forceFill([
            'state' => MerchantLaunch::STATE_CLOSED,
            'closed_at' => now(),
            'outcome' => $launch->outcome ?: 'launch_closed',
            'summary' => array_replace_recursive($launch->summary ?? [], [
                'closeout' => [
                    'closed_at' => now()->toIso8601String(),
                    'reason' => $reason,
                ],
            ]),
        ])->save();

        $this->audit($launch, 'live_launch_closed', $user, $reason, [
            'handover_state' => $launch->handover_state,
            'state' => MerchantLaunch::STATE_CLOSED,
        ]);

        return $this->refresh($launch);
    }

    /**
     * @return array<string, mixed>
     */
    public function check(MerchantLaunch $launch): array
    {
        $launch = $this->refresh($launch);
        $criticalBlockers = collect((array) data_get($launch->summary, 'blockers', []))
            ->where('severity', 'critical')
            ->pluck('message')
            ->values()
            ->all();
        $checklistPassed = (bool) data_get($launch->summary, 'handover.checklist.all_passed', false);

        return [
            'launch_id' => $launch->id,
            'feed_profile_id' => $launch->feed_profile_id,
            'state' => $launch->state,
            'handover_state' => $launch->handover_state,
            'critical_blockers' => $criticalBlockers,
            'next_actions' => (array) data_get($launch->summary, 'next_actions', []),
            'baseline_deviations' => collect((array) data_get($launch->summary, 'baseline.metrics', []))
                ->filter(fn (array $metric) => ($metric['status'] ?? 'pending') !== 'in_range')
                ->values()
                ->all(),
            'open_incidents' => $launch->feedProfile->opsAlerts()
                ->whereNotIn('state', [OpsAlert::STATE_RESOLVED, OpsAlert::STATE_FALSE_POSITIVE])
                ->count(),
            'open_defects' => $launch->defects()->get()->filter(fn (MerchantLaunchDefect $defect) => $defect->isOpen())->count(),
            'handover_ready' => $checklistPassed && $criticalBlockers === [],
            'safe_to_close' => $launch->handover_state === MerchantLaunch::HANDOVER_DONE
                && collect((array) data_get($launch->summary, 'blockers', []))->where('severity', 'critical')->isEmpty(),
        ];
    }

    public function currentOpenLaunch(FeedProfile $feedProfile): ?MerchantLaunch
    {
        return MerchantLaunch::query()
            ->where('feed_profile_id', $feedProfile->id)
            ->where('state', '!=', MerchantLaunch::STATE_CLOSED)
            ->latest('id')
            ->first();
    }

    /**
     * @return Collection<int, FeedReleaseEvent>
     */
    public function history(MerchantLaunch $launch): Collection
    {
        $events = $launch->feedProfile->releaseEvents()
            ->with(['user', 'feedGeneration'])
            ->where('occurred_at', '>=', $launch->started_at ?? $launch->created_at)
            ->when($launch->closed_at !== null, fn ($query) => $query->where('occurred_at', '<=', $launch->closed_at))
            ->latest('occurred_at')
            ->limit(150)
            ->get();

        $trackedActions = [
            'published',
            'force_published',
            'publish_failed',
            'rolled_back',
            'first_pull_verified',
            'first_pull_verification_failed',
            'smoke_check_rerun',
            'feedback_imported',
            'feedback_resolution_updated',
        ];

        return $events
            ->filter(function (FeedReleaseEvent $event) use ($launch, $trackedActions): bool {
                return (int) data_get($event->meta, 'merchant_launch_id') === $launch->id
                    || str_starts_with($event->action, 'live_launch_')
                    || in_array($event->action, $trackedActions, true);
            })
            ->values();
    }

    /**
     * @return array<int, string>
     */
    private function relations(): array
    {
        return [
            'feedProfile.shop',
            'feedProfile.sourceConnection.latestImport',
            'feedProfile.publishedGeneration',
            'feedProfile.latestGeneration',
            'feedProfile.currentHypercareWindow',
            'pilotRun',
            'promotionRun',
            'publishedGeneration',
            'initiatedBy',
            'owner',
        ];
    }

    private function resolvePilotRun(FeedProfile $feedProfile, mixed $pilotRunId): ?PilotRun
    {
        if ($pilotRunId !== null) {
            return PilotRun::query()
                ->where('feed_profile_id', $feedProfile->id)
                ->findOrFail((int) $pilotRunId);
        }

        return $feedProfile->pilotRuns()
            ->where('state', PilotRun::STATE_COMPLETED)
            ->latest('id')
            ->first();
    }

    private function resolvePromotionRun(FeedProfile $feedProfile, mixed $promotionRunId): ?PromotionRun
    {
        if ($promotionRunId !== null) {
            return PromotionRun::query()
                ->where(function ($query) use ($feedProfile): void {
                    $query->where('target_feed_profile_id', $feedProfile->id)
                        ->orWhere('source_feed_profile_id', $feedProfile->id);
                })
                ->findOrFail((int) $promotionRunId);
        }

        return $feedProfile->targetPromotionRuns()
            ->where('mode', PromotionRun::MODE_APPLY)
            ->whereIn('status', [PromotionRun::STATUS_SUCCEEDED, PromotionRun::STATUS_WARNING])
            ->latest('finished_at')
            ->first()
            ?: $feedProfile->sourcePromotionRuns()
                ->where('mode', PromotionRun::MODE_APPLY)
                ->whereIn('status', [PromotionRun::STATUS_SUCCEEDED, PromotionRun::STATUS_WARNING])
                ->latest('finished_at')
                ->first();
    }

    /**
     * @return array<string, int|null>
     */
    private function baselineSeed(FeedProfile $feedProfile, ?PilotRun $pilotRun, ?FeedGeneration $publishedGeneration): array
    {
        $generation = $publishedGeneration ?? $pilotRun?->publishedGeneration ?? $pilotRun?->candidateGeneration ?? $feedProfile->latestGeneration;
        $expectedItems = $generation instanceof FeedGeneration
            ? (int) data_get($generation->meta, 'summary.ready', $generation->valid_items_total)
            : null;
        $firstPull = $feedProfile->firstPullVerifications()->latest('verified_at')->first();
        return [
            'expected_ready_items' => $expectedItems,
            'expected_published_count' => $expectedItems,
            'expected_first_pull_latency_ms' => $firstPull?->latency_ms ?: (int) config('feed_mediator.launch.baseline.default_first_pull_latency_ms', 5000),
            'expected_feedback_total' => 0,
            'expected_rejection_total' => 0,
            'expected_sync_freshness_minutes' => $feedProfile->sourceConnection?->sync_interval_minutes
                ?: (int) config('feed_mediator.launch.baseline.default_sync_freshness_minutes', 60),
        ];
    }

    /**
     * @return array<string, int|string|null>
     */
    private function feedbackOffsetSnapshot(FeedProfile $feedProfile): array
    {
        $summary = $this->feedbackSlaService->summarize($feedProfile);

        return [
            'captured_at' => now()->toIso8601String(),
            'imports_total' => (int) ($summary['imports_total'] ?? 0),
            'unmatched_feedback_count' => (int) ($summary['unmatched_feedback_count'] ?? 0),
            'open_rejected_items' => (int) ($summary['open_rejected_items'] ?? 0),
            'open_total' => (int) ($summary['open_total'] ?? 0),
            'in_progress' => (int) ($summary['in_progress'] ?? 0),
            'fixed' => (int) ($summary['fixed'] ?? 0),
            'wont_fix' => (int) ($summary['wont_fix'] ?? 0),
            'excluded' => (int) ($summary['excluded'] ?? 0),
            'rejected_total' => (int) ($summary['rejected_total'] ?? 0),
            'warning_total' => (int) ($summary['warning_total'] ?? 0),
            'accepted_total' => (int) ($summary['accepted_total'] ?? 0),
            'pending_backlog' => (int) ($summary['pending_backlog'] ?? 0),
        ];
    }

    /**
     * @param  mixed  $hypercare
     * @return array<string, mixed>
     */
    private function launchFeedbackSummary(MerchantLaunch $launch, FeedProfile $feedProfile, mixed $hypercare): array
    {
        $from = $launch->started_at ?? $launch->created_at;
        $to = $launch->closed_at ?? now();
        $windowSummary = $this->feedbackSlaService->summarize($feedProfile, $hypercare, $from, $to);
        $cumulativeSummary = $this->feedbackSlaService->summarize($feedProfile, null, null, $to);
        $offsets = (array) data_get($launch->meta, 'feedback_offsets', []);
        $deltaKeys = [
            'imports_total',
            'unmatched_feedback_count',
            'open_rejected_items',
            'open_total',
            'in_progress',
            'fixed',
            'wont_fix',
            'excluded',
            'rejected_total',
            'warning_total',
            'accepted_total',
            'pending_backlog',
        ];

        foreach ($deltaKeys as $key) {
            $windowSummary[$key] = max(
                0,
                (int) ($cumulativeSummary[$key] ?? 0) - (int) ($offsets[$key] ?? 0)
            );
        }

        $windowSummary['window'] = [
            'from' => $from?->toIso8601String(),
            'to' => $to?->toIso8601String(),
        ];
        $windowSummary['offsets'] = $offsets;

        return $windowSummary;
    }

    /**
     * @return array<string, mixed>
     */
    private function deploySummary(FeedProfile $feedProfile): array
    {
        $maintenance = $this->opsMaintenanceStatusService->summarize($feedProfile->shop, $feedProfile);
        $lastDeploy = $maintenance['last_deploy'] ?? null;

        return [
            'verified' => $lastDeploy?->status === OpsRun::STATUS_SUCCEEDED,
            'run_id' => $lastDeploy?->id,
            'status' => $lastDeploy?->status,
            'finished_at' => $lastDeploy?->finished_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function baselineSummary(
        MerchantLaunch $launch,
        FeedProfile $feedProfile,
        ?FeedGeneration $publishedGeneration,
        ?FeedGenerationSmokeCheck $latestSmoke,
        ?FeedFirstPullVerification $latestFirstPull,
        array $feedback
    ): array {
        $feedbackTotal = (int) (($feedback['accepted_total'] ?? 0) + ($feedback['warning_total'] ?? 0) + ($feedback['rejected_total'] ?? 0));
        $metrics = [
            'ready_items' => $this->countMetric(
                $launch->expected_ready_items,
                $publishedGeneration ? (int) data_get($publishedGeneration->meta, 'summary.ready', $publishedGeneration->valid_items_total) : null
            ),
            'published_count' => $this->countMetric(
                $launch->expected_published_count,
                $latestSmoke?->offers_total ?? ($publishedGeneration?->valid_items_total)
            ),
            'first_pull_latency_ms' => $this->upperBoundMetric(
                $launch->expected_first_pull_latency_ms,
                $latestFirstPull?->latency_ms
            ),
            'feedback_total' => $this->upperBoundMetric(
                $launch->expected_feedback_total,
                $feedbackTotal
            ),
            'rejection_total' => $this->upperBoundMetric(
                $launch->expected_rejection_total,
                (int) ($feedback['rejected_total'] ?? 0)
            ),
            'sync_freshness_minutes' => $this->upperBoundMetric(
                $launch->expected_sync_freshness_minutes,
                $feedProfile->sourceConnection?->last_synced_at?->diffInMinutes(now())
            ),
        ];

        return [
            'metrics' => $metrics,
            'acceptable' => collect($metrics)->every(fn (array $metric) => ($metric['status'] ?? 'pending') === 'in_range'),
            'severe_deviations' => collect($metrics)
                ->filter(fn (array $metric) => $metric['status'] === 'out_of_range')
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function stabilizationChecklist(
        MerchantLaunch $launch,
        ?FeedGeneration $publishedGeneration,
        ?FeedGenerationSmokeCheck $latestSmoke,
        ?FeedFirstPullVerification $latestFirstPull,
        array $feedback,
        Collection $criticalAlerts,
        Collection $criticalDefects,
        array $baseline,
        array $stability
    ): array {
        $items = [
            'publish_verified' => [
                'ok' => $publishedGeneration instanceof FeedGeneration && $launch->actual_published_at !== null,
                'detail' => $publishedGeneration?->id ? 'Generation #'.$publishedGeneration->id.' published.' : 'No published generation linked yet.',
            ],
            'smoke_passed' => [
                'ok' => in_array($latestSmoke?->status, [FeedGenerationSmokeCheck::STATUS_OK, FeedGenerationSmokeCheck::STATUS_WARNING], true),
                'detail' => $latestSmoke?->status ?: 'No smoke verification recorded.',
            ],
            'first_pull_passed' => [
                'ok' => in_array($latestFirstPull?->status, [FeedFirstPullVerification::STATUS_OK, FeedFirstPullVerification::STATUS_WARNING], true),
                'detail' => $latestFirstPull?->status ?: 'No first-pull verification recorded.',
            ],
            'no_critical_alerts_open' => [
                'ok' => $criticalAlerts->isEmpty(),
                'detail' => $criticalAlerts->isEmpty() ? 'No critical alerts remain.' : $criticalAlerts->count().' critical alert(s) remain open.',
            ],
            'no_critical_defects_open' => [
                'ok' => $criticalDefects->isEmpty(),
                'detail' => $criticalDefects->isEmpty() ? 'No critical launch defects remain.' : $criticalDefects->count().' critical launch defect(s) remain open.',
            ],
            'no_critical_feedback_backlog' => [
                'ok' => (int) ($feedback['pending_backlog'] ?? 0) <= (int) config('feed_mediator.launch.handover.max_feedback_backlog', 3),
                'detail' => 'Pending feedback backlog: '.($feedback['pending_backlog'] ?? 0),
            ],
            'launch_baseline_acceptable' => [
                'ok' => ($baseline['severe_deviations'] ?? []) === [],
                'detail' => ($baseline['severe_deviations'] ?? []) === [] ? 'Actual launch metrics are within expected bands.' : 'Baseline deviations require attention.',
            ],
            'rollback_plan_reviewed' => [
                'ok' => in_array($launch->state, [MerchantLaunch::STATE_STABILIZED, MerchantLaunch::STATE_CLOSED], true)
                    || ($stability['status'] ?? null) === 'stable',
                'detail' => 'Rollback plan is either no longer needed or has been reviewed as part of stabilization.',
            ],
            'merchant_acknowledged_state' => [
                'ok' => $launch->actual_go_live_confirmed_at !== null,
                'detail' => $launch->actual_go_live_confirmed_at !== null
                    ? 'Merchant or marketplace confirmation captured.'
                    : 'Merchant or pickup confirmation is still missing.',
            ],
        ];

        return [
            'items' => $items,
            'all_passed' => collect($items)->every(fn (array $item) => $item['ok'] === true),
        ];
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function blockers(
        MerchantLaunch $launch,
        array $deploy,
        ?FeedGeneration $publishedGeneration,
        ?FeedGenerationSmokeCheck $latestSmoke,
        ?FeedFirstPullVerification $latestFirstPull,
        array $feedback,
        Collection $criticalAlerts,
        Collection $criticalDefects,
        array $baseline,
        array $stability,
        bool $rolledBack,
        bool $publishFailed
    ): array {
        $messages = [];

        if (! ($deploy['verified'] ?? false)) {
            $messages[] = ['code' => 'production_deploy_not_verified', 'severity' => 'critical', 'message' => 'Production deploy has not been verified in ops runs.'];
        }

        if (! ($launch->pilotRun instanceof PilotRun) || $launch->pilotRun->state !== PilotRun::STATE_COMPLETED) {
            $messages[] = ['code' => 'pilot_not_completed', 'severity' => 'critical', 'message' => 'A completed pilot run is not linked to this launch.'];
        }

        if (! ($publishedGeneration instanceof FeedGeneration)) {
            $messages[] = ['code' => 'publish_pending', 'severity' => 'critical', 'message' => 'The first real publish is still pending.'];
        }

        if ($publishFailed) {
            $messages[] = ['code' => 'publish_failed', 'severity' => 'critical', 'message' => 'A publish failure was recorded during the live launch window.'];
        }

        if ($latestSmoke?->status === FeedGenerationSmokeCheck::STATUS_FAILED) {
            $messages[] = ['code' => 'smoke_failed', 'severity' => 'critical', 'message' => 'Latest smoke check failed.'];
        }

        if ($latestFirstPull?->status === FeedFirstPullVerification::STATUS_FAILED) {
            $messages[] = ['code' => 'first_pull_failed', 'severity' => 'critical', 'message' => 'Latest first-pull verification failed.'];
        }

        if ($criticalAlerts->isNotEmpty()) {
            $messages[] = ['code' => 'critical_alerts_open', 'severity' => 'critical', 'message' => $criticalAlerts->count().' critical ops alert(s) remain open.'];
        }

        if ($criticalDefects->isNotEmpty()) {
            $messages[] = ['code' => 'critical_defects_open', 'severity' => 'critical', 'message' => $criticalDefects->count().' critical post-launch defect(s) remain open.'];
        }

        if (($baseline['severe_deviations'] ?? []) !== []) {
            $messages[] = ['code' => 'baseline_out_of_range', 'severity' => 'critical', 'message' => 'Actual launch metrics are outside the expected band.'];
        }

        if ((int) ($feedback['pending_backlog'] ?? 0) > (int) config('feed_mediator.launch.handover.max_feedback_backlog', 3)) {
            $messages[] = ['code' => 'feedback_backlog_open', 'severity' => 'warning', 'message' => 'Feedback remediation backlog is still above the handover threshold.'];
        }

        if (($stability['status'] ?? null) === 'watch') {
            $messages[] = ['code' => 'stability_watch', 'severity' => 'warning', 'message' => 'Launch stability is still in watch mode.'];
        }

        if (in_array($stability['status'] ?? null, ['degraded', 'unstable'], true)) {
            $messages[] = ['code' => 'stability_degraded', 'severity' => 'critical', 'message' => 'Launch stability is degraded and requires active hypercare.'];
        }

        if ($rolledBack) {
            $messages[] = ['code' => 'rolled_back', 'severity' => 'warning', 'message' => 'A rollback was executed during the live launch window.'];
        }

        return $messages;
    }

    /**
     * @return list<string>
     */
    private function nextActions(
        MerchantLaunch $launch,
        array $blockers,
        array $checklist,
        Collection $criticalDefects,
        Collection $criticalAlerts,
        array $baseline
    ): array {
        $actions = [];

        foreach ($blockers as $blocker) {
            $actions[] = match ($blocker['code']) {
                'production_deploy_not_verified' => 'Verify the most recent production deploy run in ops before progressing.',
                'pilot_not_completed' => 'Link a completed pilot run or finish the active pilot workflow first.',
                'publish_pending' => 'Publish the approved generation and confirm the live feed URL.',
                'publish_failed' => 'Inspect publish failure logs and either retry publish or execute rollback.',
                'smoke_failed' => 'Rerun smoke checks and verify the public feed content and checksum.',
                'first_pull_failed' => 'Rerun first-pull verification and inspect marketplace pickup behavior.',
                'critical_alerts_open' => 'Resolve or false-positive open critical ops alerts from the launch window.',
                'critical_defects_open' => 'Triage and mitigate the open critical launch defects.',
                'baseline_out_of_range' => 'Review baseline deviations, capture observations, and apply tuning or rollback if needed.',
                'feedback_backlog_open' => 'Work down the feedback backlog in remediation workbench.',
                'stability_watch' => 'Continue elevated hypercare until stability returns to stable.',
                'stability_degraded' => 'Extend hypercare and keep rollback available until metrics recover.',
                'rolled_back' => 'Record rollback outcome, observations, and decide whether the launch stays open or closes as rolled back.',
                default => 'Review the launch detail screen and resolve the blocking condition.',
            };
        }

        if ($launch->actual_go_live_confirmed_at === null) {
            $actions[] = 'Capture merchant confirmation or first marketplace pickup as an observation.';
        }

        if (($baseline['severe_deviations'] ?? []) === [] && $criticalAlerts->isEmpty() && $criticalDefects->isEmpty() && ($checklist['all_passed'] ?? false)) {
            $actions[] = $launch->handover_state === MerchantLaunch::HANDOVER_DONE
                ? 'Launch is stable. Close the launch record when the closeout note is ready.'
                : 'Launch is ready for handover. Record the handover reason and finalize stabilization.';
        }

        return collect($actions)->filter()->unique()->values()->all();
    }

    private function resolveState(
        MerchantLaunch $launch,
        array $deploy,
        ?FeedGeneration $publishedGeneration,
        ?FeedGenerationSmokeCheck $latestSmoke,
        ?FeedFirstPullVerification $latestFirstPull,
        array $baseline,
        Collection $criticalAlerts,
        Collection $criticalDefects,
        array $stability,
        bool $rolledBack,
        bool $publishFailed,
        array $checklist,
        string $handoverState
    ): string {
        if ($rolledBack) {
            return MerchantLaunch::STATE_ROLLED_BACK;
        }

        if ($publishFailed && ! ($publishedGeneration instanceof FeedGeneration)) {
            return MerchantLaunch::STATE_FAILED;
        }

        if (! ($publishedGeneration instanceof FeedGeneration) || $launch->actual_published_at === null) {
            return ($deploy['verified'] ?? false) && $launch->pilotRun instanceof PilotRun && $launch->pilotRun->state === PilotRun::STATE_COMPLETED
                ? MerchantLaunch::STATE_EXECUTING
                : MerchantLaunch::STATE_PLANNED;
        }

        if (
            $latestSmoke?->status === FeedGenerationSmokeCheck::STATUS_FAILED
            || $latestFirstPull?->status === FeedFirstPullVerification::STATUS_FAILED
            || $criticalAlerts->isNotEmpty()
            || $criticalDefects->isNotEmpty()
            || ($baseline['severe_deviations'] ?? []) !== []
            || in_array($stability['status'] ?? null, ['degraded', 'unstable'], true)
        ) {
            return MerchantLaunch::STATE_DEGRADED;
        }

        if (($checklist['all_passed'] ?? false) || $handoverState === MerchantLaunch::HANDOVER_DONE) {
            return MerchantLaunch::STATE_STABILIZED;
        }

        if ($latestSmoke instanceof FeedGenerationSmokeCheck || $latestFirstPull instanceof FeedFirstPullVerification) {
            return MerchantLaunch::STATE_VALIDATING;
        }

        return MerchantLaunch::STATE_PUBLISHED;
    }

    private function stateLabel(string $state): string
    {
        return match ($state) {
            MerchantLaunch::STATE_PLANNED => 'Planned',
            MerchantLaunch::STATE_EXECUTING => 'Executing',
            MerchantLaunch::STATE_PUBLISHED => 'Published',
            MerchantLaunch::STATE_VALIDATING => 'Validating',
            MerchantLaunch::STATE_DEGRADED => 'Degraded',
            MerchantLaunch::STATE_STABILIZED => 'Stabilized',
            MerchantLaunch::STATE_ROLLED_BACK => 'Rolled back',
            MerchantLaunch::STATE_FAILED => 'Failed',
            MerchantLaunch::STATE_CLOSED => 'Closed',
            default => ucfirst(str_replace('_', ' ', $state)),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function countMetric(?int $expected, mixed $actual): array
    {
        if ($expected === null || $actual === null) {
            return [
                'expected' => $expected,
                'actual' => $actual,
                'delta' => null,
                'status' => 'pending',
            ];
        }

        $actual = (int) $actual;
        $delta = $actual - $expected;
        $warnPct = (float) config('feed_mediator.launch.baseline.count_warn_pct', 0.15);
        $critPct = (float) config('feed_mediator.launch.baseline.count_critical_pct', 0.30);
        $absDelta = abs($delta);
        $warn = max(1, (int) round($expected * $warnPct));
        $critical = max($warn + 1, (int) round($expected * $critPct));

        return [
            'expected' => $expected,
            'actual' => $actual,
            'delta' => $delta,
            'status' => $absDelta <= $warn ? 'in_range' : ($absDelta <= $critical ? 'warning' : 'out_of_range'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function upperBoundMetric(?int $expected, mixed $actual): array
    {
        if ($expected === null || $actual === null) {
            return [
                'expected' => $expected,
                'actual' => $actual,
                'delta' => null,
                'status' => 'pending',
            ];
        }

        $actual = (int) $actual;
        $delta = $actual - $expected;
        $warnPct = (float) config('feed_mediator.launch.baseline.upper_warn_pct', 0.50);
        $critPct = (float) config('feed_mediator.launch.baseline.upper_critical_pct', 1.00);
        $warn = (int) round($expected * (1 + $warnPct));
        $critical = (int) round($expected * (1 + $critPct));

        return [
            'expected' => $expected,
            'actual' => $actual,
            'delta' => $delta,
            'status' => $actual <= $expected ? 'in_range' : ($actual <= $warn ? 'warning' : ($actual <= $critical ? 'warning' : 'out_of_range')),
        ];
    }

    private function syncDeviationAlerts(
        MerchantLaunch $launch,
        FeedProfile $feedProfile,
        ?FeedGeneration $publishedGeneration,
        array $baseline
    ): void {
        $metrics = (array) ($baseline['metrics'] ?? []);
        $hypercare = $feedProfile->currentHypercareWindow;
        $generation = $publishedGeneration ?? $feedProfile->publishedGeneration;
        $map = [
            'ready_items' => [OpsAlert::SOURCE_READY_ITEMS_COLLAPSE, 'Launch ready items deviated from baseline'],
            'published_count' => [OpsAlert::SOURCE_PUBLISH_DELTA_ANOMALY, 'Published count deviated from baseline'],
            'first_pull_latency_ms' => [OpsAlert::SOURCE_FIRST_PULL_FAILURE, 'First-pull latency deviated from baseline'],
            'feedback_total' => [OpsAlert::SOURCE_REJECTION_SPIKE, 'Feedback volume deviated from baseline'],
            'rejection_total' => [OpsAlert::SOURCE_REJECTION_SPIKE, 'Rejection volume deviated from baseline'],
            'sync_freshness_minutes' => [OpsAlert::SOURCE_SYNC_FAILURE, 'Sync freshness deviated from baseline'],
        ];

        foreach ($map as $key => [$source, $title]) {
            $metric = $metrics[$key] ?? null;
            $fingerprint = 'launch-'.$launch->id.'-'.$key;

            if (! is_array($metric) || ($metric['status'] ?? 'pending') === 'pending') {
                continue;
            }

            if (($metric['status'] ?? 'in_range') === 'out_of_range') {
                $this->opsAlertService->raiseForProfile(
                    $feedProfile,
                    $source,
                    OpsAlert::SEVERITY_CRITICAL,
                    $title,
                    sprintf('Actual metric %s is %s vs expected %s.', $key, (string) $metric['actual'], (string) $metric['expected']),
                    [
                        'merchant_launch_id' => $launch->id,
                        'metric' => $key,
                        'metric_summary' => $metric,
                    ],
                    $generation,
                    $hypercare,
                    $fingerprint
                );

                continue;
            }

            if (($metric['status'] ?? 'in_range') === 'warning') {
                $this->opsAlertService->raiseForProfile(
                    $feedProfile,
                    $source,
                    OpsAlert::SEVERITY_WARNING,
                    $title,
                    sprintf('Actual metric %s is %s vs expected %s.', $key, (string) $metric['actual'], (string) $metric['expected']),
                    [
                        'merchant_launch_id' => $launch->id,
                        'metric' => $key,
                        'metric_summary' => $metric,
                    ],
                    $generation,
                    $hypercare,
                    $fingerprint
                );

                continue;
            }

            $this->opsAlertService->resolveFingerprint($feedProfile, $fingerprint, 'Launch metric returned to expected band.');
        }
    }

    private function audit(
        MerchantLaunch $launch,
        string $action,
        ?User $user = null,
        ?string $reason = null,
        array $meta = []
    ): void {
        $this->auditService->record(
            $launch->feedProfile,
            $launch->publishedGeneration ?? $launch->feedProfile->publishedGeneration ?? $launch->feedProfile->latestGeneration,
            $action,
            $user,
            $reason,
            array_merge($meta, [
                'merchant_launch_id' => $launch->id,
            ])
        );
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function applyPublishGuardTuning(array &$settings, string $key, mixed $value): void
    {
        $key = $key !== '' ? $key : 'enabled';

        match ($key) {
            'enabled' => $settings['publish_guard_enabled'] = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? in_array((string) $value, ['1', 'true', 'yes', 'on'], true),
            'minimum_ready_items' => $settings['minimum_ready_items'] = max(0, (int) $value),
            'maximum_invalid_ratio' => $settings['maximum_invalid_ratio'] = max(0, min(1, (float) $value)),
            'block_publish_on_critical_conformance' => $settings['block_publish_on_critical_conformance'] = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? in_array((string) $value, ['1', 'true', 'yes', 'on'], true),
            default => throw new RuntimeException('Unsupported publish guard tuning key.'),
        };
    }

    /**
     * @param  list<int>  $values
     * @return list<int>
     */
    private function appendUniqueInt(array $values, mixed $value): array
    {
        $values[] = (int) $value;

        return collect($values)->map(fn ($item) => (int) $item)->unique()->values()->all();
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function appendUniqueString(array $values, mixed $value): array
    {
        $values[] = trim((string) $value);

        return collect($values)->map(fn ($item) => trim((string) $item))->filter()->unique()->values()->all();
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function mergeOverride(array $values, string $key, mixed $value): array
    {
        if ($key === '') {
            throw new RuntimeException('Override key is required.');
        }

        $values[$key] = $value;

        return $values;
    }
}
