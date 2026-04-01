<?php

namespace App\Jobs;

use App\Contracts\Feeds\FeedBuildServiceInterface;
use App\Models\FeedProfile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class BuildFeedJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $feedProfileId,
        public readonly bool $publishAfterBuild = false,
        public readonly ?int $sourceImportId = null,
    ) {
    }

    public function handle(FeedBuildServiceInterface $feedBuildService): void
    {
        $feedProfile = FeedProfile::findOrFail($this->feedProfileId);
        $generation = $feedBuildService->build($feedProfile);

        if ($this->sourceImportId !== null) {
            $generation->update(['source_import_id' => $this->sourceImportId]);
        }

        if ($this->publishAfterBuild) {
            PublishFeedJob::dispatch($feedProfile->id, $generation->id);
        }
    }
}
