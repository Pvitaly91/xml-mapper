<?php

namespace App\Services\Governance\Handlers;

use App\Contracts\Governance\GovernedActionHandler;
use App\Models\ApprovalRequest;
use App\Models\MerchantLaunch;
use App\Models\User;
use App\Services\Launch\MerchantLaunchService;

class LaunchCloseOverrideActionHandler implements GovernedActionHandler
{
    public function __construct(
        private readonly MerchantLaunchService $launchService,
    ) {}

    public function execute(array $payload, ?User $actor = null, ?ApprovalRequest $approvalRequest = null): array
    {
        $launch = MerchantLaunch::query()->findOrFail((int) $payload['merchant_launch_id']);
        $launch = $this->launchService->close(
            $launch,
            (string) ($payload['reason'] ?? ''),
            $actor,
            true
        );

        return [
            'merchant_launch_id' => $launch->id,
            'state' => $launch->state,
        ];
    }
}
