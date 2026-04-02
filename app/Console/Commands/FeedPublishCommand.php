<?php

namespace App\Console\Commands;

use App\Actions\Ops\ResolveDueFeedPublishesAction;
use App\Jobs\PublishFeedJob;
use App\Models\FeedGeneration;
use App\Models\FeedProfile;
use App\Services\Ops\ProcessLockService;
use Illuminate\Console\Command;

class FeedPublishCommand extends Command
{
    protected $signature = 'feed:publish
        {feedProfileId? : Feed profile ID}
        {generationId? : Optional generation ID}
        {--due : Publish all due active feeds}
        {--force : Force publish even if readiness blocks it}
        {--reason= : Reason for force publish or override}
        {--queue : Dispatch to queue instead of sync execution}';

    protected $description = 'Publish built XML feed into public cached path.';

    public function handle(ResolveDueFeedPublishesAction $resolveDueFeedPublishes, ProcessLockService $lockService): int
    {
        $force = (bool) $this->option('force');
        $reason = $this->option('reason') !== null ? (string) $this->option('reason') : null;

        if ($force && blank($reason)) {
            $this->error('The --reason option is required when --force is used.');

            return self::INVALID;
        }

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

                    PublishFeedJob::dispatch($candidate->feedProfile->id, $candidate->generation->id, true, false, null, $dispatchOwner);
                    $this->line("Queued due publish for feed profile #{$candidate->feedProfile->id} ({$candidate->reason}).");

                    continue;
                }

                PublishFeedJob::dispatchSync($candidate->feedProfile->id, $candidate->generation->id, true, false, null);
                $this->line("Published feed profile #{$candidate->feedProfile->id} ({$candidate->reason}).");
            }

            return self::SUCCESS;
        }

        $feedProfileId = $this->argument('feedProfileId');
        $generationId = $this->argument('generationId') ? (int) $this->argument('generationId') : null;

        if ($feedProfileId === null && $generationId === null) {
            $this->error('Provide feedProfileId or generationId unless --due is used.');

            return self::INVALID;
        }

        if ($generationId !== null) {
            $generation = FeedGeneration::findOrFail($generationId);
            $profile = $feedProfileId !== null
                ? FeedProfile::findOrFail((int) $feedProfileId)
                : $generation->feedProfile()->firstOrFail();

            if ($generation->feed_profile_id !== $profile->id) {
                $this->error('Selected generation does not belong to the selected feed profile.');

                return self::INVALID;
            }
        } else {
            $profile = FeedProfile::findOrFail((int) $feedProfileId);
        }

        if ($this->option('queue')) {
            $dispatchOwner = $lockService->acquireDispatchLock(
                $lockService->feedPublishProfileKey($profile->id),
                (int) config('feed_mediator.locks.dispatch_ttl_seconds')
            );

            if ($dispatchOwner === null) {
                $this->warn("Skipped publish for feed profile #{$profile->id}: already queued.");

                return self::SUCCESS;
            }

            PublishFeedJob::dispatch($profile->id, $generationId, false, $force, $reason, $dispatchOwner);
            $this->line("Queued publish for feed profile #{$profile->id}.");
        } else {
            PublishFeedJob::dispatchSync($profile->id, $generationId, false, $force, $reason);
            $this->line("Published feed profile #{$profile->id}.");
        }

        return self::SUCCESS;
    }
}
