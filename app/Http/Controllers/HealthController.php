<?php

namespace App\Http\Controllers;

use App\Services\Ops\OpsStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthController extends Controller
{
    public function __invoke(OpsStatusService $opsStatusService): JsonResponse
    {
        $ops = $opsStatusService->snapshot();
        $checks = [
            'app' => 'ok',
            'database' => $this->checkDatabase(),
            'cache' => $this->checkCache(),
            'scheduler' => $ops['scheduler_heartbeat']['status'],
            'worker' => $ops['worker_heartbeat']['status'],
            'failed_jobs' => $ops['failed_jobs']['status'],
        ];

        $healthy = ! collect($checks)->contains(fn (string $status) => in_array($status, ['failed', 'degraded'], true));

        return response()->json([
            'status' => $healthy ? 'ok' : 'degraded',
            'checks' => $checks,
            'ops' => [
                'queue_mode' => $ops['queue_mode'],
                'queue_names' => $ops['queue_names'],
                'scheduler_heartbeat' => $this->transformHeartbeat($ops['scheduler_heartbeat']),
                'worker_heartbeat' => $this->transformHeartbeat($ops['worker_heartbeat']),
                'failed_jobs_count' => $ops['failed_jobs']['count'],
                'due_source_connections_count' => $ops['due_source_connections_count'],
                'due_feed_builds_count' => $ops['due_feed_builds_count'],
                'due_feed_publishes_count' => $ops['due_feed_publishes_count'],
                'last_successful_sync_at' => optional($ops['last_successful_sync']?->finished_at)->toIso8601String(),
                'last_successful_build_at' => optional($ops['last_successful_build']?->built_at)->toIso8601String(),
                'last_successful_publish_at' => optional($ops['last_successful_publish']?->published_at)->toIso8601String(),
            ],
        ], $healthy ? 200 : 503);
    }

    private function checkDatabase(): string
    {
        try {
            DB::connection()->getPdo();

            return 'ok';
        } catch (Throwable) {
            return 'failed';
        }
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
