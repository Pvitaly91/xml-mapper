<?php

namespace App\Services\Governance\Handlers;

use App\Contracts\Governance\GovernedActionHandler;
use App\Models\ApprovalRequest;
use App\Models\FeedProfile;
use App\Models\PromotionRun;
use App\Models\PromotionSnapshot;
use App\Models\User;
use App\Services\Promotion\PromotionService;

class PromotionApplyActionHandler implements GovernedActionHandler
{
    public function __construct(
        private readonly PromotionService $promotionService,
    ) {}

    public function execute(array $payload, ?User $actor = null, ?ApprovalRequest $approvalRequest = null): array
    {
        $feedProfile = FeedProfile::query()->findOrFail((int) $payload['feed_profile_id']);
        $snapshot = PromotionSnapshot::query()->findOrFail((int) $payload['source_snapshot_id']);
        $run = $this->promotionService->applySnapshot(
            $snapshot,
            $feedProfile,
            (string) ($payload['strategy'] ?? PromotionRun::STRATEGY_SAFE_MERGE),
            $actor,
            (string) ($payload['reason'] ?? '')
        );

        return [
            'promotion_run_id' => $run->id,
            'status' => $run->status,
            'feed_profile_id' => $feedProfile->id,
        ];
    }
}
