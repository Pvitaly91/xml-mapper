<?php

namespace App\Services\Governance\Handlers;

use App\Contracts\Governance\GovernedActionHandler;
use App\Models\ApprovalRequest;
use App\Models\FeedGeneration;
use App\Models\FeedProfile;
use App\Models\User;
use App\Services\Feeds\FeedReleaseService;

class ForcePublishActionHandler implements GovernedActionHandler
{
    public function __construct(
        private readonly FeedReleaseService $releaseService,
    ) {}

    public function execute(array $payload, ?User $actor = null, ?ApprovalRequest $approvalRequest = null): array
    {
        $feedProfile = FeedProfile::query()->findOrFail((int) $payload['feed_profile_id']);
        $generation = ! empty($payload['generation_id'])
            ? FeedGeneration::query()->where('feed_profile_id', $feedProfile->id)->findOrFail((int) $payload['generation_id'])
            : null;
        $published = $this->releaseService->publish(
            $feedProfile,
            $generation,
            true,
            (string) ($payload['reason'] ?? ''),
            $actor
        );

        return [
            'feed_profile_id' => $feedProfile->id,
            'generation_id' => $published->id,
            'status' => $published->status,
        ];
    }
}
