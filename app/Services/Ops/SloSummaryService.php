<?php

namespace App\Services\Ops;

use App\Models\FeedFirstPullVerification;
use App\Models\FeedGeneration;
use App\Models\FeedGenerationSmokeCheck;
use App\Models\FeedProfile;
use App\Models\FeedReleaseEvent;
use App\Models\OpsRun;
use App\Models\Shop;
use App\Models\SourceImport;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class SloSummaryService
{
    /**
     * @return array<string, mixed>
     */
    public function summarize(?Shop $shop = null, ?FeedProfile $feedProfile = null, ?CarbonInterface $reference = null): array
    {
        $now = $reference?->copy() ?? now();
        $windows = [];

        foreach ((array) config('feed_mediator.reliability.history_windows_hours', [24, 168]) as $hours) {
            $windows[(string) $hours.'h'] = $this->windowSummary((int) $hours, $now, $shop, $feedProfile);
        }

        $latest = $windows['24h'] ?? reset($windows);

        return [
            'windows' => $windows,
            'status' => $latest['status'] ?? 'healthy',
            'recent_incidents' => FeedReleaseEvent::query()
                ->when($shop !== null, fn ($query) => $query->where('shop_id', $shop->id))
                ->when($feedProfile !== null, fn ($query) => $query->where('feed_profile_id', $feedProfile->id))
                ->whereIn('action', [
                    'publish_blocked',
                    'publish_failed',
                    'rolled_back',
                    'first_pull_verification_failed',
                    'rehearsal_failed',
                    'restore_drill_failed',
                ])
                ->latest('occurred_at')
                ->limit(10)
                ->get(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function windowSummary(int $hours, CarbonInterface $now, ?Shop $shop, ?FeedProfile $feedProfile): array
    {
        $from = $now->copy()->subHours($hours);
        $healthyThreshold = (float) config('feed_mediator.reliability.healthy_rate', 0.98);
        $warningThreshold = (float) config('feed_mediator.reliability.warning_rate', 0.90);
        $sync = $this->ratioSummary(
            SourceImport::query()
                ->when($shop !== null, fn ($query) => $query->where('shop_id', $shop->id))
                ->when($feedProfile !== null, fn ($query) => $query->where('source_connection_id', $feedProfile->source_connection_id))
                ->where('started_at', '>=', $from),
            fn ($query) => $query->where('status', SourceImport::STATUS_NORMALIZED)
        );
        $build = $this->ratioSummary(
            FeedGeneration::query()
                ->when($shop !== null, fn ($query) => $query->where('shop_id', $shop->id))
                ->when($feedProfile !== null, fn ($query) => $query->where('feed_profile_id', $feedProfile->id))
                ->where('created_at', '>=', $from),
            fn ($query) => $query->whereIn('status', [FeedGeneration::STATUS_BUILT, FeedGeneration::STATUS_PUBLISHED])
        );
        $publish = $this->ratioSummary(
            FeedReleaseEvent::query()
                ->when($shop !== null, fn ($query) => $query->where('shop_id', $shop->id))
                ->when($feedProfile !== null, fn ($query) => $query->where('feed_profile_id', $feedProfile->id))
                ->where('occurred_at', '>=', $from)
                ->whereIn('action', ['published', 'force_published', 'publish_failed']),
            fn ($query) => $query->whereIn('action', ['published', 'force_published'])
        );
        $smoke = $this->ratioSummary(
            FeedGenerationSmokeCheck::query()
                ->when($shop !== null, fn ($query) => $query->where('shop_id', $shop->id))
                ->when($feedProfile !== null, fn ($query) => $query->where('feed_profile_id', $feedProfile->id))
                ->where('checked_at', '>=', $from),
            fn ($query) => $query->whereIn('status', [
                FeedGenerationSmokeCheck::STATUS_OK,
                FeedGenerationSmokeCheck::STATUS_WARNING,
            ])
        );
        $firstPull = $this->ratioSummary(
            FeedFirstPullVerification::query()
                ->when($shop !== null, fn ($query) => $query->where('shop_id', $shop->id))
                ->when($feedProfile !== null, fn ($query) => $query->where('feed_profile_id', $feedProfile->id))
                ->where('verified_at', '>=', $from),
            fn ($query) => $query->whereIn('status', [
                FeedFirstPullVerification::STATUS_OK,
                FeedFirstPullVerification::STATUS_WARNING,
            ])
        );
        $rates = [$sync['rate'], $build['rate'], $publish['rate'], $smoke['rate'], $firstPull['rate']];
        $knownRates = array_values(array_filter($rates, static fn (?float $rate): bool => $rate !== null));
        $floor = $knownRates === [] ? 1.0 : min($knownRates);

        return [
            'hours' => $hours,
            'from' => $from->toIso8601String(),
            'to' => $now->toIso8601String(),
            'status' => $floor >= $healthyThreshold ? 'healthy' : ($floor >= $warningThreshold ? 'warning' : 'degraded'),
            'sync' => $sync,
            'build' => $build,
            'publish' => $publish,
            'smoke' => $smoke,
            'first_pull' => $firstPull,
            'recent_failures' => OpsRun::query()
                ->when($shop !== null, fn ($query) => $query->where('shop_id', $shop->id))
                ->when($feedProfile !== null, fn ($query) => $query->where('feed_profile_id', $feedProfile->id))
                ->where('started_at', '>=', $from)
                ->where('status', OpsRun::STATUS_FAILED)
                ->count(),
        ];
    }

    /**
     * @param  Builder<Model>  $baseQuery
     * @return array<string, int|float|null>
     */
    private function ratioSummary($baseQuery, callable $successConstraint): array
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
}
