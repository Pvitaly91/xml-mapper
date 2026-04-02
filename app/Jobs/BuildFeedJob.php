<?php

namespace App\Jobs;

use App\Actions\Ops\ResolveDueFeedBuildsAction;
use App\Contracts\Feeds\FeedBuildServiceInterface;
use App\Models\FeedProfile;
use App\Services\Ops\ProcessLockService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class BuildFeedJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public int $tries = 3;

    public int $maxExceptions = 2;

    public function __construct(
        public readonly int $feedProfileId,
        public readonly bool $publishAfterBuild = false,
        public readonly ?int $sourceImportId = null,
        public readonly bool $onlyIfDue = false,
        public readonly ?string $dispatchLockOwner = null,
    ) {
        $this->onQueue((string) config('feed_mediator.queues.feeds'));
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(
        FeedBuildServiceInterface $feedBuildService,
        ResolveDueFeedBuildsAction $resolveDueFeedBuilds,
        ProcessLockService $lockService,
    ): void
    {
        try {
            $feedProfile = FeedProfile::findOrFail($this->feedProfileId);

            if ($this->onlyIfDue && ! $resolveDueFeedBuilds->handle(null, $feedProfile->id)->contains('id', $feedProfile->id)) {
                return;
            }

            $generation = $feedBuildService->build($feedProfile, $this->sourceImportId);

            if ($this->publishAfterBuild) {
                PublishFeedJob::dispatch($feedProfile->id, $generation->id);
            }
        } finally {
            $lockService->releaseDispatchLock($lockService->feedBuildKey($this->feedProfileId), $this->dispatchLockOwner);
        }
    }
}
