<?php

namespace Tests\Feature\Admin;

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
}
