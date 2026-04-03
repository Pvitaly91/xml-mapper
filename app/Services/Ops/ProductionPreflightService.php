<?php

namespace App\Services\Ops;

use App\Models\OpsRun;
use App\Models\User;
use App\Services\Setup\DatabaseSetupInspector;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProductionPreflightService
{
    public function __construct(
        private readonly DatabaseSetupInspector $databaseSetupInspector,
        private readonly OpsRunService $opsRunService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function run(?User $user = null): array
    {
        $run = $this->opsRunService->start(OpsRun::TYPE_PREFLIGHT, user: $user);
        $checks = [
            $this->databaseCheck(),
            $this->redisCheck(),
            $this->schemaCheck(),
            $this->storageCheck(),
            $this->queueCheck(),
            $this->schedulerCheck(),
            $this->appKeyCheck(),
            $this->environmentCheck(),
            $this->criticalConfigCheck(),
        ];
        $blockingIssues = collect($checks)
            ->where('status', 'failed')
            ->pluck('message')
            ->values()
            ->all();
        $warnings = collect($checks)
            ->where('status', 'warning')
            ->pluck('message')
            ->values()
            ->all();
        $nextSteps = collect($checks)
            ->flatMap(fn (array $check): array => $check['next_steps'] ?? [])
            ->filter()
            ->values()
            ->all();
        $status = $blockingIssues !== []
            ? OpsRun::STATUS_FAILED
            : ($warnings !== [] ? OpsRun::STATUS_WARNING : OpsRun::STATUS_SUCCEEDED);
        $summary = [
            'status' => $status,
            'checks_total' => count($checks),
            'failed_checks' => count($blockingIssues),
            'warnings_total' => count($warnings),
        ];

        $this->opsRunService->finish($run, $status, $summary, [
            'checks' => $checks,
            'blocking_issues' => $blockingIssues,
            'warnings' => $warnings,
            'next_steps' => $nextSteps,
        ]);

        return [
            'run' => $run->fresh(),
            'status' => $status,
            'checks' => $checks,
            'blocking_issues' => $blockingIssues,
            'warnings' => $warnings,
            'next_steps' => $nextSteps,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function databaseCheck(): array
    {
        try {
            DB::connection()->getPdo();

            return $this->ok('database', 'Database connection is ready.');
        } catch (Throwable $exception) {
            return $this->failed('database', 'Database connection failed: '.$exception->getMessage(), [
                'Verify DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, and DB_PASSWORD.',
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function redisCheck(): array
    {
        if (! config('feed_mediator.preflight.require_redis', true)) {
            return $this->warning('redis', 'Redis check is disabled for this environment.');
        }

        try {
            Redis::connection()->ping();
            Cache::put('feed-mediator:preflight', 'ok', 30);

            return $this->ok('redis', 'Redis connection is ready.');
        } catch (Throwable $exception) {
            return $this->failed('redis', 'Redis connection failed: '.$exception->getMessage(), [
                'Verify REDIS_HOST, REDIS_PORT, REDIS_PASSWORD, and queue/cache Redis settings.',
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function schemaCheck(): array
    {
        $report = $this->databaseSetupInspector->adminReport();

        if (! $report['database_connected']) {
            return $this->failed('schema', 'Schema check skipped because the database is not reachable.');
        }

        if ($report['missing_tables'] !== []) {
            return $this->failed(
                'schema',
                'Database schema is missing required tables: '.implode(', ', $report['missing_tables']).'.',
                ['Run php artisan migrate --force before switching the release symlink.'],
                ['missing_tables' => $report['missing_tables']]
            );
        }

        return $this->ok('schema', 'Database schema is ready.');
    }

    /**
     * @return array<string, mixed>
     */
    private function storageCheck(): array
    {
        $disk = Storage::disk(config('feed_mediator.storage_disk'));
        $directories = config('feed_mediator.preflight.required_directories', []);
        $missing = [];
        $unwritable = [];

        foreach ($directories as $directory) {
            try {
                $absoluteDirectory = $disk->path($directory);

                if (! is_dir($absoluteDirectory)) {
                    $missing[] = $directory;

                    continue;
                }

                $probe = rtrim($directory, '/').'/._preflight_probe';
                $disk->put($probe, 'ok');
                $disk->delete($probe);
            } catch (Throwable) {
                $unwritable[] = $directory;
            }
        }

        if ($missing !== [] || $unwritable !== []) {
            return $this->failed(
                'storage',
                'Storage disk is not ready for production feed artifacts.',
                [
                    'Create missing storage directories and ensure the PHP-FPM user can write to them.',
                ],
                [
                    'missing_directories' => $missing,
                    'unwritable_directories' => $unwritable,
                ]
            );
        }

        return $this->ok('storage', 'Storage disk and required directories are writable.');
    }

    /**
     * @return array<string, mixed>
     */
    private function queueCheck(): array
    {
        $connection = (string) config('queue.default');

        if ($connection === 'sync') {
            return $this->warning(
                'queue',
                'Queue connection is set to sync. This is acceptable for tests, but not for production workers.',
                ['Set QUEUE_CONNECTION=redis and start worker services before production traffic.']
            );
        }

        try {
            foreach ((array) config('feed_mediator.queues') as $queueName) {
                Queue::size($queueName);
            }

            return $this->ok('queue', 'Queue connection and named queues are reachable.');
        } catch (Throwable $exception) {
            return $this->failed('queue', 'Queue readiness check failed: '.$exception->getMessage(), [
                'Verify queue workers and queue driver access before deploy.',
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function schedulerCheck(): array
    {
        $heartbeat = app(HeartbeatService::class)->schedulerHeartbeat();

        if ($heartbeat === null) {
            return $this->warning(
                'scheduler',
                'Scheduler heartbeat is missing.',
                ['Ensure deploy/cron or systemd starts php artisan schedule:work or schedule:run.']
            );
        }

        $ageSeconds = $heartbeat->diffInSeconds(now());

        if ($ageSeconds > (int) config('feed_mediator.ops.heartbeat_stale_after_seconds')) {
            return $this->warning(
                'scheduler',
                sprintf('Scheduler heartbeat is stale (%d seconds old).', $ageSeconds),
                ['Restart the scheduler service after deploy if needed.']
            );
        }

        return $this->ok('scheduler', 'Scheduler heartbeat is fresh.');
    }

    /**
     * @return array<string, mixed>
     */
    private function appKeyCheck(): array
    {
        if (blank(config('app.key'))) {
            return $this->failed('app_key', 'APP_KEY is missing.', [
                'Set APP_KEY before production deploy and rebuild config cache.',
            ]);
        }

        return $this->ok('app_key', 'APP_KEY is configured.');
    }

    /**
     * @return array<string, mixed>
     */
    private function environmentCheck(): array
    {
        $issues = [];

        if (config('app.debug')) {
            $issues[] = 'APP_DEBUG=true';
        }

        if ((string) config('app.env') !== 'production') {
            $issues[] = 'APP_ENV is not production';
        }

        if ($issues !== []) {
            return $this->warning(
                'environment',
                'Environment sanity warnings: '.implode(', ', $issues).'.',
                ['Review APP_ENV and APP_DEBUG before go-live.']
            );
        }

        return $this->ok('environment', 'Environment flags are production-safe.');
    }

    /**
     * @return array<string, mixed>
     */
    private function criticalConfigCheck(): array
    {
        $missing = [];

        foreach (['app.url', 'feed_mediator.storage_disk', 'feed_mediator.backups.db_directory', 'feed_mediator.backups.files_directory'] as $key) {
            if (blank(config($key))) {
                $missing[] = $key;
            }
        }

        if ($missing !== []) {
            return $this->failed(
                'config',
                'Critical production configuration is missing: '.implode(', ', $missing).'.',
                ['Populate missing .env/config values and rebuild config cache.']
            );
        }

        return $this->ok('config', 'Critical production configuration is present.');
    }

    /**
     * @param  array<string, mixed>  $details
     * @param  list<string>  $nextSteps
     * @return array<string, mixed>
     */
    private function ok(string $key, string $message, array $details = [], array $nextSteps = []): array
    {
        return [
            'key' => $key,
            'status' => 'ok',
            'message' => $message,
            'details' => $details,
            'next_steps' => $nextSteps,
        ];
    }

    /**
     * @param  list<string>  $nextSteps
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    private function warning(string $key, string $message, array $nextSteps = [], array $details = []): array
    {
        return [
            'key' => $key,
            'status' => 'warning',
            'message' => $message,
            'details' => $details,
            'next_steps' => $nextSteps,
        ];
    }

    /**
     * @param  list<string>  $nextSteps
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    private function failed(string $key, string $message, array $nextSteps = [], array $details = []): array
    {
        return [
            'key' => $key,
            'status' => 'failed',
            'message' => $message,
            'details' => $details,
            'next_steps' => $nextSteps,
        ];
    }
}
