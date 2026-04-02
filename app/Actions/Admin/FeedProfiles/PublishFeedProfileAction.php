<?php

namespace App\Actions\Admin\FeedProfiles;

use App\Models\FeedGeneration;
use App\Models\FeedProfile;
use App\Models\User;
use App\Services\Feeds\FeedReleaseService;

class PublishFeedProfileAction
{
    public function __construct(
        private readonly FeedReleaseService $feedReleaseService,
    ) {
    }

    public function handle(
        FeedProfile $feedProfile,
        ?FeedGeneration $generation = null,
        bool $force = false,
        ?string $reason = null,
        ?User $user = null
    ): FeedGeneration
    {
        return $this->feedReleaseService->publish($feedProfile, $generation, $force, $reason, $user);
    }
}
