<?php

namespace Tests\Unit\Normalization;

use App\Contracts\Source\ProductNormalizerInterface;
use App\Contracts\Source\PromYmlParserInterface;
use App\Models\Shop;
use App\Models\SourceConnection;
use App\Models\SourceImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductNormalizerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_groups_variants_by_vendor_and_article_and_creates_stable_offer_ids(): void
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
            'source_url' => base_path('tests/Fixtures/prom_sample.yml'),
        ]);

        $import = SourceImport::create([
            'shop_id' => $shop->id,
            'source_connection_id' => $connection->id,
            'status' => SourceImport::STATUS_FETCHED,
            'temp_path' => 'tests/Fixtures/prom_sample.yml',
        ]);

        $parser = app(PromYmlParserInterface::class);
        $normalizer = app(ProductNormalizerInterface::class);

        $summary = $normalizer->normalize($connection, $import, $parser->parseFile(base_path('tests/Fixtures/prom_sample.yml')));

        $this->assertSame(['categories' => 2, 'products' => 1, 'variants' => 2], $summary);
        $this->assertDatabaseCount('source_products', 1);
        $this->assertDatabaseCount('source_variants', 2);
        $this->assertDatabaseHas('source_products', [
            'vendor' => 'Acme',
            'article' => 'TSHIRT-001',
        ]);
        $this->assertDatabaseHas('source_variants', [
            'external_offer_id' => 'SKU-1',
            'color' => 'Black',
            'size' => 'S',
        ]);
        $this->assertDatabaseMissing('source_variants', [
            'stable_offer_id' => null,
        ]);
    }
}
