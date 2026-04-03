<?php

namespace App\Console\Commands;

use App\Models\FeedProfile;
use App\Services\Feeds\FeedHypercareService;
use Illuminate\Console\Command;

class FeedHypercareCloseCommand extends Command
{
    protected $signature = 'feed:hypercare:close {feedProfileId : Feed profile ID} {--reason= : Closeout reason}';

    protected $description = 'Close the active hypercare window for a feed profile.';

    public function handle(FeedHypercareService $service): int
    {
        $feedProfile = FeedProfile::findOrFail((int) $this->argument('feedProfileId'));
        $window = $service->current($feedProfile);

        if ($window === null) {
            $this->error('No active hypercare window found.');

            return self::FAILURE;
        }

        $result = $service->close(
            $window,
            $this->option('reason') !== null ? (string) $this->option('reason') : 'Closed from CLI.'
        );

        $this->info('Hypercare closed. Report: '.$result['report']['absolute_path']);

        return self::SUCCESS;
    }
}
