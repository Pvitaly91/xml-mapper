<?php

namespace App\Services\Promotion;

use App\Models\FeedProfile;
use App\Models\PromotionRun;
use App\Models\PromotionSnapshot;
use App\Services\Ops\EnvironmentContextService;

class PromotionCenterService
{
    public function __construct(
        private readonly PromotionStatusService $statusService,
        private readonly EnvironmentContextService $environmentContextService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function summarize(FeedProfile $feedProfile): array
    {
        return [
            'environment' => $this->environmentContextService->summary(),
            'status' => $this->statusService->summarize($feedProfile),
            'snapshots' => PromotionSnapshot::query()
                ->where(function ($query) use ($feedProfile): void {
                    $query->where('feed_profile_id', $feedProfile->id)
                        ->orWhere('shop_id', $feedProfile->shop_id);
                })
                ->latest('generated_at')
                ->latest('id')
                ->limit(30)
                ->get(),
            'runs' => PromotionRun::query()
                ->with(['sourceSnapshot', 'targetSnapshot', 'resultSnapshot', 'user'])
                ->where(function ($query) use ($feedProfile): void {
                    $query->where('target_feed_profile_id', $feedProfile->id)
                        ->orWhere('source_feed_profile_id', $feedProfile->id);
                })
                ->latest('started_at')
                ->latest('id')
                ->limit(30)
                ->get(),
        ];
    }
}
