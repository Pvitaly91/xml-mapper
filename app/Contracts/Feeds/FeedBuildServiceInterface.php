<?php

namespace App\Contracts\Feeds;

use App\Models\FeedGeneration;
use App\Models\FeedProfile;

interface FeedBuildServiceInterface
{
    public function build(FeedProfile $feedProfile): FeedGeneration;
}
