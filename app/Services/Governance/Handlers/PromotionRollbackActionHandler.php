<?php

namespace App\Services\Governance\Handlers;

use App\Contracts\Governance\GovernedActionHandler;
use App\Models\ApprovalRequest;
use App\Models\PromotionRun;
use App\Models\User;
use App\Services\Promotion\PromotionService;

class PromotionRollbackActionHandler implements GovernedActionHandler
{
    public function __construct(
        private readonly PromotionService $promotionService,
    ) {}

    public function execute(array $payload, ?User $actor = null, ?ApprovalRequest $approvalRequest = null): array
    {
        $promotionRun = PromotionRun::query()->findOrFail((int) $payload['promotion_run_id']);
        $run = $this->promotionService->rollback(
            $promotionRun,
            $actor,
            (string) ($payload['reason'] ?? '')
        );

        return [
            'promotion_run_id' => $run->id,
            'status' => $run->status,
            'target_feed_profile_id' => $run->target_feed_profile_id,
        ];
    }
}
