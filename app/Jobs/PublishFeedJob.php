<?php

namespace App\Jobs;

use App\Actions\Ops\ResolveDueFeedPublishesAction;
use App\Models\FeedGeneration;
use App\Models\FeedProfile;
use App\Services\Ops\ProcessLockService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PublishFeedJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

    public int $tries = 3;

    public int $maxExceptions = 2;

    public function __construct(
        public readonly int $feedProfileId,
        public readonly ?int $feedGenerationId = null,
        public readonly bool $onlyIfDue = false,
        public readonly bool $force = false,
        public readonly ?string $reason = null,
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
        \App\Services\Feeds\FeedReleaseService $feedReleaseService,
        ResolveDueFeedPublishesAction $resolveDueFeedPublishes,
        ProcessLockService $lockService,
    ): void
    {
        try {
            $feedProfile = FeedProfile::findOrFail($this->feedProfileId);
            $generation = $this->feedGenerationId ? FeedGeneration::findOrFail($this->feedGenerationId) : null;
            $reason = $this->reason;

            if ($this->onlyIfDue) {
                $candidate = $resolveDueFeedPublishes->candidateForProfile($feedProfile);

                if ($candidate === null) {
                    return;
                }

                $generation = $candidate->generation;
                $reason ??= 'Due publish: '.$candidate->reason;
            }

            $feedReleaseService->publish($feedProfile, $generation, $this->force, $reason);
        } finally {
            $lockService->releaseDispatchLock($lockService->feedPublishProfileKey($this->feedProfileId), $this->dispatchLockOwner);
        }
    }
}
