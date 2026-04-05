<?php

namespace Tests\Feature\Feeds;

use App\Contracts\Feeds\FeedBuildServiceInterface;
use App\Models\AttributeMapping;
use App\Models\FeedItem;
use App\Models\SizeGrid;
use App\Models\ValidationError;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAdminContext;
use Tests\TestCase;

class KastaExportConformanceTest extends TestCase
{
    use CreatesAdminContext;
    use RefreshDatabase;

    public function test_required_category_attributes_are_enforced(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));

        ['feedProfile' => $feedProfile, 'kastaCategory' => $kastaCategory] = $this->seedBuildableCatalog();
        $this->createKastaAttribute($kastaCategory, 'Material', 'material', true);

        app(FeedBuildServiceInterface::class)->build($feedProfile);

        $feedItem = FeedItem::query()->where('feed_profile_id', $feedProfile->id)->firstOrFail();

        $this->assertSame(FeedItem::STATUS_INVALID_MAPPING, $feedItem->status);
        $this->assertDatabaseHas('validation_errors', [
            'feed_item_id' => $feedItem->id,
            'code' => ValidationError::CODE_MISSING_REQUIRED_ATTRIBUTE_MAPPING,
            'is_active' => true,
        ]);
    }

    public function test_missing_required_source_values_are_detected(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));

        [
            'connection' => $connection,
            'feedProfile' => $feedProfile,
            'sourceCategory' => $sourceCategory,
            'variant' => $variant,
            'kastaCategory' => $kastaCategory,
        ] = $this->seedBuildableCatalog();

        $seasonAttribute = $this->createSourceAttribute($connection, 'Season', 'season');
        $kastaSeason = $this->createKastaAttribute($kastaCategory, 'Season', 'season', true);

        AttributeMapping::create([
            'shop_id' => $connection->shop_id,
            'source_connection_id' => $connection->id,
            'feed_profile_id' => $feedProfile->id,
            'source_category_id' => $sourceCategory->id,
            'source_attribute_id' => $seasonAttribute->id,
            'kasta_category_id' => $kastaCategory->id,
            'kasta_attribute_id' => $kastaSeason->id,
            'mapping_strategy' => AttributeMapping::STRATEGY_MANUAL,
            'is_required' => true,
            'use_variant_value' => true,
        ]);

        $variant->update(['attributes_snapshot' => ['Color' => 'Black', 'Size' => 'S']]);

        app(FeedBuildServiceInterface::class)->build($feedProfile);

        $feedItem = FeedItem::query()->where('feed_profile_id', $feedProfile->id)->firstOrFail();

        $this->assertSame(FeedItem::STATUS_INVALID_SOURCE, $feedItem->status);
        $this->assertDatabaseHas('validation_errors', [
            'feed_item_id' => $feedItem->id,
            'code' => ValidationError::CODE_MISSING_REQUIRED_ATTRIBUTE_VALUE,
            'is_active' => true,
        ]);
    }

    public function test_missing_required_value_mappings_are_detected(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));

        [
            'connection' => $connection,
            'feedProfile' => $feedProfile,
            'sourceCategory' => $sourceCategory,
            'kastaCategory' => $kastaCategory,
        ] = $this->seedBuildableCatalog();

        $colorAttribute = $this->createSourceAttribute($connection, 'Color', 'color', true);
        $kastaColor = $this->createKastaAttribute($kastaCategory, 'Color', 'color', true, false);

        AttributeMapping::create([
            'shop_id' => $connection->shop_id,
            'source_connection_id' => $connection->id,
            'feed_profile_id' => $feedProfile->id,
            'source_category_id' => $sourceCategory->id,
            'source_attribute_id' => $colorAttribute->id,
            'kasta_category_id' => $kastaCategory->id,
            'kasta_attribute_id' => $kastaColor->id,
            'mapping_strategy' => AttributeMapping::STRATEGY_MANUAL,
            'is_required' => true,
            'use_variant_value' => true,
        ]);

        app(FeedBuildServiceInterface::class)->build($feedProfile);

        $feedItem = FeedItem::query()->where('feed_profile_id', $feedProfile->id)->firstOrFail();

        $this->assertSame(FeedItem::STATUS_INVALID_MAPPING, $feedItem->status);
        $this->assertDatabaseHas('validation_errors', [
            'feed_item_id' => $feedItem->id,
            'code' => ValidationError::CODE_MISSING_VALUE_MAPPING,
            'is_active' => true,
        ]);
    }

    public function test_invalid_color_and_size_cases_are_marked_as_conformance_failures(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));

        ['feedProfile' => $feedProfile, 'variant' => $variant] = $this->seedBuildableCatalog();

        $variant->update([
            'color' => null,
            'size' => null,
            'attributes_snapshot' => [],
        ]);

        app(FeedBuildServiceInterface::class)->build($feedProfile);

        $feedItem = FeedItem::query()->where('feed_profile_id', $feedProfile->id)->firstOrFail();

        $this->assertSame(FeedItem::STATUS_INVALID_CONFORMANCE, $feedItem->status);
        $this->assertDatabaseHas('validation_errors', [
            'feed_item_id' => $feedItem->id,
            'code' => ValidationError::CODE_INVALID_COLOR,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('validation_errors', [
            'feed_item_id' => $feedItem->id,
            'code' => ValidationError::CODE_INVALID_SIZE,
            'is_active' => true,
        ]);
    }

    public function test_ready_item_passes_conformance(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));

        ['feedProfile' => $feedProfile, 'variant' => $variant] = $this->seedBuildableCatalog();

        $generation = app(FeedBuildServiceInterface::class)->build($feedProfile);
        $feedItem = FeedItem::query()->where('feed_profile_id', $feedProfile->id)->firstOrFail();

        $this->assertSame(FeedItem::STATUS_READY, $feedItem->status);
        $this->assertSame(1, $generation->meta['summary']['ready']);
        Storage::disk(config('feed_mediator.storage_disk'))
            ->assertExists($generation->file_path);
        $this->assertStringContainsString($variant->stable_offer_id, Storage::disk(config('feed_mediator.storage_disk'))->get($generation->file_path));
    }

    public function test_footwear_contract_requires_size_grid_and_minimum_images(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));

        ['feedProfile' => $feedProfile, 'product' => $product, 'variant' => $variant, 'kastaCategory' => $kastaCategory] = $this->seedBuildableCatalog();

        $kastaCategory->update([
            'external_id' => 'KASTA-SNEAKERS',
            'name' => 'Sneakers',
            'full_path' => 'Footwear > Sneakers',
        ]);
        $product->update([
            'name' => 'Runner',
            'primary_image_url' => 'https://example.test/shoe-1.jpg',
            'images_json' => ['https://example.test/shoe-1.jpg'],
        ]);
        $variant->update([
            'title' => 'Runner',
            'size' => '42',
            'images_json' => ['https://example.test/shoe-1.jpg'],
            'attributes_snapshot' => ['Color' => 'Black', 'Size' => '42'],
        ]);

        app(FeedBuildServiceInterface::class)->build($feedProfile);
        $feedItem = FeedItem::query()->where('feed_profile_id', $feedProfile->id)->firstOrFail();

        $this->assertSame(FeedItem::STATUS_INVALID_CONFORMANCE, $feedItem->status);
        $this->assertDatabaseHas('validation_errors', [
            'feed_item_id' => $feedItem->id,
            'code' => ValidationError::CODE_INSUFFICIENT_IMAGES,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('validation_errors', [
            'feed_item_id' => $feedItem->id,
            'code' => ValidationError::CODE_INVALID_SIZE_GRID,
            'is_active' => true,
        ]);

        SizeGrid::create([
            'code' => 'adult-eu-shoes',
            'name' => 'Adult EU Shoes',
            'schema' => ['labels' => ['41', '42', '43']],
            'is_active' => true,
        ]);

        $product->update([
            'primary_image_url' => 'https://example.test/shoe-1.jpg',
            'images_json' => [
                'https://example.test/shoe-1.jpg',
                'https://example.test/shoe-2.jpg',
                'https://example.test/shoe-3.jpg',
            ],
        ]);
        $variant->update([
            'images_json' => [
                'https://example.test/shoe-1.jpg',
                'https://example.test/shoe-2.jpg',
                'https://example.test/shoe-3.jpg',
            ],
        ]);

        $generation = app(FeedBuildServiceInterface::class)->build($feedProfile->fresh());

        $this->assertSame(FeedItem::STATUS_READY, $feedItem->fresh()->status);
        $this->assertStringContainsString(
            '<param name="size_grid_code">adult-eu-shoes</param>',
            Storage::disk(config('feed_mediator.storage_disk'))->get($generation->file_path)
        );
    }
}
