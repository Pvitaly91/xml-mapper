<?php

namespace Tests\Feature\Feeds;

use App\Contracts\Feeds\FeedBuildServiceInterface;
use App\Models\FeedItem;
use App\Models\SourceVariant;
use App\Models\ValidationError;
use App\Services\Mappings\Automation\FeedItemMappingExceptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAdminContext;
use Tests\TestCase;

class VariantFamilyExportTest extends TestCase
{
    use CreatesAdminContext;
    use RefreshDatabase;

    public function test_duplicate_variant_axes_are_blocked_until_item_level_override_resolves_family(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));

        ['feedProfile' => $feedProfile, 'product' => $product, 'variant' => $variant] = $this->seedBuildableCatalog();

        $duplicateVariant = SourceVariant::create([
            'shop_id' => $variant->shop_id,
            'source_connection_id' => $variant->source_connection_id,
            'source_product_id' => $product->id,
            'external_offer_id' => 'SKU-duplicate',
            'external_sku' => 'SKU-duplicate',
            'stable_offer_id' => 'ofr_duplicate_axes',
            'offer_identity_key' => 'SKU-duplicate',
            'export_key_hash' => sha1('SKU-duplicate'),
            'title' => 'Basic Tee Black S Duplicate',
            'price' => 799,
            'currency' => 'UAH',
            'quantity' => 8,
            'is_available' => true,
            'color' => 'Black',
            'size' => 'S',
            'images_json' => ['https://example.test/img-duplicate.jpg'],
            'attributes_snapshot' => ['Color' => 'Black', 'Size' => 'S'],
            'is_enabled' => true,
        ]);

        app(FeedBuildServiceInterface::class)->build($feedProfile);

        $this->assertDatabaseHas('validation_errors', [
            'source_variant_id' => $variant->id,
            'code' => ValidationError::CODE_VARIANT_GROUPING_ISSUE,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('validation_errors', [
            'source_variant_id' => $duplicateVariant->id,
            'code' => ValidationError::CODE_VARIANT_GROUPING_ISSUE,
            'is_active' => true,
        ]);

        $duplicateFeedItem = FeedItem::query()
            ->where('feed_profile_id', $feedProfile->id)
            ->where('source_variant_id', $duplicateVariant->id)
            ->firstOrFail();

        app(FeedItemMappingExceptionService::class)->syncContentOverrides(
            $feedProfile,
            $duplicateFeedItem,
            ['size' => 'M'],
            'Resolve duplicate size axis for one variant'
        );

        $generation = app(FeedBuildServiceInterface::class)->build($feedProfile->fresh());

        $this->assertDatabaseHas('feed_items', [
            'feed_profile_id' => $feedProfile->id,
            'source_variant_id' => $variant->id,
            'status' => FeedItem::STATUS_READY,
        ]);
        $this->assertDatabaseHas('feed_items', [
            'feed_profile_id' => $feedProfile->id,
            'source_variant_id' => $duplicateVariant->id,
            'status' => FeedItem::STATUS_READY,
        ]);
        $this->assertStringContainsString($variant->stable_offer_id, Storage::disk(config('feed_mediator.storage_disk'))->get($generation->file_path));
        $this->assertStringContainsString($duplicateVariant->stable_offer_id, Storage::disk(config('feed_mediator.storage_disk'))->get($generation->file_path));
    }
}
