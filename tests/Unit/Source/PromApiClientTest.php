<?php

namespace Tests\Unit\Source;

use App\Contracts\Source\PromApiClientInterface;
use App\Exceptions\Source\SourceAuthException;
use App\Exceptions\Source\SourceInvalidPayloadException;
use App\Exceptions\Source\SourceNetworkException;
use App\Models\SourceConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Concerns\CreatesAdminContext;
use Tests\TestCase;

class PromApiClientTest extends TestCase
{
    use CreatesAdminContext;
    use RefreshDatabase;

    public function test_successful_auth_check_uses_bearer_token_and_headers(): void
    {
        $connection = $this->createPromApiConnection();

        Http::fake([
            'https://my.prom.ua/api/v1/groups/list*' => Http::response(['groups' => [['id' => 1]]], 200),
            'https://my.prom.ua/api/v1/products/list*' => Http::response(['products' => [['id' => 2]]], 200),
        ]);

        $result = app(PromApiClientInterface::class)->checkConnection($connection);

        $this->assertSame([
            'groups_sample_count' => 1,
            'products_sample_count' => 1,
        ], $result);

        Http::assertSent(function (Request $request): bool {
            return $request->hasHeader('Authorization', 'Bearer secret-token')
                && $request->hasHeader('X-LANGUAGE', 'uk');
        });
    }

    public function test_auth_failure_is_mapped_to_source_auth_exception(): void
    {
        $connection = $this->createPromApiConnection();

        Http::fake([
            'https://my.prom.ua/api/v1/groups/list*' => Http::response(['error' => 'Forbidden'], 403),
        ]);

        $this->expectException(SourceAuthException::class);

        app(PromApiClientInterface::class)->checkConnection($connection);
    }

    public function test_rate_limit_handling_retries_and_uses_backoff(): void
    {
        $connection = $this->createPromApiConnection();
        Sleep::fake();

        Http::fake([
            'https://my.prom.ua/api/v1/products/list*' => Http::sequence()
                ->push(['error' => 'Too many requests'], 429, ['Retry-After' => '1'])
                ->push(['products' => []], 200),
        ]);

        $result = app(PromApiClientInterface::class)->fetchAllProducts($connection);

        $this->assertSame([], $result['items']);
        Http::assertSentCount(2);
        Sleep::assertSleptTimes(1);
    }

    public function test_retry_backoff_behavior_retries_server_errors(): void
    {
        $connection = $this->createPromApiConnection();
        Sleep::fake();

        Http::fake([
            'https://my.prom.ua/api/v1/groups/list*' => Http::sequence()
                ->push(['error' => 'Server unavailable'], 503)
                ->push(['groups' => []], 200),
        ]);

        $result = app(PromApiClientInterface::class)->fetchAllGroups($connection);

        $this->assertSame([], $result['items']);
        Http::assertSentCount(2);
        Sleep::assertSleptTimes(1);
    }

    public function test_network_errors_are_mapped_after_retries(): void
    {
        $connection = $this->createPromApiConnection();
        Sleep::fake();

        Http::fake([
            'https://my.prom.ua/api/v1/products/list*' => Http::failedConnection('timeout'),
        ]);

        $this->expectException(SourceNetworkException::class);

        try {
            app(PromApiClientInterface::class)->fetchAllProducts($connection);
        } finally {
            Http::assertSentCount((int) config('feed_mediator.prom_api.retry_times'));
            Sleep::assertSleptTimes(max(0, (int) config('feed_mediator.prom_api.retry_times') - 1));
        }
    }

    public function test_invalid_payload_handling_rejects_missing_products_array(): void
    {
        $connection = $this->createPromApiConnection();

        Http::fake([
            'https://my.prom.ua/api/v1/products/list*' => Http::response(['unexpected' => []], 200),
        ]);

        $this->expectException(SourceInvalidPayloadException::class);

        app(PromApiClientInterface::class)->fetchAllProducts($connection);
    }

    private function createPromApiConnection(array $overrides = []): SourceConnection
    {
        $shop = $this->createShop();

        return SourceConnection::create(array_merge([
            'shop_id' => $shop->id,
            'name' => 'Prom API',
            'code' => 'prom-api-'.mt_rand(1000, 9999),
            'driver' => SourceConnection::DRIVER_PROM_API,
            'status' => SourceConnection::STATUS_ACTIVE,
            'api_base_url' => 'https://my.prom.ua',
            'api_token' => 'secret-token',
            'api_version' => 'v1',
            'options' => ['locale' => 'uk'],
            'sync_interval_minutes' => 60,
        ], $overrides));
    }
}
