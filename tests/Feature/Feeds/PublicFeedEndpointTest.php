<?php

namespace Tests\Feature\Feeds;

use App\Contracts\Feeds\FeedBuildServiceInterface;
use App\Contracts\Feeds\FeedPublishServiceInterface;
use App\Models\CategoryMapping;
use App\Models\FeedProfile;
use App\Models\KastaCategory;
use App\Models\Shop;
use App\Models\SourceCategory;
use App\Models\SourceConnection;
use App\Models\SourceProduct;
use App\Models\SourceVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicFeedEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_serves_prebuilt_public_feed_xml(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));

        $feedProfile = $this->seedPublishedFeed();

        $response = $this->get('/feeds/'.$feedProfile->public_token.'.xml');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
        $response->assertSee('ofr_test_001', false);
        $response->assertSee('KASTA-TSHIRTS', false);
    }

    private function seedPublishedFeed(): FeedProfile
    {
        $shop = Shop::create([
            'name' => 'Demo Shop',
            'slug' => 'demo-shop',
        ]);

        $connection = SourceConnection::create([
            'shop_id' => $shop->id,
            'name' => 'Prom Feed',
            'code' => 'prom-main',
            'driver' => SourceConnection::DRIVER_PROM_YML,
            'status' => SourceConnection::STATUS_ACTIVE,
            'source_url' => 'https://example.test/feed.xml',
        ]);

        $sourceCategory = SourceCategory::create([
            'shop_id' => $shop->id,
            'source_connection_id' => $connection->id,
            'external_id' => '101',
            'name' => 'Футболки',
        ]);

        $product = SourceProduct::create([
            'shop_id' => $shop->id,
            'source_connection_id' => $connection->id,
            'source_category_id' => $sourceCategory->id,
            'group_key' => 'grp-1',
            'name' => 'Футболка Basic',
            'vendor' => 'Acme',
            'article' => 'TSHIRT-001',
            'brand' => 'Acme',
            'description' => 'Базова футболка',
            'primary_image_url' => 'https://example.test/img-1.jpg',
            'images_json' => ['https://example.test/img-1.jpg'],
            'is_active' => true,
        ]);

        SourceVariant::create([
            'shop_id' => $shop->id,
            'source_connection_id' => $connection->id,
            'source_product_id' => $product->id,
            'external_offer_id' => 'SKU-1',
            'external_sku' => 'SKU-1',
            'stable_offer_id' => 'ofr_test_001',
            'offer_identity_key' => 'SKU-1',
            'export_key_hash' => sha1('SKU-1'),
            'title' => 'Футболка Basic Black S',
            'price' => 799,
            'currency' => 'UAH',
            'quantity' => 10,
            'is_available' => true,
            'color' => 'Black',
            'size' => 'S',
            'images_json' => ['https://example.test/img-1.jpg'],
            'is_enabled' => true,
        ]);

        $feedProfile = FeedProfile::create([
            'shop_id' => $shop->id,
            'source_connection_id' => $connection->id,
            'name' => 'Kasta Main',
            'code' => 'kasta-main',
            'status' => FeedProfile::STATUS_ACTIVE,
        ]);

        $kastaCategory = KastaCategory::create([
            'external_id' => 'KASTA-TSHIRTS',
            'name' => 'Футболки',
            'is_active' => true,
        ]);

        CategoryMapping::create([
            'shop_id' => $shop->id,
            'source_connection_id' => $connection->id,
            'feed_profile_id' => $feedProfile->id,
            'source_category_id' => $sourceCategory->id,
            'kasta_category_id' => $kastaCategory->id,
            'mapping_strategy' => CategoryMapping::STRATEGY_MANUAL,
            'is_active' => true,
        ]);

        $generation = app(FeedBuildServiceInterface::class)->build($feedProfile);
        app(FeedPublishServiceInterface::class)->publish($feedProfile, $generation);

        return $feedProfile->fresh();
    }
}
