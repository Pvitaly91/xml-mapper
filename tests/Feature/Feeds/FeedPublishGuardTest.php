<?php

namespace Tests\Feature\Feeds;

use App\Contracts\Feeds\FeedBuildServiceInterface;
use App\Contracts\Feeds\FeedPublishServiceInterface;
use App\Models\SourceProduct;
use App\Models\SourceVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Concerns\CreatesAdminContext;
use Tests\TestCase;

class FeedPublishGuardTest extends TestCase
{
    use CreatesAdminContext;
    use RefreshDatabase;

    public function test_publish_is_blocked_when_below_minimum_ready_threshold(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));

        ['feedProfile' => $feedProfile] = $this->seedBuildableCatalog();
        $feedProfile->update([
            'settings' => array_merge($feedProfile->settings ?? [], [
                'publish_guard_enabled' => true,
                'minimum_ready_items' => 2,
                'maximum_invalid_ratio' => 1,
                'block_publish_on_critical_conformance' => false,
                'minimum_pictures' => 1,
            ]),
        ]);

        $generation = app(FeedBuildServiceInterface::class)->build($feedProfile->fresh());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Ready items 1 are below the minimum threshold 2.');

        app(FeedPublishServiceInterface::class)->publish($feedProfile->fresh(), $generation);
    }

    public function test_publish_is_blocked_when_invalid_ratio_is_too_high(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));

        ['feedProfile' => $feedProfile, 'connection' => $connection, 'sourceCategory' => $sourceCategory] = $this->seedBuildableCatalog();
        $feedProfile->update([
            'settings' => array_merge($feedProfile->settings ?? [], [
                'publish_guard_enabled' => true,
                'minimum_ready_items' => 0,
                'maximum_invalid_ratio' => 0.2,
                'block_publish_on_critical_conformance' => false,
                'minimum_pictures' => 1,
            ]),
        ]);

        $extraProduct = SourceProduct::create([
            'shop_id' => $connection->shop_id,
            'source_connection_id' => $connection->id,
            'source_category_id' => $sourceCategory->id,
            'group_key' => 'grp-2',
            'name' => 'Broken Tee',
            'vendor' => 'Acme',
            'article' => 'TSHIRT-002',
            'brand' => 'Acme',
            'description' => 'Broken tee',
            'primary_image_url' => 'https://example.test/img-broken.jpg',
            'images_json' => ['https://example.test/img-broken.jpg'],
            'is_active' => true,
        ]);

        SourceVariant::create([
            'shop_id' => $connection->shop_id,
            'source_connection_id' => $connection->id,
            'source_product_id' => $extraProduct->id,
            'external_offer_id' => 'SKU-BROKEN',
            'external_sku' => 'SKU-BROKEN',
            'stable_offer_id' => 'ofr_test_broken',
            'offer_identity_key' => 'SKU-BROKEN',
            'export_key_hash' => sha1('SKU-BROKEN'),
            'title' => 'Broken Tee',
            'price' => 699,
            'currency' => 'UAH',
            'quantity' => 4,
            'is_available' => true,
            'color' => null,
            'size' => null,
            'images_json' => ['https://example.test/img-broken.jpg'],
            'attributes_snapshot' => [],
            'is_enabled' => true,
        ]);

        $generation = app(FeedBuildServiceInterface::class)->build($feedProfile->fresh());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid ratio 0.50 exceeds the configured maximum 0.20.');

        app(FeedPublishServiceInterface::class)->publish($feedProfile->fresh(), $generation);
    }

    public function test_manual_force_publish_bypasses_guardrails(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));

        ['feedProfile' => $feedProfile] = $this->seedBuildableCatalog();
        $feedProfile->update([
            'settings' => array_merge($feedProfile->settings ?? [], [
                'publish_guard_enabled' => true,
                'minimum_ready_items' => 2,
                'maximum_invalid_ratio' => 1,
                'block_publish_on_critical_conformance' => false,
                'minimum_pictures' => 1,
            ]),
        ]);

        $generation = app(FeedBuildServiceInterface::class)->build($feedProfile->fresh());
        $published = app(FeedPublishServiceInterface::class)->publish($feedProfile->fresh(), $generation, true);

        $this->assertNotNull($published->published_at);
        $this->assertTrue($published->meta['publish_guard']['forced']);
    }
}
