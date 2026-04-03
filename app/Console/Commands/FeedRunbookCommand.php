<?php

namespace App\Console\Commands;

use App\Models\FeedProfile;
use App\Services\Feeds\FeedRunbookService;
use Illuminate\Console\Command;

class FeedRunbookCommand extends Command
{
    protected $signature = 'feed:runbook {feedProfileId : Feed profile ID}';

    protected $description = 'Generate and print the runbook path for a feed profile.';

    public function handle(FeedRunbookService $service): int
    {
        $feedProfile = FeedProfile::findOrFail((int) $this->argument('feedProfileId'));
        $runbook = $service->generate($feedProfile, $feedProfile->latestGeneration);

        $this->line($runbook['absolute_path']);
        $this->info('Runbook generated.');

        return self::SUCCESS;
    }
}
