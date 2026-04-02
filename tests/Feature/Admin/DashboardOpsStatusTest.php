<?php

namespace Tests\Feature\Admin;

use App\Models\SourceConnection;
use App\Services\Ops\HeartbeatService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesAdminContext;
use Tests\TestCase;

class DashboardOpsStatusTest extends TestCase
{
    use CreatesAdminContext;
    use RefreshDatabase;

    public function test_dashboard_displays_ops_status_metrics(): void
    {
        config()->set('queue.default', 'redis');

        $shop = $this->createShop();
        $admin = $this->createAdminUser($shop);
        $connection = $this->createSourceConnection($shop, ['next_sync_at' => now()->subMinute()]);
        $this->createFeedProfile($connection, $admin, ['next_build_at' => now()->subMinute()]);

        app(HeartbeatService::class)->recordSchedulerHeartbeat(CarbonImmutable::now());
        app(HeartbeatService::class)->recordWorkerHeartbeat(CarbonImmutable::now());

        DB::table('failed_jobs')->insert([
            'uuid' => (string) str()->uuid(),
            'connection' => 'redis',
            'queue' => 'feeds',
            'payload' => '{}',
            'exception' => 'dashboard failure sample',
            'failed_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Ops Status')
            ->assertSee('Queue mode')
            ->assertSee('Failed jobs')
            ->assertSee('Due source connections')
            ->assertSee('Due feed builds')
            ->assertSee('1');
    }

    public function test_dashboard_surfaces_broken_prom_api_auth_state(): void
    {
        config()->set('queue.default', 'redis');

        $shop = $this->createShop();
        $admin = $this->createAdminUser($shop);

        SourceConnection::create([
            'shop_id' => $shop->id,
            'name' => 'Prom API Broken',
            'code' => 'prom-api-broken',
            'driver' => SourceConnection::DRIVER_PROM_API,
            'status' => SourceConnection::STATUS_ACTIVE,
            'api_base_url' => 'https://my.prom.ua',
            'api_token' => 'broken-token',
            'api_version' => 'v1',
            'last_connection_check_status' => SourceConnection::CHECK_STATUS_AUTH_FAILED,
            'last_connection_check_message' => 'Prom API token expired.',
            'sync_interval_minutes' => 60,
        ]);

        app(HeartbeatService::class)->recordSchedulerHeartbeat(CarbonImmutable::now());
        app(HeartbeatService::class)->recordWorkerHeartbeat(CarbonImmutable::now());

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Broken Prom API auth')
            ->assertSee('Prom API Broken')
            ->assertSee('Prom API token expired.');
    }
}
