<?php

namespace App\Console\Commands;

use App\Models\FeedGeneration;
use App\Models\FeedProfile;
use App\Services\Feeds\FeedReleaseService;
use Illuminate\Console\Command;

class FeedRollbackCommand extends Command
{
    protected $signature = 'feed:rollback {feedProfileId : Feed profile ID} {--to-generation= : Roll back to a specific generation ID} {--reason= : Required rollback reason}';

    protected $description = 'Roll back the published feed to a previous generation.';

    public function handle(FeedReleaseService $releaseService): int
    {
        $reason = $this->option('reason') !== null ? (string) $this->option('reason') : null;

        if (blank($reason)) {
            $this->error('The --reason option is required for rollback.');

            return self::INVALID;
        }

        $feedProfile = FeedProfile::findOrFail((int) $this->argument('feedProfileId'));
        $targetGeneration = $this->option('to-generation')
            ? FeedGeneration::query()
                ->where('feed_profile_id', $feedProfile->id)
                ->findOrFail((int) $this->option('to-generation'))
            : null;

        $rolledBack = $releaseService->rollback($feedProfile, $targetGeneration, $reason);

        $this->info('Feed rolled back to generation #'.$rolledBack->id.'.');

        return self::SUCCESS;
    }
}
