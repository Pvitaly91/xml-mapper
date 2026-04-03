<?php

namespace App\Actions\Admin\FeedProfiles;

use App\Contracts\Feeds\FeedBuildServiceInterface;
use App\Models\FeedGeneration;
use App\Models\FeedProfile;

class BuildFeedProfileAction
{
    public function __construct(
        private readonly FeedBuildServiceInterface $feedBuildService,
    ) {}

    public function handle(FeedProfile $feedProfile): FeedGeneration
    {
        return $this->feedBuildService->build($feedProfile);
    }
}
