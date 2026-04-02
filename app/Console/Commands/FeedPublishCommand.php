<?php

namespace App\Console\Commands;

use App\Actions\Ops\ResolveDueFeedPublishesAction;
use App\Jobs\PublishFeedJob;
use App\Models\FeedProfile;
use App\Services\Ops\ProcessLockService;
use Illuminate\Console\Command;

class FeedPublishCommand extends Command
{
    protected $signature = 'feed:publish {feedProfileId? : Feed profile ID} {--due : Publish all due active feeds} {--generation= : Optional generation ID} {--queue : Dispatch to queue instead of sync execution}';

    protected $description = 'Publish built XML feed into public cached path.';

    public function handle(ResolveDueFeedPublishesAction $resolveDueFeedPublishes, ProcessLockService $lockService): int
    {
        if ($this->option('due')) {
            $candidates = $resolveDueFeedPublishes->handle(null, $this->argument('feedProfileId') ? (int) $this->argument('feedProfileId') : null);

            if ($candidates->isEmpty()) {
                $this->warn('No feed profiles found for due publish.');

                return self::SUCCESS;
            }

            foreach ($candidates as $candidate) {
                if ($this->option('queue')) {
                    $dispatchOwner = $lockService->acquireDispatchLock(
                        $lockService->feedPublishProfileKey($candidate->feedProfile->id),
                        (int) config('feed_mediator.locks.dispatch_ttl_seconds')
                    );

                    if ($dispatchOwner === null) {
                        $this->warn("Skipped publish for feed profile #{$candidate->feedProfile->id}: already queued.");

                        continue;
                    }

                    PublishFeedJob::dispatch($candidate->feedProfile->id, $candidate->generation->id, true, $dispatchOwner);
                    $this->line("Queued due publish for feed profile #{$candidate->feedProfile->id} ({$candidate->reason}).");

                    continue;
                }

                PublishFeedJob::dispatchSync($candidate->feedProfile->id, $candidate->generation->id, true);
                $this->line("Published feed profile #{$candidate->feedProfile->id} ({$candidate->reason}).");
            }

            return self::SUCCESS;
        }

        $feedProfileId = $this->argument('feedProfileId');

        if ($feedProfileId === null) {
            $this->error('The feedProfileId argument is required unless --due is used.');

            return self::INVALID;
        }

        $profile = FeedProfile::findOrFail((int) $feedProfileId);
        $generationId = $this->option('generation') ? (int) $this->option('generation') : null;

        if ($this->option('queue')) {
            $dispatchOwner = $lockService->acquireDispatchLock(
                $lockService->feedPublishProfileKey($profile->id),
                (int) config('feed_mediator.locks.dispatch_ttl_seconds')
            );

            if ($dispatchOwner === null) {
                $this->warn("Skipped publish for feed profile #{$profile->id}: already queued.");

                return self::SUCCESS;
            }

            PublishFeedJob::dispatch($profile->id, $generationId, false, $dispatchOwner);
            $this->line("Queued publish for feed profile #{$profile->id}.");
        } else {
            PublishFeedJob::dispatchSync($profile->id, $generationId);
            $this->line("Published feed profile #{$profile->id}.");
        }

        return self::SUCCESS;
    }
}
