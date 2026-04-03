<?php

namespace App\Services\Feeds;

use App\Models\FeedFirstPullVerification;
use App\Models\FeedGeneration;
use App\Models\FeedGenerationSmokeCheck;
use App\Models\FeedHypercareWindow;
use App\Models\FeedProfile;
use App\Models\OpsAlert;
use App\Models\SourceImport;

class FeedStabilityService
{
    public function __construct(
        private readonly FeedbackSlaService $feedbackSlaService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function evaluate(FeedProfile $feedProfile, ?FeedHypercareWindow $hypercare = null): array
    {
        $hypercare ??= $feedProfile->currentHypercareWindow;
        [$from, $to] = $this->window($hypercare);
        $syncRate = $this->ratio(
            SourceImport::query()
                ->where('source_connection_id', $feedProfile->source_connection_id)
                ->whereBetween('started_at', [$from, $to]),
            fn ($query) => $query->where('status', SourceImport::STATUS_NORMALIZED)
        );
        $buildRate = $this->ratio(
            FeedGeneration::query()
                ->where('feed_profile_id', $feedProfile->id)
                ->whereBetween('created_at', [$from, $to]),
            fn ($query) => $query->whereIn('status', [FeedGeneration::STATUS_BUILT, FeedGeneration::STATUS_PUBLISHED])
        );
        $publishRate = $this->ratio(
            $feedProfile->releaseEvents()
                ->whereBetween('occurred_at', [$from, $to])
                ->whereIn('action', ['published', 'force_published', 'publish_failed']),
            fn ($query) => $query->whereIn('action', ['published', 'force_published'])
        );
        $smokeRate = $this->ratio(
            FeedGenerationSmokeCheck::query()
                ->where('feed_profile_id', $feedProfile->id)
                ->whereBetween('checked_at', [$from, $to]),
            fn ($query) => $query->whereIn('status', [FeedGenerationSmokeCheck::STATUS_OK, FeedGenerationSmokeCheck::STATUS_WARNING])
        );
        $firstPullRate = $this->ratio(
            FeedFirstPullVerification::query()
                ->where('feed_profile_id', $feedProfile->id)
                ->whereBetween('verified_at', [$from, $to]),
            fn ($query) => $query->whereIn('status', [FeedFirstPullVerification::STATUS_OK, FeedFirstPullVerification::STATUS_WARNING])
        );
        $feedback = $this->feedbackSlaService->summarize($feedProfile, $hypercare, $from, $to);
        $openCriticalIncidents = OpsAlert::query()
            ->where('feed_profile_id', $feedProfile->id)
            ->when($hypercare !== null, fn ($query) => $query->where('feed_hypercare_window_id', $hypercare->id))
            ->where('severity', OpsAlert::SEVERITY_CRITICAL)
            ->whereNotIn('state', [OpsAlert::STATE_RESOLVED, OpsAlert::STATE_FALSE_POSITIVE])
            ->count();
        $lastRollback = $feedProfile->releaseEvents()
            ->where('action', 'rolled_back')
            ->whereBetween('occurred_at', [$from, $to])
            ->latest('occurred_at')
            ->first();
        $score = 100;

        foreach ([$syncRate, $buildRate, $publishRate, $smokeRate, $firstPullRate] as $rate) {
            $score -= $this->penaltyForRate($rate['rate']);
        }

        $score -= min(20, ((int) ($feedback['rejected_total'] ?? 0)) / 2);
        $score -= min(20, ((int) ($feedback['pending_backlog'] ?? 0)));
        $score -= $openCriticalIncidents * 15;
        $score -= $lastRollback ? 15 : 0;
        $score = max(0, (int) round($score));

        $status = match (true) {
            $score >= 85 => 'stable',
            $score >= 65 => 'watch',
            $score >= 40 => 'degraded',
            default => 'unstable',
        };

        return [
            'score' => $score,
            'status' => $status,
            'factors' => [
                'sync' => $syncRate,
                'build' => $buildRate,
                'publish' => $publishRate,
                'smoke' => $smokeRate,
                'first_pull' => $firstPullRate,
                'feedback_rejected_total' => $feedback['rejected_total'] ?? 0,
                'feedback_backlog' => $feedback['pending_backlog'] ?? 0,
                'open_critical_incidents' => $openCriticalIncidents,
                'last_rollback_at' => $lastRollback?->occurred_at?->toIso8601String(),
            ],
            'blockers' => $openCriticalIncidents > 0 ? ['Open critical incidents must be resolved before clean hypercare closeout.'] : [],
            'recommended_next_steps' => $this->nextSteps($status, $feedback, $openCriticalIncidents, $lastRollback !== null),
        ];
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Relations\HasMany  $baseQuery
     * @return array<string, int|float|null>
     */
    private function ratio($baseQuery, callable $successConstraint): array
    {
        $total = (clone $baseQuery)->count();
        $successes = $successConstraint(clone $baseQuery)->count();

        return [
            'total' => $total,
            'successes' => $successes,
            'failures' => max(0, $total - $successes),
            'rate' => $total > 0 ? round($successes / $total, 4) : null,
        ];
    }

    /**
     * @return array{0:mixed,1:mixed}
     */
    private function window(?FeedHypercareWindow $hypercare): array
    {
        return [
            $hypercare?->started_at ?? now()->subHours(72),
            $hypercare?->actual_end_at ?? now(),
        ];
    }

    private function penaltyForRate(?float $rate): int
    {
        if ($rate === null || $rate >= 0.98) {
            return 0;
        }

        if ($rate >= 0.90) {
            return 8;
        }

        if ($rate >= 0.75) {
            return 18;
        }

        return 28;
    }

    /**
     * @param  array<string, mixed>  $feedback
     * @return list<string>
     */
    private function nextSteps(string $status, array $feedback, int $openCriticalIncidents, bool $hasRollback): array
    {
        $steps = [];

        if ($openCriticalIncidents > 0) {
            $steps[] = 'Resolve or false-positive all critical incidents before closeout.';
        }

        if (($feedback['pending_backlog'] ?? 0) > 0) {
            $steps[] = 'Work through the remaining feedback remediation backlog.';
        }

        if ($hasRollback) {
            $steps[] = 'Review rollback cause and confirm the currently published generation is stable.';
        }

        if ($status === 'watch') {
            $steps[] = 'Keep elevated monitoring until the score returns to stable.';
        }

        if (in_array($status, ['degraded', 'unstable'], true)) {
            $steps[] = 'Extend hypercare and continue active monitoring.';
        }

        return $steps;
    }
}
