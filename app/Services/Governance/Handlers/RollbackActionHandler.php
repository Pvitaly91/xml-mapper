<?php

namespace App\Services\Governance\Handlers;

use App\Contracts\Governance\GovernedActionHandler;
use App\Models\ApprovalRequest;
use App\Models\FeedGeneration;
use App\Models\FeedProfile;
use App\Models\User;
use App\Services\Feeds\FeedReleaseService;

class RollbackActionHandler implements GovernedActionHandler
{
    public function __construct(
        private readonly FeedReleaseService $releaseService,
    ) {}

    public function execute(array $payload, ?User $actor = null, ?ApprovalRequest $approvalRequest = null): array
    {
        $feedProfile = FeedProfile::query()->findOrFail((int) $payload['feed_profile_id']);
        $targetGeneration = ! empty($payload['to_generation_id'])
            ? FeedGeneration::query()->where('feed_profile_id', $feedProfile->id)->findOrFail((int) $payload['to_generation_id'])
            : null;
        $rolledBack = $this->releaseService->rollback(
            $feedProfile,
            $targetGeneration,
            (string) ($payload['reason'] ?? ''),
            $actor
        );

        return [
            'feed_profile_id' => $feedProfile->id,
            'generation_id' => $rolledBack->id,
            'status' => $rolledBack->status,
        ];
    }
}
