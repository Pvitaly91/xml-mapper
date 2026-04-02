<?php

namespace App\Data\Ops;

use App\Models\FeedGeneration;
use App\Models\FeedProfile;

readonly class DueFeedPublishCandidate
{
    public function __construct(
        public FeedProfile $feedProfile,
        public FeedGeneration $generation,
        public string $reason,
    ) {
    }
}
