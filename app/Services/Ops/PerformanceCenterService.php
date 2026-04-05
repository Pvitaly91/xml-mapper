<?php

namespace App\Services\Ops;

use App\Models\FeedProfile;
use App\Models\PerformanceRun;
use App\Models\Shop;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class PerformanceCenterService
{
    public function __construct(
        private readonly PerformanceRunService $runService,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function runs(?Shop $shop = null, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        return PerformanceRun::query()
            ->with(['shop', 'feedProfile', 'user', 'stageRuns'])
            ->when($shop !== null, fn ($query) => $query->where('shop_id', $shop->id))
            ->when(filled($filters['run_type'] ?? null), fn ($query) => $query->where('run_type', $filters['run_type']))
            ->when(filled($filters['status'] ?? null), fn ($query) => $query->where('status', $filters['status']))
            ->when(filled($filters['budget_status'] ?? null), fn ($query) => $query->where('budget_status', $filters['budget_status']))
            ->when(filled($filters['feed_profile_id'] ?? null), fn ($query) => $query->where('feed_profile_id', (int) $filters['feed_profile_id']))
            ->latest('started_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(?Shop $shop = null, ?FeedProfile $feedProfile = null): array
    {
        $latest = $this->runService->latest($shop, $feedProfile);
        $compare = $this->runService->compareRecent($shop, $feedProfile);

        return [
            'latest' => $latest,
            'compare' => $compare,
            'critical_count' => PerformanceRun::query()
                ->when($shop !== null, fn ($query) => $query->where('shop_id', $shop->id))
                ->where('budget_status', PerformanceRun::BUDGET_CRITICAL)
                ->where('started_at', '>=', now()->subDays(7))
                ->count(),
            'warning_count' => PerformanceRun::query()
                ->when($shop !== null, fn ($query) => $query->where('shop_id', $shop->id))
                ->where('budget_status', PerformanceRun::BUDGET_WARNING)
                ->where('started_at', '>=', now()->subDays(7))
                ->count(),
        ];
    }
}
