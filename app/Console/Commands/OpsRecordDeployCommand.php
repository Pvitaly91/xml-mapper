<?php

namespace App\Console\Commands;

use App\Models\OpsRun;
use App\Services\Ops\OpsRunService;
use Illuminate\Console\Command;

class OpsRecordDeployCommand extends Command
{
    protected $signature = 'ops:record-deploy
        {action : deploy or rollback}
        {release : Release identifier}
        {--revision= : Git revision or tag}
        {--status=succeeded : succeeded, warning, or failed}
        {--note= : Optional note}';

    protected $description = 'Persist deploy or rollback metadata for the operations dashboard.';

    public function handle(OpsRunService $opsRunService): int
    {
        $action = (string) $this->argument('action');
        $type = $action === 'rollback' ? OpsRun::TYPE_ROLLBACK : OpsRun::TYPE_DEPLOY;
        $status = (string) $this->option('status');
        $run = $opsRunService->start($type, meta: [
            'release' => (string) $this->argument('release'),
            'revision' => $this->option('revision'),
            'note' => $this->option('note'),
        ]);

        $opsRunService->finish($run, $status, [
            'release' => (string) $this->argument('release'),
            'revision' => (string) $this->option('revision'),
        ]);

        $this->info(ucfirst($action).' metadata recorded.');

        return self::SUCCESS;
    }
}
