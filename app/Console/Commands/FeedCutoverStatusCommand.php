<?php

namespace App\Console\Commands;

use App\Models\FeedProfile;
use App\Services\Feeds\FeedCutoverService;
use Illuminate\Console\Command;

class FeedCutoverStatusCommand extends Command
{
    protected $signature = 'feed:cutover-status {feedProfileId : Feed profile ID}';

    protected $description = 'Show current cutover status and blocking issues for a feed profile.';

    public function handle(FeedCutoverService $cutoverService): int
    {
        $feedProfile = FeedProfile::findOrFail((int) $this->argument('feedProfileId'));
        $summary = $cutoverService->summarize($feedProfile, $feedProfile->latestGeneration);

        $this->info('Cutover status: '.($summary['cutover']?->status ?? 'n/a'));

        foreach ($summary['blocking_issues'] as $issue) {
            $this->line('BLOCK: '.$issue);
        }

        foreach ($summary['warnings'] as $warning) {
            $this->line('WARN: '.$warning);
        }

        return self::SUCCESS;
    }
}
