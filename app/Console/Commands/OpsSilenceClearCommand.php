<?php

namespace App\Console\Commands;

use App\Models\FeedProfile;
use App\Services\Ops\SilenceWindowService;
use Illuminate\Console\Command;

class OpsSilenceClearCommand extends Command
{
    protected $signature = 'ops:silence:clear {feedProfileId : Feed profile ID}';

    protected $description = 'Clear all active silence windows for a feed profile.';

    public function handle(SilenceWindowService $service): int
    {
        $feedProfile = FeedProfile::findOrFail((int) $this->argument('feedProfileId'));
        $cleared = $service->clear($feedProfile, null, 'Cleared from CLI.');

        $this->info('Silence windows cleared: '.$cleared);

        return self::SUCCESS;
    }
}
