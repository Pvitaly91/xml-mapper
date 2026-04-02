<?php

namespace Tests\Feature\Feeds;

use App\Contracts\Feeds\FeedBuildServiceInterface;
use App\Contracts\Feeds\FeedPublishServiceInterface;
use App\Models\SourceVariant;
use App\Services\Feeds\FeedItemDiagnosticsService;
use App\Services\Feeds\KastaExportXmlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAdminContext;
use Tests\TestCase;

class KastaExportPreviewAndDiffTest extends TestCase
{
    use CreatesAdminContext;
    use RefreshDatabase;

    public function test_preview_fragment_is_generated_for_ready_item(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));

        ['feedProfile' => $feedProfile, 'product' => $product, 'variant' => $variant] = $this->seedBuildableCatalog();

        app(FeedBuildServiceInterface::class)->build($feedProfile);
        $diagnostics = app(FeedItemDiagnosticsService::class)->analyze($feedProfile, $product, $variant);
        $preview = app(KastaExportXmlService::class)->renderOfferFragment($diagnostics['normalized_export_snapshot']);

        $this->assertStringContainsString('<offer id="'.$variant->stable_offer_id.'"', $preview);
        $this->assertStringContainsString('<categoryId>KASTA-TSHIRTS</categoryId>', $preview);
        $this->assertStringContainsString('<vendorCode>TSHIRT-001</vendorCode>', $preview);
    }

    public function test_generation_diff_reports_added_removed_and_changed_items(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));

        ['feedProfile' => $feedProfile, 'product' => $product, 'variant' => $variant] = $this->seedBuildableCatalog();

        $secondVariant = SourceVariant::create([
            'shop_id' => $variant->shop_id,
            'source_connection_id' => $variant->source_connection_id,
            'source_product_id' => $product->id,
            'external_offer_id' => 'SKU-2',
            'external_sku' => 'SKU-2',
            'stable_offer_id' => 'ofr_test_002',
            'offer_identity_key' => 'SKU-2',
            'export_key_hash' => sha1('SKU-2'),
            'title' => 'Basic Tee Black M',
            'price' => 899,
            'currency' => 'UAH',
            'quantity' => 6,
            'is_available' => true,
            'color' => 'Black',
            'size' => 'M',
            'images_json' => ['https://example.test/img-2.jpg'],
            'attributes_snapshot' => ['Color' => 'Black', 'Size' => 'M'],
            'is_enabled' => true,
        ]);

        $firstGeneration = app(FeedBuildServiceInterface::class)->build($feedProfile);
        app(FeedPublishServiceInterface::class)->publish($feedProfile->fresh(), $firstGeneration);

        $variant->update(['price' => 999]);
        $secondVariant->update(['is_enabled' => false]);

        SourceVariant::create([
            'shop_id' => $variant->shop_id,
            'source_connection_id' => $variant->source_connection_id,
            'source_product_id' => $product->id,
            'external_offer_id' => 'SKU-3',
            'external_sku' => 'SKU-3',
            'stable_offer_id' => 'ofr_test_003',
            'offer_identity_key' => 'SKU-3',
            'export_key_hash' => sha1('SKU-3'),
            'title' => 'Basic Tee Blue L',
            'price' => 1099,
            'currency' => 'UAH',
            'quantity' => 3,
            'is_available' => true,
            'color' => 'Blue',
            'size' => 'L',
            'images_json' => ['https://example.test/img-3.jpg'],
            'attributes_snapshot' => ['Color' => 'Blue', 'Size' => 'L'],
            'is_enabled' => true,
        ]);

        $secondGeneration = app(FeedBuildServiceInterface::class)->build($feedProfile);
        $diffSummary = $secondGeneration->meta['diff']['summary'];

        $this->assertSame(1, $diffSummary['added_items_total']);
        $this->assertSame(1, $diffSummary['removed_items_total']);
        $this->assertSame(1, $diffSummary['changed_items_total']);
        $this->assertSame(1, $diffSummary['changed_fields']['price']);
    }
}
