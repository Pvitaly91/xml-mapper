<?php

namespace App\Console\Commands;

use App\Actions\Ops\ResolveDueFeedBuildsAction;
use App\Jobs\BuildFeedJob;
use App\Models\FeedProfile;
use App\Services\Ops\ProcessLockService;
use Illuminate\Console\Command;

class FeedBuildCommand extends Command
{
    protected $signature = 'feed:build {feedProfileId? : Feed profile ID} {--due : Build all due active feeds} {--publish : Publish after build} {--queue : Dispatch to queue instead of sync execution}';

    protected $description = 'Build cached XML feed file.';

    public function handle(ResolveDueFeedBuildsAction $resolveDueFeedBuilds, ProcessLockService $lockService): int
    {
        $profiles = $this->resolveProfiles($resolveDueFeedBuilds);

        if ($profiles->isEmpty()) {
            $this->warn('No feed profiles found for build.');

            return self::SUCCESS;
        }

        foreach ($profiles as $profile) {
            if ($this->option('queue')) {
                $dispatchOwner = $lockService->acquireDispatchLock(
                    $lockService->feedBuildKey($profile->id),
                    (int) config('feed_mediator.locks.dispatch_ttl_seconds')
                );

                if ($dispatchOwner === null) {
                    $this->warn("Skipped feed build for profile #{$profile->id}: already queued.");

                    continue;
                }

                BuildFeedJob::dispatch($profile->id, (bool) $this->option('publish'), null, (bool) $this->option('due'), $dispatchOwner);
                $this->line("Queued feed build for profile #{$profile->id}.");
            } else {
                BuildFeedJob::dispatchSync($profile->id, (bool) $this->option('publish'), null, (bool) $this->option('due'));
                $this->line("Built feed profile #{$profile->id}.");
            }
        }

        return self::SUCCESS;
    }

    private function resolveProfiles(ResolveDueFeedBuildsAction $resolveDueFeedBuilds)
    {
        if ($this->option('due')) {
            return $resolveDueFeedBuilds->handle(null, $this->argument('feedProfileId') ? (int) $this->argument('feedProfileId') : null);
        }

        return FeedProfile::query()
            ->when($this->argument('feedProfileId'), fn ($query, $id) => $query->whereKey($id))
            ->orderBy('id')
            ->get();
    }
}
