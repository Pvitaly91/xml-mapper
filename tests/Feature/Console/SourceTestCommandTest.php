<?php

namespace Tests\Feature\Console;

use App\Models\SourceConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesAdminContext;
use Tests\TestCase;

class SourceTestCommandTest extends TestCase
{
    use CreatesAdminContext;
    use RefreshDatabase;

    public function test_source_test_command_reports_prom_api_success(): void
    {
        Http::fake([
            'https://my.prom.ua/api/v1/groups/list*' => Http::response(['groups' => [['id' => 1]]], 200),
            'https://my.prom.ua/api/v1/products/list*' => Http::response(['products' => [['id' => 10]]], 200),
        ]);

        $shop = $this->createShop();
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

        $this->artisan('source:test', ['sourceConnectionId' => $connection->id])
            ->expectsOutput("Connection #{$connection->id} [prom_api]: Prom API token can access Products and Groups endpoints.")
            ->assertSuccessful();

        $this->assertDatabaseHas('source_connections', [
            'id' => $connection->id,
            'last_connection_check_status' => SourceConnection::CHECK_STATUS_OK,
        ]);
    }
}
