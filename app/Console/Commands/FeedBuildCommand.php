<?php

namespace App\Console\Commands;

use App\Jobs\BuildFeedJob;
use App\Models\FeedProfile;
use Illuminate\Console\Command;

class FeedBuildCommand extends Command
{
    protected $signature = 'feed:build {feedProfileId? : Feed profile ID} {--due : Build all due active feeds} {--publish : Publish after build} {--queue : Dispatch to queue instead of sync execution}';

    protected $description = 'Build cached XML feed file.';

    public function handle(): int
    {
        $profiles = $this->resolveProfiles();

        if ($profiles->isEmpty()) {
            $this->warn('No feed profiles found for build.');

            return self::SUCCESS;
        }

        foreach ($profiles as $profile) {
            if ($this->option('queue')) {
                BuildFeedJob::dispatch($profile->id, (bool) $this->option('publish'));
                $this->line("Queued feed build for profile #{$profile->id}.");
            } else {
                BuildFeedJob::dispatchSync($profile->id, (bool) $this->option('publish'));
                $this->line("Built feed profile #{$profile->id}.");
            }
        }

        return self::SUCCESS;
    }

    private function resolveProfiles()
    {
        return FeedProfile::query()
            ->when($this->argument('feedProfileId'), fn ($query, $id) => $query->whereKey($id))
            ->when($this->option('due'), fn ($query) => $query->where('status', FeedProfile::STATUS_ACTIVE)->where(function ($innerQuery): void {
                $innerQuery->whereNull('next_build_at')->orWhere('next_build_at', '<=', now());
            }))
            ->orderBy('id')
            ->get();
    }
}
