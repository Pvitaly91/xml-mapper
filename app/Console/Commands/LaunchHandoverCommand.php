<?php

namespace App\Console\Commands;

use App\Models\MerchantLaunch;
use App\Services\Launch\MerchantLaunchService;
use Illuminate\Console\Command;

class LaunchHandoverCommand extends Command
{
    protected $signature = 'launch:handover {launchId : Merchant launch ID} {--reason= : Handover reason}';

    protected $description = 'Mark a live merchant launch ready and handed over.';

    public function handle(MerchantLaunchService $service): int
    {
        $launch = MerchantLaunch::findOrFail((int) $this->argument('launchId'));
        $updated = $service->handover($launch, (string) $this->option('reason'));

        $this->line(json_encode($service->check($updated), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
