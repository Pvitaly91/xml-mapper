<?php

namespace Tests\Feature\Admin;

use App\Models\SourceConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAdminContext;
use Tests\TestCase;

class SourceConnectionCrudTest extends TestCase
{
    use CreatesAdminContext;
    use RefreshDatabase;

    public function test_admin_can_create_update_and_view_source_connection(): void
    {
        $admin = $this->createAdminUser();

        $this->actingAs($admin)
            ->post(route('admin.source-connections.store'), [
                'name' => 'Primary Prom',
                'code' => 'primary-prom',
                'driver' => SourceConnection::DRIVER_PROM_YML,
                'status' => SourceConnection::STATUS_ACTIVE,
                'source_url' => base_path('tests/Fixtures/prom_sample.yml'),
                'sync_interval_minutes' => 30,
                'credentials_json' => json_encode(['token' => 'secret'], JSON_THROW_ON_ERROR),
                'options_json' => json_encode(['region' => 'ua'], JSON_THROW_ON_ERROR),
            ])
            ->assertRedirect();

        $connection = SourceConnection::query()->where('code', 'primary-prom')->firstOrFail();

        $this->assertSame(['region' => 'ua'], $connection->options);

        $this->actingAs($admin)
            ->put(route('admin.source-connections.update', $connection), [
                'name' => 'Primary Prom Updated',
                'code' => 'primary-prom',
                'driver' => SourceConnection::DRIVER_PROM_YML,
                'status' => SourceConnection::STATUS_PAUSED,
                'source_url' => base_path('tests/Fixtures/prom_sample.yml'),
                'sync_interval_minutes' => 45,
                'credentials_json' => json_encode(['token' => 'updated'], JSON_THROW_ON_ERROR),
                'options_json' => json_encode(['region' => 'eu'], JSON_THROW_ON_ERROR),
            ])
            ->assertRedirect(route('admin.source-connections.show', $connection));

        $this->assertDatabaseHas('source_connections', [
            'id' => $connection->id,
            'name' => 'Primary Prom Updated',
            'status' => SourceConnection::STATUS_PAUSED,
            'sync_interval_minutes' => 45,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.source-connections.show', $connection))
            ->assertOk()
            ->assertSee('Primary Prom Updated');
    }

    public function test_admin_can_run_manual_source_sync(): void
    {
        $shop = $this->createShop();
        $admin = $this->createAdminUser($shop);
        $connection = $this->createSourceConnection($shop, [
            'source_url' => base_path('tests/Fixtures/prom_sample.yml'),
        ]);

        $this->actingAs($admin)
            ->post(route('admin.source-connections.sync', $connection))
            ->assertRedirect();

        $this->assertDatabaseHas('source_imports', [
            'source_connection_id' => $connection->id,
            'status' => 'normalized',
        ]);
        $this->assertDatabaseCount('source_products', 1);
        $this->assertDatabaseCount('source_variants', 2);
    }
}
