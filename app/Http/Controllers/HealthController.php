<?php

namespace App\Http\Controllers;

use App\Services\Ops\OpsStatusService;
use App\Services\Setup\DatabaseSetupInspector;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Throwable;

class HealthController extends Controller
{
    public function __invoke(
        OpsStatusService $opsStatusService,
        DatabaseSetupInspector $databaseSetupInspector
    ): JsonResponse {
        $ops = $opsStatusService->snapshot();
        $schema = $databaseSetupInspector->healthReport();
        $checks = [
            'app' => 'ok',
            'database' => $schema['database_connected'] ? 'ok' : 'failed',
            'schema' => ! $schema['database_connected']
                ? 'unknown'
                : ($schema['schema_ready'] ? 'ok' : 'setup_required'),
            'cache' => $this->checkCache(),
            'scheduler' => $ops['scheduler_heartbeat']['status'],
            'worker' => $ops['worker_heartbeat']['status'],
            'failed_jobs' => $ops['failed_jobs']['status'],
            'prom_api_auth' => ($ops['broken_prom_api_connections_count'] ?? 0) > 0 ? 'failed' : 'ok',
        ];

        $status = $this->overallStatus($checks, $schema);

        return response()->json([
            'status' => $status,
            'schema_ready' => $schema['schema_ready'],
            'missing_tables' => $schema['missing_tables'],
            'setup_required' => $schema['setup_required'],
            'checks' => $checks,
            'ops' => [
                'queue_mode' => $ops['queue_mode'],
                'queue_names' => $ops['queue_names'],
                'scheduler_heartbeat' => $this->transformHeartbeat($ops['scheduler_heartbeat']),
                'worker_heartbeat' => $this->transformHeartbeat($ops['worker_heartbeat']),
                'failed_jobs_count' => $ops['failed_jobs']['count'],
                'broken_prom_api_connections_count' => $ops['broken_prom_api_connections_count'] ?? 0,
                'due_source_connections_count' => $ops['due_source_connections_count'],
                'due_feed_builds_count' => $ops['due_feed_builds_count'],
                'due_feed_publishes_count' => $ops['due_feed_publishes_count'],
                'last_successful_sync_at' => optional($ops['last_successful_sync']?->finished_at)->toIso8601String(),
                'last_successful_build_at' => optional($ops['last_successful_build']?->built_at)->toIso8601String(),
                'last_successful_publish_at' => optional($ops['last_successful_publish']?->published_at)->toIso8601String(),
            ],
        ], $status === 'ok' ? 200 : 503);
    }

    private function checkCache(): string
    {
        try {
            $key = config('feed_mediator.health_cache_key');
            Cache::put($key, 'ok', 30);

            return Cache::get($key) === 'ok' ? 'ok' : 'failed';
        } catch (Throwable) {
            return 'failed';
        }
    }

    /**
     * @param  array<string, string>  $checks
     * @param  array{database_connected:bool,schema_ready:bool,setup_required:bool,required_tables:array<int,string>,missing_tables:array<int,string>}  $schema
     */
    private function overallStatus(array $checks, array $schema): string
    {
        if (! $schema['database_connected']) {
            return 'degraded';
        }

        if ($schema['setup_required']) {
            return 'setup_required';
        }

        return collect($checks)->contains(fn (string $status) => in_array($status, ['failed', 'degraded'], true))
            ? 'degraded'
            : 'ok';
    }

    /**
     * @param  array{status:string,last_seen_at:mixed,age_seconds:mixed}  $heartbeat
     * @return array{status:string,last_seen_at:?string,age_seconds:?int}
     */
    private function transformHeartbeat(array $heartbeat): array
    {
        return [
            'status' => $heartbeat['status'],
            'last_seen_at' => $heartbeat['last_seen_at']?->toIso8601String(),
            'age_seconds' => $heartbeat['age_seconds'],
        ];
    }
}
