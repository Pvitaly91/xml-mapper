<?php

namespace App\Console\Commands;

use App\Services\Ops\BackupService;
use Illuminate\Console\Command;

class OpsBackupDbCommand extends Command
{
    protected $signature = 'ops:backup-db';

    protected $description = 'Create a database backup artifact on the configured storage disk.';

    public function handle(BackupService $service): int
    {
        $result = $service->backupDatabase();

        $this->info('Database backup created: '.$result['path']);

        return self::SUCCESS;
    }
}
