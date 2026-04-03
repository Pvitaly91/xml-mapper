<?php

namespace App\Console\Commands;

use App\Services\Ops\BackupService;
use Illuminate\Console\Command;

class OpsBackupFilesCommand extends Command
{
    protected $signature = 'ops:backup-files';

    protected $description = 'Create a ZIP backup of feed artifacts and imports on the configured storage disk.';

    public function handle(BackupService $service): int
    {
        $result = $service->backupFiles();

        $this->info('Files backup created: '.$result['path']);

        return self::SUCCESS;
    }
}
