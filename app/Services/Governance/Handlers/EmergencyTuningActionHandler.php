<?php

namespace App\Services\Governance\Handlers;

use App\Contracts\Governance\GovernedActionHandler;
use App\Models\ApprovalRequest;
use App\Models\MerchantLaunch;
use App\Models\User;
use App\Services\Launch\MerchantLaunchService;

class EmergencyTuningActionHandler implements GovernedActionHandler
{
    public function __construct(
        private readonly MerchantLaunchService $launchService,
    ) {}

    public function execute(array $payload, ?User $actor = null, ?ApprovalRequest $approvalRequest = null): array
    {
        $launch = MerchantLaunch::query()->findOrFail((int) $payload['merchant_launch_id']);
        $action = $this->launchService->applyTuning($launch, (array) ($payload['tuning_payload'] ?? []), $actor);

        return [
            'merchant_launch_id' => $launch->id,
            'tuning_action_id' => $action->id,
            'mode' => $action->mode,
        ];
    }
}
