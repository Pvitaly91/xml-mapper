<?php

namespace App\Console\Commands;

use App\Models\MerchantLaunch;
use App\Services\Launch\MerchantLaunchService;
use Illuminate\Console\Command;

class LaunchStatusCommand extends Command
{
    protected $signature = 'launch:status {launchId : Merchant launch ID}';

    protected $description = 'Show live merchant launch status.';

    public function handle(MerchantLaunchService $service): int
    {
        $launch = MerchantLaunch::findOrFail((int) $this->argument('launchId'));
        $snapshot = $service->snapshot($launch);

        $this->line(json_encode([
            'launch_id' => $snapshot['launch']->id,
            'state' => $snapshot['launch']->state,
            'handover_state' => $snapshot['launch']->handover_state,
            'blockers' => $snapshot['blockers'],
            'next_actions' => $snapshot['next_actions'],
            'baseline' => $snapshot['baseline'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $snapshot['launch']->state === 'failed' ? self::FAILURE : self::SUCCESS;
    }
}
