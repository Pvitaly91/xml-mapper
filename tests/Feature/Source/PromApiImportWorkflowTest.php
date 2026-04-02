<?php

namespace Tests\Feature\Source;

use App\Contracts\Feeds\FeedBuildServiceInterface;
use App\Contracts\Feeds\FeedPublishServiceInterface;
use App\Contracts\Source\SourceSyncWorkflowServiceInterface;
use App\Models\CategoryMapping;
use App\Models\FeedGeneration;
use App\Models\FeedProfile;
use App\Models\KastaCategory;
use App\Models\SourceCategory;
use App\Models\SourceConnection;
use App\Models\SourceImport;
use App\Models\SourceProduct;
use App\Models\SourceVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAdminContext;
use Tests\TestCase;

class PromApiImportWorkflowTest extends TestCase
{
    use CreatesAdminContext;
    use RefreshDatabase;

    public function test_prom_api_sync_imports_categories_products_variants_and_attributes_with_pagination(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));
        $this->fakePromApiCatalog();

        $shop = $this->createShop();
        $connection = $this->createPromApiConnection($shop);

        $this->artisan('source:sync', ['sourceConnectionId' => $connection->id])->assertSuccessful();

        $connection = $connection->fresh(['latestImport']);
        $import = $connection->latestImport;

        $this->assertNotNull($import);
        $this->assertSame(SourceImport::STATUS_NORMALIZED, $import->status);
        $this->assertSame(SourceConnection::CHECK_STATUS_OK, $connection->last_sync_status);
        $this->assertSame(3, SourceCategory::query()->where('source_connection_id', $connection->id)->count());
        $this->assertSame(2, SourceProduct::query()->where('source_connection_id', $connection->id)->count());
        $this->assertSame(3, SourceVariant::query()->where('source_connection_id', $connection->id)->count());
        $this->assertDatabaseHas('source_products', [
            'source_connection_id' => $connection->id,
            'external_group_id' => '9001',
        ]);
        $this->assertDatabaseHas('source_variants', [
            'source_connection_id' => $connection->id,
            'external_offer_id' => '5002',
            'external_sku' => 'PROM-SKU-RED-M',
        ]);
        $this->assertDatabaseHas('source_attributes', [
            'source_connection_id' => $connection->id,
            'name' => 'Prom presence',
        ]);
        $this->assertSame('Catalog > Dresses', SourceCategory::query()->where('external_id', '2')->value('full_path'));
        $this->assertSame(2, $import->meta['groups_pages_total']);
        $this->assertSame(2, $import->meta['products_pages_total']);
        $this->assertSame(1, $import->meta['variation_groups_total']);
    }

    public function test_prom_api_reimport_is_idempotent_and_build_publish_pipeline_stays_intact(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));
        $shop = $this->createShop();
        $admin = $this->createAdminUser($shop);
        $connection = $this->createPromApiConnection($shop);

        $this->fakePromApiCatalog();
        $firstImport = app(SourceSyncWorkflowServiceInterface::class)->run($connection, false);
        $firstStableOfferId = SourceVariant::query()
            ->where('source_connection_id', $connection->id)
            ->where('external_offer_id', '5002')
            ->value('stable_offer_id');

        $this->fakePromApiCatalog();
        $secondImport = app(SourceSyncWorkflowServiceInterface::class)->run($connection->fresh(), false);

        $this->assertSame(2, SourceProduct::query()->where('source_connection_id', $connection->id)->count());
        $this->assertSame(3, SourceVariant::query()->where('source_connection_id', $connection->id)->count());
        $this->assertSame($firstStableOfferId, SourceVariant::query()
            ->where('source_connection_id', $connection->id)
            ->where('external_offer_id', '5002')
            ->value('stable_offer_id'));
        $this->assertDatabaseCount('source_imports', 2);

        SourceProduct::query()
            ->where('source_connection_id', $connection->id)
            ->update([
                'vendor' => 'Prom Brand',
                'brand' => 'Prom Brand',
                'article' => 'PROM-ART-001',
            ]);
        SourceVariant::query()
            ->where('source_connection_id', $connection->id)
            ->where('external_offer_id', '5002')
            ->update([
                'color' => 'Red',
                'size' => 'M',
            ]);
        SourceVariant::query()
            ->where('source_connection_id', $connection->id)
            ->where('external_offer_id', '5001')
            ->update([
                'color' => 'Black',
                'size' => 'S',
            ]);
        SourceVariant::query()
            ->where('source_connection_id', $connection->id)
            ->where('external_offer_id', '5003')
            ->update([
                'color' => 'White',
                'size' => '42',
            ]);

        $feedProfile = $this->createFeedProfile($connection, $admin, [
            'code' => 'prom-api-feed',
            'status' => FeedProfile::STATUS_ACTIVE,
        ]);

        $dresses = SourceCategory::query()->where('source_connection_id', $connection->id)->where('external_id', '2')->firstOrFail();
        $shoes = SourceCategory::query()->where('source_connection_id', $connection->id)->where('external_id', '1')->firstOrFail();
        $kastaDresses = KastaCategory::create([
            'external_id' => 'KASTA-DRESSES',
            'name' => 'Dresses',
            'is_active' => true,
        ]);
        $kastaShoes = KastaCategory::create([
            'external_id' => 'KASTA-SHOES',
            'name' => 'Shoes',
            'is_active' => true,
        ]);

        CategoryMapping::create([
            'shop_id' => $shop->id,
            'source_connection_id' => $connection->id,
            'feed_profile_id' => $feedProfile->id,
            'source_category_id' => $dresses->id,
            'kasta_category_id' => $kastaDresses->id,
            'mapping_strategy' => CategoryMapping::STRATEGY_MANUAL,
            'is_active' => true,
        ]);
        CategoryMapping::create([
            'shop_id' => $shop->id,
            'source_connection_id' => $connection->id,
            'feed_profile_id' => $feedProfile->id,
            'source_category_id' => $shoes->id,
            'kasta_category_id' => $kastaShoes->id,
            'mapping_strategy' => CategoryMapping::STRATEGY_MANUAL,
            'is_active' => true,
        ]);

        $generation = app(FeedBuildServiceInterface::class)->build($feedProfile, $secondImport->id);
        $published = app(FeedPublishServiceInterface::class)->publish($feedProfile->fresh(), $generation);

        $this->assertSame(FeedGeneration::STATUS_BUILT, $generation->status);
        $this->assertNotNull($published->published_at);
        Storage::disk(config('feed_mediator.storage_disk'))->assertExists($feedProfile->fresh()->published_path);

        $response = $this->get('/feeds/'.$feedProfile->fresh()->public_token.'.xml');

        $response->assertOk();
        $response->assertSee($firstStableOfferId, false);
        $response->assertSee('KASTA-DRESSES', false);
        $response->assertSee('KASTA-SHOES', false);

        $this->assertSame(SourceImport::STATUS_NORMALIZED, $firstImport->fresh()->status);
    }

    private function createPromApiConnection($shop): SourceConnection
    {
        return SourceConnection::create([
            'shop_id' => $shop->id,
            'name' => 'Prom API',
            'code' => 'prom-api-main',
            'driver' => SourceConnection::DRIVER_PROM_API,
            'status' => SourceConnection::STATUS_ACTIVE,
            'api_base_url' => 'https://my.prom.ua',
            'api_token' => 'secret-token',
            'api_version' => 'v1',
            'options' => [
                'locale' => 'uk',
                'page_limit' => 2,
            ],
            'sync_interval_minutes' => 60,
        ]);
    }

    private function fakePromApiCatalog(): void
    {
        Http::fake(function (Request $request) {
            $data = $request->data();
            $lastId = isset($data['last_id']) ? (int) $data['last_id'] : null;

            if (str_contains($request->url(), '/groups/list')) {
                if ($lastId === 1) {
                    return Http::response([
                        'groups' => [
                            ['id' => 1, 'name' => 'Shoes', 'parent_group_id' => null],
                        ],
                    ], 200);
                }

                return Http::response([
                    'groups' => [
                        ['id' => 3, 'name' => 'Catalog', 'parent_group_id' => null],
                        ['id' => 2, 'name' => 'Dresses', 'parent_group_id' => 3],
                    ],
                ], 200);
            }

            if (str_contains($request->url(), '/products/list')) {
                if ($lastId === 5001) {
                    return Http::response([
                        'products' => [
                            [
                                'id' => 5001,
                                'name' => 'Evening Dress Black S',
                                'sku' => 'PROM-SKU-BLK-S',
                                'description' => 'Black variation',
                                'presence' => 'available',
                                'status' => 'on_display',
                                'selling_type' => 'retail',
                                'currency' => 'UAH',
                                'price' => 1299,
                                'quantity_in_stock' => 7,
                                'measure_unit' => 'pcs',
                                'variation_group_id' => 9001,
                                'variation_base_id' => 5001,
                                'main_image' => 'https://cdn.example.test/dress-black-main.jpg',
                                'images' => [
                                    ['url' => 'https://cdn.example.test/dress-black-2.jpg'],
                                ],
                                'group' => ['id' => 2, 'name' => 'Dresses'],
                                'category' => ['id' => 2, 'caption' => 'Dresses'],
                            ],
                        ],
                    ], 200);
                }

                return Http::response([
                    'products' => [
                        [
                            'id' => 5003,
                            'name' => 'Leather Sneakers White 42',
                            'sku' => 'PROM-SHOE-WHT-42',
                            'description' => 'Standalone sneakers',
                            'presence' => 'available',
                            'status' => 'on_display',
                            'selling_type' => 'retail',
                            'currency' => 'UAH',
                            'price' => 2199,
                            'quantity_in_stock' => 4,
                            'measure_unit' => 'pcs',
                            'main_image' => 'https://cdn.example.test/shoe-main.jpg',
                            'images' => [
                                ['url' => 'https://cdn.example.test/shoe-side.jpg'],
                            ],
                            'group' => ['id' => 1, 'name' => 'Shoes'],
                            'category' => ['id' => 1, 'caption' => 'Shoes'],
                        ],
                        [
                            'id' => 5002,
                            'name' => 'Evening Dress Red M',
                            'sku' => 'PROM-SKU-RED-M',
                            'description' => 'Red variation',
                            'presence' => 'available',
                            'status' => 'on_display',
                            'selling_type' => 'retail',
                            'currency' => 'UAH',
                            'price' => 1199,
                            'quantity_in_stock' => 5,
                            'measure_unit' => 'pcs',
                            'variation_group_id' => 9001,
                            'variation_base_id' => 5001,
                            'discount' => ['type' => 'percent', 'value' => 10],
                            'main_image' => 'https://cdn.example.test/dress-red-main.jpg',
                            'images' => [
                                ['url' => 'https://cdn.example.test/dress-red-2.jpg'],
                            ],
                            'group' => ['id' => 2, 'name' => 'Dresses'],
                            'category' => ['id' => 2, 'caption' => 'Dresses'],
                        ],
                    ],
                ], 200);
            }

            return Http::response(['error' => 'Not found'], 404);
        });
    }
}
