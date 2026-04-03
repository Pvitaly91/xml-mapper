<?php

namespace App\Console\Commands;

use App\Models\FeedGeneration;
use App\Models\FeedProfile;
use App\Services\Feeds\FeedFirstPullVerificationService;
use Illuminate\Console\Command;

class FeedFirstPullVerifyCommand extends Command
{
    protected $signature = 'feed:first-pull-verify
        {feedProfileId : Feed profile ID}
        {--generation= : Published generation ID override}
        {--reason= : Optional verification note}';

    protected $description = 'Run first-pull verification for the currently published generation.';

    public function handle(FeedFirstPullVerificationService $service): int
    {
        $feedProfile = FeedProfile::findOrFail((int) $this->argument('feedProfileId'));
        $generation = $this->option('generation') !== null
            ? FeedGeneration::query()->where('feed_profile_id', $feedProfile->id)->findOrFail((int) $this->option('generation'))
            : $feedProfile->publishedGeneration;

        $verification = $service->run(
            $feedProfile,
            $generation,
            'command',
            null,
            $this->option('reason') !== null ? (string) $this->option('reason') : null
        );

        $this->info('First-pull verification finished with status '.$verification->status.'.');

        return self::SUCCESS;
    }
}
