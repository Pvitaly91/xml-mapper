<?php

namespace Tests\Feature\Health;

use App\Services\Ops\HeartbeatService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HealthEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_endpoint_is_ok_when_scheduler_and_worker_heartbeats_are_fresh(): void
    {
        config()->set('queue.default', 'redis');

        app(HeartbeatService::class)->recordSchedulerHeartbeat(CarbonImmutable::now());
        app(HeartbeatService::class)->recordWorkerHeartbeat(CarbonImmutable::now());

        $response = $this->get('/health');

        $response->assertOk();
        $response->assertJsonPath('status', 'ok');
        $response->assertJsonPath('checks.scheduler', 'ok');
        $response->assertJsonPath('checks.worker', 'ok');
    }

    public function test_health_endpoint_is_degraded_when_heartbeat_is_stale(): void
    {
        config()->set('queue.default', 'redis');

        app(HeartbeatService::class)->recordSchedulerHeartbeat(CarbonImmutable::now()->subMinutes(10));
        app(HeartbeatService::class)->recordWorkerHeartbeat(CarbonImmutable::now());

        $response = $this->get('/health');

        $response->assertStatus(503);
        $response->assertJsonPath('status', 'degraded');
        $response->assertJsonPath('checks.scheduler', 'degraded');
    }

    public function test_health_endpoint_reports_failed_jobs_count(): void
    {
        config()->set('queue.default', 'redis');

        app(HeartbeatService::class)->recordSchedulerHeartbeat(CarbonImmutable::now());
        app(HeartbeatService::class)->recordWorkerHeartbeat(CarbonImmutable::now());

        DB::table('failed_jobs')->insert([
            'uuid' => (string) str()->uuid(),
            'connection' => 'redis',
            'queue' => 'feeds',
            'payload' => '{}',
            'exception' => 'test failure',
            'failed_at' => now(),
        ]);

        $response = $this->get('/health');

        $response->assertStatus(503);
        $response->assertJsonPath('ops.failed_jobs_count', 1);
        $response->assertJsonPath('checks.failed_jobs', 'degraded');
    }
}
