<?php

namespace Tests\Feature\Health;

use App\Models\Shop;
use App\Models\SourceConnection;
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

    public function test_health_endpoint_reports_broken_prom_api_auth_in_ops_payload(): void
    {
        config()->set('queue.default', 'redis');

        app(HeartbeatService::class)->recordSchedulerHeartbeat(CarbonImmutable::now());
        app(HeartbeatService::class)->recordWorkerHeartbeat(CarbonImmutable::now());

        $shop = Shop::create([
            'name' => 'Demo Shop',
            'slug' => 'demo-shop-health',
        ]);

        SourceConnection::create([
            'shop_id' => $shop->id,
            'name' => 'Broken Prom API',
            'code' => 'broken-prom-api',
            'driver' => SourceConnection::DRIVER_PROM_API,
            'status' => SourceConnection::STATUS_ACTIVE,
            'api_base_url' => 'https://my.prom.ua',
            'api_token' => 'broken',
            'api_version' => 'v1',
            'last_sync_status' => SourceConnection::CHECK_STATUS_AUTH_FAILED,
            'last_sync_message' => 'Prom API authorization failed.',
            'sync_interval_minutes' => 60,
        ]);

        $response = $this->get('/health');

        $response->assertStatus(503);
        $response->assertJsonPath('checks.prom_api_auth', 'failed');
        $response->assertJsonPath('ops.broken_prom_api_connections_count', 1);
    }
}
