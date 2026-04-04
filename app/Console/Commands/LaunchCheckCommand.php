<?php

namespace App\Console\Commands;

use App\Models\MerchantLaunch;
use App\Services\Launch\MerchantLaunchService;
use Illuminate\Console\Command;

class LaunchCheckCommand extends Command
{
    protected $signature = 'launch:check {launchId : Merchant launch ID}';

    protected $description = 'Return a compact live-launch operational check summary.';

    public function handle(MerchantLaunchService $service): int
    {
        $launch = MerchantLaunch::findOrFail((int) $this->argument('launchId'));

        $this->line(json_encode($service->check($launch), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
