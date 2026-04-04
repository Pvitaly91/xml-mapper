<?php

namespace App\Console\Commands;

use App\Models\MerchantLaunch;
use App\Services\Launch\MerchantLaunchService;
use Illuminate\Console\Command;

class LaunchCloseCommand extends Command
{
    protected $signature = 'launch:close {launchId : Merchant launch ID} {--reason= : Close reason}';

    protected $description = 'Close a live merchant launch record when safe.';

    public function handle(MerchantLaunchService $service): int
    {
        $launch = MerchantLaunch::findOrFail((int) $this->argument('launchId'));
        $updated = $service->close($launch, (string) $this->option('reason'));

        $this->line(json_encode($service->check($updated), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
