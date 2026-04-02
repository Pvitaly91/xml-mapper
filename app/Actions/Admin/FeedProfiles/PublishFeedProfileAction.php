<?php

namespace App\Actions\Admin\FeedProfiles;

use App\Contracts\Feeds\FeedPublishServiceInterface;
use App\Models\FeedGeneration;
use App\Models\FeedProfile;

class PublishFeedProfileAction
{
    public function __construct(
        private readonly FeedPublishServiceInterface $feedPublishService,
    ) {
    }

    public function handle(FeedProfile $feedProfile, ?FeedGeneration $generation = null, bool $force = false): FeedGeneration
    {
        return $this->feedPublishService->publish($feedProfile, $generation, $force);
    }
}
