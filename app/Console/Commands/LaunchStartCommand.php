<?php

namespace App\Console\Commands;

use App\Models\FeedProfile;
use App\Services\Launch\MerchantLaunchService;
use Illuminate\Console\Command;

class LaunchStartCommand extends Command
{
    protected $signature = 'launch:start {feedProfileId : Feed profile ID} {--pilot= : Pilot run ID} {--promotion= : Promotion run ID} {--note= : Launch note}';

    protected $description = 'Start a live merchant launch record for the given feed profile.';

    public function handle(MerchantLaunchService $service): int
    {
        $feedProfile = FeedProfile::findOrFail((int) $this->argument('feedProfileId'));
        $launch = $service->start($feedProfile, [
            'pilot_run_id' => $this->option('pilot'),
            'promotion_run_id' => $this->option('promotion'),
            'note' => $this->option('note'),
        ]);

        $this->line(json_encode($service->check($launch), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
