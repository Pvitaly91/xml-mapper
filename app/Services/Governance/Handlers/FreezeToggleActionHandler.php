<?php

namespace App\Services\Governance\Handlers;

use App\Contracts\Governance\GovernedActionHandler;
use App\Models\ApprovalRequest;
use App\Models\FeedProfile;
use App\Models\User;
use App\Services\Feeds\FeedPublishWindowService;

class FreezeToggleActionHandler implements GovernedActionHandler
{
    public function __construct(
        private readonly FeedPublishWindowService $publishWindowService,
    ) {}

    public function execute(array $payload, ?User $actor = null, ?ApprovalRequest $approvalRequest = null): array
    {
        $feedProfile = FeedProfile::query()->findOrFail((int) $payload['feed_profile_id']);
        $feedProfile = $this->publishWindowService->setFreezeMode(
            $feedProfile,
            (bool) ($payload['freeze'] ?? false),
            (string) ($payload['reason'] ?? ''),
            $actor
        );

        return [
            'feed_profile_id' => $feedProfile->id,
            'freeze_mode' => $feedProfile->freezeModeActive(),
        ];
    }
}
