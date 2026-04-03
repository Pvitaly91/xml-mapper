<?php

namespace App\Services\Ops;

use App\Models\FeedbackRecord;
use App\Models\FeedFirstPullVerification;
use App\Models\FeedGeneration;
use App\Models\FeedGenerationSmokeCheck;
use App\Models\FeedHypercareWindow;
use App\Models\FeedProfile;
use App\Models\OpsAlert;
use App\Models\OpsPolicyResult;
use App\Models\SourceConnection;
use App\Services\Feeds\FeedbackSlaService;
use Carbon\CarbonInterface;

class HypercarePolicyService
{
    public function __construct(
        private readonly FeedbackSlaService $feedbackSlaService,
        private readonly OpsStatusService $opsStatusService,
        private readonly OpsMaintenanceStatusService $opsMaintenanceStatusService,
        private readonly OpsAlertService $alertService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function review(
        FeedProfile $feedProfile,
        ?FeedHypercareWindow $hypercare = null,
        ?CarbonInterface $reference = null
    ): array {
        $feedProfile->loadMissing([
            'sourceConnection.latestImport',
            'publishedGeneration',
            'currentHypercareWindow',
        ]);
        $hypercare ??= $feedProfile->currentHypercareWindow;
        $reference ??= now();
        $generation = $hypercare?->feedGeneration ?? $feedProfile->publishedGeneration ?? $feedProfile->latestGeneration;
        $phase = $this->phase($hypercare, $reference);
        $feedbackSla = $this->feedbackSlaService->summarize($feedProfile, $hypercare);
        $ops = $this->opsStatusService->snapshot($feedProfile->shop);
        $maintenance = $this->opsMaintenanceStatusService->summarize($feedProfile->shop, $feedProfile);
        $results = [
            $this->smokeChecksCadence($feedProfile, $hypercare, $generation, $phase, $reference),
            $this->firstPullCadence($feedProfile, $hypercare, $generation, $phase, $reference),
            $this->sourceSyncFreshness($feedProfile, $phase, $reference),
            $this->publishDeltaAnomaly($feedProfile, $generation),
            $this->brokenAuth($feedProfile),
            $this->feedbackSpike($feedProfile, $phase, $reference),
            $this->readyItemsDrop($feedProfile, $generation),
            $this->queueLag($feedProfile, $ops, $maintenance),
            $this->feedLatency($feedProfile, $generation),
            $this->feedbackBacklog($feedProfile, $feedbackSla),
        ];

        foreach ($results as $result) {
            OpsPolicyResult::query()->updateOrCreate(
                [
                    'feed_profile_id' => $feedProfile->id,
                    'feed_hypercare_window_id' => $hypercare?->id,
                    'policy_key' => $result['policy_key'],
                ],
                [
                    'shop_id' => $feedProfile->shop_id,
                    'feed_generation_id' => $generation?->id,
                    'status' => $result['status'],
                    'summary' => $result['summary'],
                    'due_at' => $result['due_at'],
                    'evaluated_at' => $reference,
                    'meta' => $result['meta'],
                ]
            );

            if ($result['status'] === OpsPolicyResult::STATUS_OK) {
                $this->alertService->resolveFingerprint($feedProfile, (string) $result['alert']['fingerprint'], 'Policy recovered.');

                continue;
            }

            $this->alertService->raiseForProfile(
                $feedProfile,
                (string) $result['alert']['source'],
                (string) $result['alert']['severity'],
                (string) $result['alert']['title'],
                (string) $result['alert']['message'],
                $result['meta'],
                $generation,
                $hypercare,
                (string) $result['alert']['fingerprint']
            );
        }

        return [
            'hypercare' => $hypercare,
            'phase' => $phase,
            'results' => $results,
            'next_checks_due' => collect($results)
                ->filter(fn (array $result) => $result['due_at'] !== null)
                ->sortBy('due_at')
                ->values()
                ->all(),
            'current_risk_state' => $this->currentRiskState($results),
            'blocking_results' => array_values(array_filter($results, fn (array $result) => $result['status'] === OpsPolicyResult::STATUS_CRITICAL)),
            'feedback_sla' => $feedbackSla,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function smokeChecksCadence(
        FeedProfile $feedProfile,
        ?FeedHypercareWindow $hypercare,
        ?FeedGeneration $generation,
        array $phase,
        CarbonInterface $reference
    ): array {
        $lastSmoke = $generation?->smokeChecks()->latest('checked_at')->first();
        $cadenceMinutes = (int) $this->override($feedProfile, 'smoke_checks_cadence_minutes', $phase['smoke_checks_cadence_minutes']);
        $start = $hypercare?->started_at ?? $generation?->published_at ?? $reference;
        $dueAt = ($lastSmoke?->checked_at ?? $start)?->copy()->addMinutes($cadenceMinutes);
        $status = OpsPolicyResult::STATUS_OK;
        $summary = 'Smoke checks are within cadence.';

        if ($lastSmoke?->status === FeedGenerationSmokeCheck::STATUS_FAILED) {
            $status = OpsPolicyResult::STATUS_CRITICAL;
            $summary = 'Latest smoke check failed.';
        } elseif ($lastSmoke?->status === FeedGenerationSmokeCheck::STATUS_WARNING) {
            $status = OpsPolicyResult::STATUS_WARNING;
            $summary = 'Latest smoke check returned warnings.';
        } elseif ($lastSmoke === null && $reference->greaterThan($dueAt)) {
            $status = OpsPolicyResult::STATUS_CRITICAL;
            $summary = 'No smoke check was recorded inside the required cadence.';
        } elseif ($lastSmoke?->checked_at?->lt($reference->copy()->subMinutes($cadenceMinutes * 2))) {
            $status = OpsPolicyResult::STATUS_CRITICAL;
            $summary = 'Smoke checks are overdue beyond the critical threshold.';
        } elseif ($lastSmoke?->checked_at?->lt($reference->copy()->subMinutes($cadenceMinutes))) {
            $status = OpsPolicyResult::STATUS_WARNING;
            $summary = 'Smoke checks are overdue.';
        }

        return $this->result(
            'smoke_checks_cadence',
            $status,
            $summary,
            $dueAt,
            [
                'last_checked_at' => $lastSmoke?->checked_at?->toIso8601String(),
                'last_status' => $lastSmoke?->status,
                'cadence_minutes' => $cadenceMinutes,
            ],
            OpsAlert::SOURCE_SMOKE_CHECK_FAILURE,
            'policy:smoke_checks_cadence',
            'Smoke checks need attention'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function firstPullCadence(
        FeedProfile $feedProfile,
        ?FeedHypercareWindow $hypercare,
        ?FeedGeneration $generation,
        array $phase,
        CarbonInterface $reference
    ): array {
        $lastVerification = $feedProfile->firstPullVerifications()->latest('verified_at')->first();
        $cadenceMinutes = (int) $this->override($feedProfile, 'first_pull_cadence_minutes', $phase['first_pull_cadence_minutes']);
        $start = $hypercare?->started_at ?? $generation?->published_at ?? $reference;
        $dueAt = ($lastVerification?->verified_at ?? $start)?->copy()->addMinutes($cadenceMinutes);
        $status = OpsPolicyResult::STATUS_OK;
        $summary = 'First-pull verification is within cadence.';

        if ($lastVerification?->status === FeedFirstPullVerification::STATUS_FAILED) {
            $status = OpsPolicyResult::STATUS_CRITICAL;
            $summary = 'Latest first-pull verification failed.';
        } elseif ($lastVerification?->status === FeedFirstPullVerification::STATUS_WARNING) {
            $status = OpsPolicyResult::STATUS_WARNING;
            $summary = 'Latest first-pull verification returned warnings.';
        } elseif ($lastVerification === null && $reference->greaterThan($dueAt)) {
            $status = OpsPolicyResult::STATUS_CRITICAL;
            $summary = 'No first-pull verification was recorded inside the required cadence.';
        } elseif ($lastVerification?->verified_at?->lt($reference->copy()->subMinutes($cadenceMinutes * 2))) {
            $status = OpsPolicyResult::STATUS_CRITICAL;
            $summary = 'First-pull verification is overdue beyond the critical threshold.';
        } elseif ($lastVerification?->verified_at?->lt($reference->copy()->subMinutes($cadenceMinutes))) {
            $status = OpsPolicyResult::STATUS_WARNING;
            $summary = 'First-pull verification is overdue.';
        }

        return $this->result(
            'first_pull_cadence',
            $status,
            $summary,
            $dueAt,
            [
                'last_verified_at' => $lastVerification?->verified_at?->toIso8601String(),
                'last_status' => $lastVerification?->status,
                'cadence_minutes' => $cadenceMinutes,
            ],
            OpsAlert::SOURCE_FIRST_PULL_FAILURE,
            'policy:first_pull_cadence',
            'First-pull verification needs attention'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function sourceSyncFreshness(FeedProfile $feedProfile, array $phase, CarbonInterface $reference): array
    {
        $connection = $feedProfile->sourceConnection;
        $warningMinutes = (int) $this->override($feedProfile, 'sync_warning_after_minutes', $phase['sync_warning_after_minutes']);
        $criticalMinutes = (int) $this->override($feedProfile, 'sync_critical_after_minutes', $phase['sync_critical_after_minutes']);
        $dueAt = $connection?->last_synced_at?->copy()->addMinutes($warningMinutes);
        $status = OpsPolicyResult::STATUS_OK;
        $summary = 'Source sync freshness is within policy.';

        if (! $connection instanceof SourceConnection || $connection->last_synced_at === null) {
            $status = OpsPolicyResult::STATUS_CRITICAL;
            $summary = 'No source sync timestamp is available.';
        } elseif ($connection->last_synced_at->lt($reference->copy()->subMinutes($criticalMinutes))) {
            $status = OpsPolicyResult::STATUS_CRITICAL;
            $summary = 'Source sync is stale beyond the critical threshold.';
        } elseif ($connection->last_synced_at->lt($reference->copy()->subMinutes($warningMinutes))) {
            $status = OpsPolicyResult::STATUS_WARNING;
            $summary = 'Source sync is stale.';
        }

        return $this->result(
            'source_sync_freshness',
            $status,
            $summary,
            $dueAt,
            [
                'last_synced_at' => $connection?->last_synced_at?->toIso8601String(),
                'last_sync_status' => $connection?->last_sync_status,
                'warning_after_minutes' => $warningMinutes,
                'critical_after_minutes' => $criticalMinutes,
            ],
            OpsAlert::SOURCE_SYNC_FAILURE,
            'policy:source_sync_freshness',
            'Source sync freshness degraded'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function publishDeltaAnomaly(FeedProfile $feedProfile, ?FeedGeneration $generation): array
    {
        $previous = $feedProfile->generations()
            ->whereNotNull('published_at')
            ->when($generation !== null, fn ($query) => $query->whereKeyNot($generation->id))
            ->latest('published_at')
            ->latest('id')
            ->first();
        $currentReady = (int) ($generation->meta['summary']['ready'] ?? $generation?->valid_items_total ?? 0);
        $previousReady = (int) ($previous->meta['summary']['ready'] ?? $previous?->valid_items_total ?? 0);
        $deltaPct = $previousReady > 0 ? round((($currentReady - $previousReady) / $previousReady) * 100, 2) : 0.0;
        $warning = (float) $this->override($feedProfile, 'publish_delta_warning_pct', config('feed_mediator.hypercare.policies.publish_delta_anomaly.warning_pct', 15));
        $critical = (float) $this->override($feedProfile, 'publish_delta_critical_pct', config('feed_mediator.hypercare.policies.publish_delta_anomaly.critical_pct', 30));
        $status = OpsPolicyResult::STATUS_OK;
        $summary = 'Published item delta looks normal.';

        if ($previous instanceof FeedGeneration && abs($deltaPct) >= $critical) {
            $status = OpsPolicyResult::STATUS_CRITICAL;
            $summary = sprintf('Published ready-item delta moved by %s%% versus the previous publish.', $deltaPct);
        } elseif ($previous instanceof FeedGeneration && abs($deltaPct) >= $warning) {
            $status = OpsPolicyResult::STATUS_WARNING;
            $summary = sprintf('Published ready-item delta moved by %s%% versus the previous publish.', $deltaPct);
        }

        return $this->result(
            'publish_delta_anomaly',
            $status,
            $summary,
            null,
            [
                'current_ready' => $currentReady,
                'previous_ready' => $previousReady,
                'delta_pct' => $deltaPct,
            ],
            OpsAlert::SOURCE_PUBLISH_DELTA_ANOMALY,
            'policy:publish_delta_anomaly',
            'Published item delta anomaly'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function brokenAuth(FeedProfile $feedProfile): array
    {
        $connection = $feedProfile->sourceConnection;
        $broken = in_array($connection?->last_connection_check_status, [
            SourceConnection::CHECK_STATUS_AUTH_FAILED,
            SourceConnection::CHECK_STATUS_CONFIG_ERROR,
        ], true) || in_array($connection?->last_sync_status, [
            SourceConnection::CHECK_STATUS_AUTH_FAILED,
            SourceConnection::CHECK_STATUS_CONFIG_ERROR,
        ], true);

        return $this->result(
            'broken_auth',
            $broken ? OpsPolicyResult::STATUS_CRITICAL : OpsPolicyResult::STATUS_OK,
            $broken ? 'Source authentication is broken.' : 'Source authentication is healthy.',
            null,
            [
                'last_connection_check_status' => $connection?->last_connection_check_status,
                'last_sync_status' => $connection?->last_sync_status,
            ],
            OpsAlert::SOURCE_SOURCE_AUTH_BROKEN,
            'policy:broken_auth',
            'Source authentication failed'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function feedbackSpike(FeedProfile $feedProfile, array $phase, CarbonInterface $reference): array
    {
        $windowHours = (int) $this->override($feedProfile, 'feedback_spike_window_hours', $phase['feedback_spike_window_hours']);
        $warning = (int) $this->override($feedProfile, 'feedback_spike_warning_count', $phase['feedback_spike_warning_count']);
        $critical = (int) $this->override($feedProfile, 'feedback_spike_critical_count', $phase['feedback_spike_critical_count']);
        $recentRejected = $feedProfile->feedbackRecords()
            ->where('status', FeedbackRecord::STATUS_REJECTED)
            ->where('imported_at', '>=', $reference->copy()->subHours($windowHours))
            ->count();
        $status = OpsPolicyResult::STATUS_OK;
        $summary = 'Feedback rejection volume is normal.';

        if ($recentRejected >= $critical) {
            $status = OpsPolicyResult::STATUS_CRITICAL;
            $summary = sprintf('Feedback rejections spiked to %d items in the last %d hour(s).', $recentRejected, $windowHours);
        } elseif ($recentRejected >= $warning) {
            $status = OpsPolicyResult::STATUS_WARNING;
            $summary = sprintf('Feedback rejections reached %d items in the last %d hour(s).', $recentRejected, $windowHours);
        }

        return $this->result(
            'feedback_rejection_spike',
            $status,
            $summary,
            null,
            [
                'recent_rejected' => $recentRejected,
                'window_hours' => $windowHours,
            ],
            OpsAlert::SOURCE_REJECTION_SPIKE,
            'policy:feedback_rejection_spike',
            'Feedback rejection spike detected'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function readyItemsDrop(FeedProfile $feedProfile, ?FeedGeneration $generation): array
    {
        $previous = $feedProfile->generations()
            ->whereNotNull('published_at')
            ->when($generation !== null, fn ($query) => $query->whereKeyNot($generation->id))
            ->latest('published_at')
            ->latest('id')
            ->first();
        $currentReady = (int) ($generation->meta['summary']['ready'] ?? $generation?->valid_items_total ?? 0);
        $previousReady = (int) ($previous->meta['summary']['ready'] ?? $previous?->valid_items_total ?? 0);
        $dropPct = $previousReady > 0 ? round((max(0, $previousReady - $currentReady) / $previousReady) * 100, 2) : 0.0;
        $warning = (float) $this->override($feedProfile, 'ready_drop_warning_pct', config('feed_mediator.hypercare.policies.ready_items_drop.warning_pct', 10));
        $critical = (float) $this->override($feedProfile, 'ready_drop_critical_pct', config('feed_mediator.hypercare.policies.ready_items_drop.critical_pct', 20));
        $status = OpsPolicyResult::STATUS_OK;
        $summary = 'Ready-item volume is stable.';

        if ($previous instanceof FeedGeneration && $dropPct >= $critical) {
            $status = OpsPolicyResult::STATUS_CRITICAL;
            $summary = sprintf('Ready items dropped by %s%% versus the previous publish.', $dropPct);
        } elseif ($previous instanceof FeedGeneration && $dropPct >= $warning) {
            $status = OpsPolicyResult::STATUS_WARNING;
            $summary = sprintf('Ready items dropped by %s%% versus the previous publish.', $dropPct);
        }

        return $this->result(
            'ready_items_drop',
            $status,
            $summary,
            null,
            [
                'current_ready' => $currentReady,
                'previous_ready' => $previousReady,
                'drop_pct' => $dropPct,
            ],
            OpsAlert::SOURCE_READY_ITEMS_COLLAPSE,
            'policy:ready_items_drop',
            'Ready-item collapse detected'
        );
    }

    /**
     * @param  array<string, mixed>  $ops
     * @param  array<string, mixed>  $maintenance
     * @return array<string, mixed>
     */
    private function queueLag(FeedProfile $feedProfile, array $ops, array $maintenance): array
    {
        $warningJobs = (int) $this->override($feedProfile, 'failed_jobs_warning_count', config('feed_mediator.hypercare.policies.queue_lag.warning_failed_jobs', 1));
        $criticalJobs = (int) $this->override($feedProfile, 'failed_jobs_critical_count', config('feed_mediator.hypercare.policies.queue_lag.critical_failed_jobs', 3));
        $warningQueue = (int) $this->override($feedProfile, 'queue_warning_backlog', config('feed_mediator.hypercare.policies.queue_lag.warning_backlog', 10));
        $criticalQueue = (int) $this->override($feedProfile, 'queue_critical_backlog', config('feed_mediator.hypercare.policies.queue_lag.critical_backlog', 25));
        $failedJobs = (int) ($ops['failed_jobs']['count'] ?? 0);
        $backlog = collect($maintenance['queue_backlog'] ?? [])->filter(fn ($value) => $value !== null)->max() ?? 0;
        $status = OpsPolicyResult::STATUS_OK;
        $summary = 'Queue backlog and failed jobs are healthy.';

        if ($failedJobs >= $criticalJobs || $backlog >= $criticalQueue) {
            $status = OpsPolicyResult::STATUS_CRITICAL;
            $summary = sprintf('Queue lag is critical (%d failed jobs, max backlog %d).', $failedJobs, $backlog);
        } elseif ($failedJobs >= $warningJobs || $backlog >= $warningQueue) {
            $status = OpsPolicyResult::STATUS_WARNING;
            $summary = sprintf('Queue lag needs attention (%d failed jobs, max backlog %d).', $failedJobs, $backlog);
        }

        return $this->result(
            'queue_lag',
            $status,
            $summary,
            null,
            [
                'failed_jobs' => $failedJobs,
                'max_queue_backlog' => $backlog,
                'queue_backlog' => $maintenance['queue_backlog'] ?? [],
            ],
            OpsAlert::SOURCE_QUEUE_BACKLOG,
            'policy:queue_lag',
            'Queue backlog issue detected'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function feedLatency(FeedProfile $feedProfile, ?FeedGeneration $generation): array
    {
        $latestSmoke = $generation?->smokeChecks()->latest('checked_at')->first();
        $latestFirstPull = $feedProfile->firstPullVerifications()->latest('verified_at')->first();
        $latency = max((int) ($latestSmoke?->latency_ms ?? 0), (int) ($latestFirstPull?->latency_ms ?? 0));
        $warning = (int) $this->override($feedProfile, 'latency_warning_ms', config('feed_mediator.hypercare.policies.feed_url_latency.warning_ms', 3000));
        $critical = (int) $this->override($feedProfile, 'latency_critical_ms', config('feed_mediator.hypercare.policies.feed_url_latency.critical_ms', 6000));
        $status = OpsPolicyResult::STATUS_OK;
        $summary = 'Feed URL latency is healthy.';

        if ($latency >= $critical) {
            $status = OpsPolicyResult::STATUS_CRITICAL;
            $summary = sprintf('Feed URL latency reached %d ms.', $latency);
        } elseif ($latency >= $warning) {
            $status = OpsPolicyResult::STATUS_WARNING;
            $summary = sprintf('Feed URL latency reached %d ms.', $latency);
        }

        return $this->result(
            'feed_url_latency',
            $status,
            $summary,
            null,
            [
                'latency_ms' => $latency,
                'smoke_check_id' => $latestSmoke?->id,
                'first_pull_id' => $latestFirstPull?->id,
            ],
            OpsAlert::SOURCE_SMOKE_CHECK_FAILURE,
            'policy:feed_url_latency',
            'Feed URL latency degraded'
        );
    }

    /**
     * @param  array<string, mixed>  $feedbackSla
     * @return array<string, mixed>
     */
    private function feedbackBacklog(FeedProfile $feedProfile, array $feedbackSla): array
    {
        $warning = (int) $this->override($feedProfile, 'feedback_backlog_warning_count', config('feed_mediator.hypercare.policies.feedback_backlog.warning_count', 10));
        $critical = (int) $this->override($feedProfile, 'feedback_backlog_critical_count', config('feed_mediator.hypercare.policies.feedback_backlog.critical_count', 25));
        $backlog = (int) ($feedbackSla['pending_backlog'] ?? 0);
        $status = OpsPolicyResult::STATUS_OK;
        $summary = 'Feedback backlog is under control.';

        if ($backlog >= $critical) {
            $status = OpsPolicyResult::STATUS_CRITICAL;
            $summary = sprintf('Feedback backlog reached %d unresolved item(s).', $backlog);
        } elseif ($backlog >= $warning) {
            $status = OpsPolicyResult::STATUS_WARNING;
            $summary = sprintf('Feedback backlog reached %d unresolved item(s).', $backlog);
        }

        return $this->result(
            'feedback_backlog',
            $status,
            $summary,
            null,
            [
                'pending_backlog' => $backlog,
                'open_rejected_items' => $feedbackSla['open_rejected_items'] ?? 0,
            ],
            OpsAlert::SOURCE_REJECTION_SPIKE,
            'policy:feedback_backlog',
            'Feedback backlog is growing'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function result(
        string $policyKey,
        string $status,
        string $summary,
        mixed $dueAt,
        array $meta,
        string $alertSource,
        string $fingerprint,
        string $title
    ): array {
        return [
            'policy_key' => $policyKey,
            'status' => $status,
            'summary' => $summary,
            'due_at' => $dueAt,
            'meta' => $meta,
            'alert' => [
                'source' => $alertSource,
                'fingerprint' => $fingerprint,
                'title' => $title,
                'message' => $summary,
                'severity' => match ($status) {
                    OpsPolicyResult::STATUS_CRITICAL => OpsAlert::SEVERITY_CRITICAL,
                    OpsPolicyResult::STATUS_WARNING => OpsAlert::SEVERITY_WARNING,
                    default => OpsAlert::SEVERITY_INFO,
                },
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function phase(?FeedHypercareWindow $hypercare, CarbonInterface $reference): array
    {
        $hours = $hypercare?->started_at?->diffInHours($reference) ?? 0;

        if ($hours < 24) {
            return array_merge(['name' => 'first_24h'], (array) config('feed_mediator.hypercare.phases.first_24h', []));
        }

        if ($hours < 72) {
            return array_merge(['name' => 'first_72h'], (array) config('feed_mediator.hypercare.phases.first_72h', []));
        }

        return array_merge(['name' => 'steady'], (array) config('feed_mediator.hypercare.phases.steady', []));
    }

    private function override(FeedProfile $feedProfile, string $key, mixed $default): mixed
    {
        return data_get($feedProfile->hypercarePolicyOverrides(), $key, $default);
    }

    /**
     * @param  array<int, array<string, mixed>>  $results
     */
    private function currentRiskState(array $results): string
    {
        if (collect($results)->contains(fn (array $result) => $result['status'] === OpsPolicyResult::STATUS_CRITICAL)) {
            return 'critical';
        }

        if (collect($results)->contains(fn (array $result) => $result['status'] === OpsPolicyResult::STATUS_WARNING)) {
            return 'warning';
        }

        return 'ok';
    }
}
