<?php

namespace App\Jobs;

use App\Contracts\Feeds\FeedPublishServiceInterface;
use App\Models\FeedGeneration;
use App\Models\FeedProfile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PublishFeedJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $feedProfileId,
        public readonly ?int $feedGenerationId = null,
    ) {
    }

    public function handle(FeedPublishServiceInterface $feedPublishService): void
    {
        $feedProfile = FeedProfile::findOrFail($this->feedProfileId);
        $generation = $this->feedGenerationId ? FeedGeneration::findOrFail($this->feedGenerationId) : null;

        $feedPublishService->publish($feedProfile, $generation);
    }
}
