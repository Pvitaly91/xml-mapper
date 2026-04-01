<?php

namespace Tests\Unit\Mapping;

use App\Contracts\Mappings\CategoryMappingServiceInterface;
use App\Models\CategoryMapping;
use App\Models\FeedProfile;
use App\Models\KastaCategory;
use App\Models\Shop;
use App\Models\SourceCategory;
use App\Models\SourceConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryMappingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_auto_maps_category_by_rz_id(): void
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

        $feedProfile = FeedProfile::create([
            'shop_id' => $shop->id,
            'source_connection_id' => $connection->id,
            'name' => 'Kasta Main',
            'code' => 'kasta-main',
            'status' => FeedProfile::STATUS_ACTIVE,
        ]);

        $sourceCategory = SourceCategory::create([
            'shop_id' => $shop->id,
            'source_connection_id' => $connection->id,
            'external_id' => '101',
            'name' => 'Футболки',
            'rz_id' => '2001',
        ]);

        $kastaCategory = KastaCategory::create([
            'external_id' => 'K-2001',
            'name' => 'Футболки',
            'rz_id' => '2001',
            'is_active' => true,
        ]);

        $service = app(CategoryMappingServiceInterface::class);
        $mapping = $service->resolve($feedProfile, $sourceCategory);

        $this->assertNotNull($mapping);
        $this->assertSame($kastaCategory->id, $mapping->kasta_category_id);
        $this->assertSame(CategoryMapping::STRATEGY_RZ_ID, $mapping->mapping_strategy);
        $this->assertDatabaseHas('category_mappings', [
            'feed_profile_id' => $feedProfile->id,
            'source_category_id' => $sourceCategory->id,
            'kasta_category_id' => $kastaCategory->id,
        ]);
    }
}
