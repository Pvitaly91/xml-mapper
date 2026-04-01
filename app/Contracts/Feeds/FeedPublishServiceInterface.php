<?php

namespace App\Contracts\Feeds;

use App\Models\FeedGeneration;
use App\Models\FeedProfile;

interface FeedPublishServiceInterface
{
    public function publish(FeedProfile $feedProfile, ?FeedGeneration $generation = null): FeedGeneration;
}
