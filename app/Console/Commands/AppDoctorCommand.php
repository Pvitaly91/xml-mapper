<?php

namespace App\Console\Commands;

use App\Services\Setup\DatabaseSetupInspector;
use Illuminate\Console\Command;

class AppDoctorCommand extends Command
{
    protected $signature = 'app:doctor';

    protected $description = 'Check database connectivity and required schema readiness.';

    public function handle(DatabaseSetupInspector $databaseSetupInspector): int
    {
        $report = $databaseSetupInspector->adminReport();

        if (! $report['database_connected']) {
            $this->error('Database connection failed.');
            $this->line('Check DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME and DB_PASSWORD in .env.');

            return self::FAILURE;
        }

        $this->info('Database connection: OK');

        if ($report['schema_ready']) {
            $this->info('Schema readiness: OK');
            $this->line('All required admin tables are present.');

            return self::SUCCESS;
        }

        $this->warn('Schema readiness: setup_required');
        $this->line('Missing tables: '.implode(', ', $report['missing_tables']));
        $this->line('Next steps:');
        $this->line('  php artisan migrate');
        $this->line('  php artisan app:doctor');

        return self::FAILURE;
    }
}
