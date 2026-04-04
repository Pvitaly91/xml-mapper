<?php

namespace Tests\Concerns;

use App\Contracts\Source\SourceSyncWorkflowServiceInterface;
use App\Models\CategoryMapping;
use App\Models\FeedGenerationSignoff;
use App\Models\KastaAttribute;
use App\Models\KastaAttributeValue;
use App\Models\FeedProfile;
use App\Models\KastaCategory;
use App\Models\OpsRun;
use App\Models\PilotRun;
use App\Models\SourceCategory;
use App\Models\SourceConnection;
use App\Models\SourceProduct;
use App\Models\SourceVariant;
use App\Models\User;
use App\Services\Ops\HeartbeatService;
use App\Services\Pilot\PilotFixtureLibrary;
use App\Services\Pilot\PilotExecutionService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

trait CreatesPilotFixtureContext
{
    protected function pilotFixtureLibrary(): PilotFixtureLibrary
    {
        return app(PilotFixtureLibrary::class);
    }

    /**
     * @return array<string, mixed>
     */
    protected function seedPilotFixtureCatalog(
        string $driver = SourceConnection::DRIVER_PROM_YML,
        array $connectionOverrides = [],
        array $profileOverrides = []
    ): array {
        $disk = 'pilot-test-'.Str::lower(Str::random(10));
        config()->set("filesystems.disks.{$disk}", [
            'driver' => 'local',
            'root' => storage_path('framework/testing/disks/'.$disk),
            'throw' => false,
        ]);
        config()->set('feed_mediator.storage_disk', $disk);
        Storage::fake($disk);
        config()->set('feed_mediator.environment.class', 'staging');
        config()->set('feed_mediator.environment.label', 'Staging');
        config()->set('app.env', 'staging');
        config()->set('app.debug', false);
        config()->set('queue.default', 'redis');

        foreach (config('feed_mediator.preflight.required_directories', []) as $directory) {
            Storage::disk(config('feed_mediator.storage_disk'))->makeDirectory($directory);
        }

        Redis::shouldReceive('connection->ping')->zeroOrMoreTimes()->andReturn('PONG');
        Queue::shouldReceive('size')->zeroOrMoreTimes()->andReturn(0);
        app(HeartbeatService::class)->recordSchedulerHeartbeat(CarbonImmutable::now());

        $shop = $this->createShop();
        $admin = $this->createAdminUser($shop);

        if ($driver === SourceConnection::DRIVER_PROM_API) {
            $this->fakePilotPromApiCatalog();
        }

        $connection = $this->createSourceConnection($shop, array_merge([
            'driver' => $driver,
            'source_url' => $driver === SourceConnection::DRIVER_PROM_YML ? $this->pilotFixtureLibrary()->promYmlPath() : null,
            'api_base_url' => $driver === SourceConnection::DRIVER_PROM_API ? SourceConnection::defaultPromApiBaseUrl() : null,
            'api_version' => $driver === SourceConnection::DRIVER_PROM_API ? SourceConnection::defaultPromApiVersion() : null,
            'api_token' => $driver === SourceConnection::DRIVER_PROM_API ? 'pilot-prom-api-token' : null,
            'options' => $driver === SourceConnection::DRIVER_PROM_API
                ? ['locale' => 'uk', 'page_limit' => 2]
                : [],
        ], $connectionOverrides));

        $feedProfile = $this->createFeedProfile($connection, $admin, array_merge([
            'code' => 'pilot-'.str_replace('_', '-', $driver).'-'.mt_rand(1000, 9999),
            'status' => FeedProfile::STATUS_ACTIVE,
            'settings' => [
                'signoff_required' => true,
                'required_signoff_status' => FeedGenerationSignoff::STATUS_INTERNAL_APPROVED,
                'publish_guard_enabled' => false,
                'publish_window_enabled' => false,
            ],
        ], $profileOverrides));

        $import = app(SourceSyncWorkflowServiceInterface::class)->run($connection->fresh(), false);

        $connection = $connection->fresh(['latestImport']);
        $feedProfile = $feedProfile->fresh(['sourceConnection.latestImport']);

        $this->normalizePilotCatalog($connection);
        $this->preparePilotMappings($feedProfile->fresh(['sourceConnection']));

        return [
            'shop' => $shop,
            'admin' => $admin,
            'connection' => $connection->fresh(['latestImport']),
            'feedProfile' => $feedProfile->fresh(['sourceConnection.latestImport']),
            'import' => $import->fresh(),
        ];
    }

    protected function fakePilotPromApiCatalog(): void
    {
        $groupsPage1 = $this->pilotFixtureLibrary()->promApiResponse('groups-page-1');
        $groupsPage2 = $this->pilotFixtureLibrary()->promApiResponse('groups-page-2');
        $productsPage1 = $this->pilotFixtureLibrary()->promApiResponse('products-page-1');
        $productsPage2 = $this->pilotFixtureLibrary()->promApiResponse('products-page-2');

        Http::fake(function (Request $request) use ($groupsPage1, $groupsPage2, $productsPage1, $productsPage2) {
            $data = $request->data();
            $lastId = isset($data['last_id']) ? (int) $data['last_id'] : null;

            if (str_contains($request->url(), '/groups/list')) {
                return Http::response($lastId === 1 ? $groupsPage2 : $groupsPage1, 200);
            }

            if (str_contains($request->url(), '/products/list')) {
                return Http::response($lastId === 5001 ? $productsPage2 : $productsPage1, 200);
            }

            return Http::response(['error' => 'Not found'], 404);
        });
    }

    protected function preparePilotMappings(FeedProfile $feedProfile): void
    {
        $categories = SourceCategory::query()
            ->where('source_connection_id', $feedProfile->source_connection_id)
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        foreach ($categories as $category) {
            $kastaCategory = KastaCategory::create([
                'external_id' => 'KASTA-'.strtoupper((string) ($category->external_id ?: $category->id)),
                'name' => $category->name,
                'full_path' => $category->full_path ?: $category->name,
                'rz_id' => (string) (2000 + $category->id),
                'is_active' => true,
            ]);
            $attribute = KastaAttribute::create([
                'kasta_category_id' => $kastaCategory->id,
                'external_id' => 'color-'.$category->id,
                'name' => 'Color',
                'code' => 'color',
                'data_type' => 'string',
                'is_required' => false,
                'allows_custom_value' => true,
                'sort_order' => 10,
            ]);
            KastaAttributeValue::create([
                'kasta_attribute_id' => $attribute->id,
                'external_id' => 'black-'.$category->id,
                'value' => 'Black',
                'normalized_value' => 'black',
                'value_hash' => sha1('black-'.$category->id),
                'sort_order' => 10,
            ]);

            CategoryMapping::create([
                'shop_id' => $feedProfile->shop_id,
                'source_connection_id' => $feedProfile->source_connection_id,
                'feed_profile_id' => $feedProfile->id,
                'source_category_id' => $category->id,
                'kasta_category_id' => $kastaCategory->id,
                'mapping_strategy' => CategoryMapping::STRATEGY_MANUAL,
                'is_active' => true,
            ]);
        }
    }

    protected function normalizePilotCatalog(SourceConnection $connection): void
    {
        SourceProduct::query()
            ->where('source_connection_id', $connection->id)
            ->get()
            ->each(function (SourceProduct $product): void {
                $product->update([
                    'vendor' => $product->vendor ?: 'Pilot Brand',
                    'brand' => $product->brand ?: 'Pilot Brand',
                    'article' => $product->article ?: 'PILOT-ART-'.$product->id,
                    'primary_image_url' => $product->primary_image_url ?: 'https://cdn.example.test/pilot-default.jpg',
                    'images_json' => $product->images_json ?: ['https://cdn.example.test/pilot-default.jpg'],
                ]);
            });

        SourceVariant::query()
            ->where('source_connection_id', $connection->id)
            ->get()
            ->each(function (SourceVariant $variant): void {
                $lookup = strtoupper((string) ($variant->external_sku ?: $variant->title ?: $variant->external_offer_id));

                $variant->update([
                    'color' => $variant->color ?: (str_contains($lookup, 'RED') ? 'Red' : (str_contains($lookup, 'WHT') || str_contains($lookup, 'WHITE') ? 'White' : 'Black')),
                    'size' => $variant->size ?: (str_contains($lookup, '42') ? '42' : (str_contains($lookup, '-M') || str_contains($lookup, ' M') ? 'M' : 'S')),
                    'images_json' => $variant->images_json ?: ['https://cdn.example.test/pilot-variant.jpg'],
                    'is_enabled' => true,
                ]);
            });
    }

    /**
     * @return array<string, mixed>
     */
    protected function completePilotRunFromFixtures(
        string $driver = SourceConnection::DRIVER_PROM_YML,
        array $options = [],
        array $connectionOverrides = [],
        array $profileOverrides = []
    ): array {
        $context = $this->seedPilotFixtureCatalog($driver, $connectionOverrides, $profileOverrides);
        $run = app(PilotExecutionService::class)->run(
            $context['feedProfile']->fresh(['sourceConnection.latestImport']),
            array_replace([
                'with_sync' => true,
                'with_build' => true,
                'with_publish' => true,
                'with_feedback_fixtures' => true,
            ], $options),
            $context['admin']
        );

        $context['run'] = $run->fresh();

        return $context;
    }

    protected function recordSuccessfulDeployRun(FeedProfile $feedProfile, ?User $user = null): OpsRun
    {
        return OpsRun::create([
            'shop_id' => $feedProfile->shop_id,
            'feed_profile_id' => $feedProfile->id,
            'user_id' => $user?->id,
            'type' => OpsRun::TYPE_DEPLOY,
            'status' => OpsRun::STATUS_SUCCEEDED,
            'summary' => ['verified' => true],
            'started_at' => now()->subMinutes(5),
            'finished_at' => now(),
            'meta' => ['source' => 'test'],
        ]);
    }
}
