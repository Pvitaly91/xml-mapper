<?php

namespace Tests\Feature\Admin;

use App\Models\SourceConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesAdminContext;
use Tests\TestCase;

class SourceConnectionPromApiAdminTest extends TestCase
{
    use CreatesAdminContext;
    use RefreshDatabase;

    public function test_source_connection_form_renders_prom_api_driver_specific_fields(): void
    {
        $admin = $this->createAdminUser();

        $this->actingAs($admin)
            ->get(route('admin.source-connections.create'))
            ->assertOk()
            ->assertSee('Prom API')
            ->assertSee('API base URL')
            ->assertSee('API token');
    }

    public function test_admin_can_create_prom_api_source_connection_and_token_is_encrypted(): void
    {
        $admin = $this->createAdminUser();

        $this->actingAs($admin)
            ->post(route('admin.source-connections.store'), [
                'name' => 'Prom API Main',
                'code' => 'prom-api-main',
                'driver' => SourceConnection::DRIVER_PROM_API,
                'status' => SourceConnection::STATUS_ACTIVE,
                'api_base_url' => 'https://my.prom.ua',
                'api_token' => 'secret-token',
                'api_version' => 'v1',
                'sync_interval_minutes' => 30,
                'options_json' => json_encode(['locale' => 'uk'], JSON_THROW_ON_ERROR),
            ])
            ->assertRedirect();

        $connection = SourceConnection::query()->where('code', 'prom-api-main')->firstOrFail();
        $rawToken = DB::table('source_connections')->where('id', $connection->id)->value('api_token');

        $this->assertNotSame('secret-token', $rawToken);
        $this->assertSame('secret-token', $connection->fresh()->api_token);
        $this->assertNull($connection->fresh()->source_url);
    }

    public function test_prom_api_connection_requires_token(): void
    {
        $admin = $this->createAdminUser();

        $this->actingAs($admin)
            ->from(route('admin.source-connections.create'))
            ->post(route('admin.source-connections.store'), [
                'name' => 'Prom API Main',
                'code' => 'prom-api-main',
                'driver' => SourceConnection::DRIVER_PROM_API,
                'status' => SourceConnection::STATUS_ACTIVE,
                'api_base_url' => 'https://my.prom.ua',
                'sync_interval_minutes' => 30,
            ])
            ->assertRedirect(route('admin.source-connections.create'))
            ->assertSessionHasErrors('api_token');
    }

    public function test_test_connection_action_updates_success_state_for_prom_api(): void
    {
        Http::fake([
            'https://my.prom.ua/api/v1/groups/list*' => Http::response(['groups' => [['id' => 1]]], 200),
            'https://my.prom.ua/api/v1/products/list*' => Http::response(['products' => [['id' => 10]]], 200),
        ]);

        $shop = $this->createShop();
        $admin = $this->createAdminUser($shop);
        $connection = SourceConnection::create([
            'shop_id' => $shop->id,
            'name' => 'Prom API',
            'code' => 'prom-api',
            'driver' => SourceConnection::DRIVER_PROM_API,
            'status' => SourceConnection::STATUS_ACTIVE,
            'api_base_url' => 'https://my.prom.ua',
            'api_token' => 'secret-token',
            'api_version' => 'v1',
            'options' => ['locale' => 'uk'],
            'sync_interval_minutes' => 60,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.source-connections.test', $connection))
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertDatabaseHas('source_connections', [
            'id' => $connection->id,
            'last_connection_check_status' => SourceConnection::CHECK_STATUS_OK,
        ]);
    }

    public function test_invalid_prom_api_token_sets_controlled_error_state(): void
    {
        Http::fake([
            'https://my.prom.ua/api/v1/groups/list*' => Http::response(['error' => 'Forbidden'], 403),
        ]);

        $shop = $this->createShop();
        $admin = $this->createAdminUser($shop);
        $connection = SourceConnection::create([
            'shop_id' => $shop->id,
            'name' => 'Prom API',
            'code' => 'prom-api',
            'driver' => SourceConnection::DRIVER_PROM_API,
            'status' => SourceConnection::STATUS_ACTIVE,
            'api_base_url' => 'https://my.prom.ua',
            'api_token' => 'bad-token',
            'api_version' => 'v1',
            'options' => ['locale' => 'uk'],
            'sync_interval_minutes' => 60,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.source-connections.test', $connection))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseHas('source_connections', [
            'id' => $connection->id,
            'last_connection_check_status' => SourceConnection::CHECK_STATUS_AUTH_FAILED,
        ]);
    }
}
