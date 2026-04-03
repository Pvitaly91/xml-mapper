<?php

namespace App\Console\Commands;

use App\Models\OpsRun;
use App\Services\Ops\ProductionPreflightService;
use Illuminate\Console\Command;

class OpsPreflightProductionCommand extends Command
{
    protected $signature = 'ops:preflight-production';

    protected $description = 'Run production environment preflight checks for deploy and post-deploy validation.';

    public function handle(ProductionPreflightService $service): int
    {
        $result = $service->run();

        foreach ($result['checks'] as $check) {
            $this->line(sprintf('[%s] %s: %s', strtoupper($check['status']), $check['key'], $check['message']));
        }

        foreach ($result['next_steps'] as $step) {
            $this->warn('Next step: '.$step);
        }

        return $result['status'] === OpsRun::STATUS_FAILED ? self::FAILURE : self::SUCCESS;
    }
}
