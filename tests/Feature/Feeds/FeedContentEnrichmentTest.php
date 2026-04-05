<?php

namespace Tests\Feature\Feeds;

use App\Actions\Admin\FeedItems\SaveFeedItemContentOverrideAction;
use App\Contracts\Feeds\FeedBuildServiceInterface;
use App\Models\FeedItem;
use App\Services\Feeds\FeedItemDiagnosticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAdminContext;
use Tests\TestCase;

class FeedContentEnrichmentTest extends TestCase
{
    use CreatesAdminContext;
    use RefreshDatabase;

    public function test_title_description_and_image_rules_are_deterministic(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));

        ['feedProfile' => $feedProfile, 'product' => $product, 'variant' => $variant] = $this->seedBuildableCatalog();
        $product->update(['description' => null]);
        $variant->update([
            'title' => 'Basic Tee',
            'images_json' => ['invalid-url', 'https://example.test/variant-extra.jpg'],
        ]);

        app(FeedBuildServiceInterface::class)->build($feedProfile);

        $analysis = app(FeedItemDiagnosticsService::class)->analyze($feedProfile, $product->fresh(), $variant->fresh());

        $this->assertSame('Acme Basic Tee Black S', $analysis['normalized_export_snapshot']['name']);
        $this->assertStringContainsString('Артикул: TSHIRT-001', (string) $analysis['normalized_export_snapshot']['description']);
        $this->assertSame([
            'https://example.test/variant-extra.jpg',
            'https://example.test/img-1.jpg',
        ], $analysis['normalized_export_snapshot']['pictures']);
        $this->assertContains('description_fallback', collect($analysis['warnings'])->pluck('code')->all());
    }

    public function test_manual_content_override_persists_across_rebuilds(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));

        ['feedProfile' => $feedProfile, 'product' => $product, 'variant' => $variant] = $this->seedBuildableCatalog();
        app(FeedBuildServiceInterface::class)->build($feedProfile);

        $feedItem = FeedItem::query()->where('feed_profile_id', $feedProfile->id)->firstOrFail();

        app(SaveFeedItemContentOverrideAction::class)->handle($feedProfile, $feedItem, [
            'title' => 'Manual Tee Title',
            'description' => 'Manual merchant description',
            'images' => [
                'https://example.test/manual-1.jpg',
                'https://example.test/manual-2.jpg',
            ],
            'reason' => 'Manual merchandising override',
        ]);

        $product->update(['description' => 'Source sync changed description']);
        $variant->update(['title' => 'Source sync changed title']);
        app(FeedBuildServiceInterface::class)->build($feedProfile->fresh());

        $analysis = app(FeedItemDiagnosticsService::class)->analyze(
            $feedProfile->fresh(),
            $product->fresh(),
            $variant->fresh(),
            $feedItem->fresh()
        );

        $this->assertSame('Manual Tee Title', $analysis['normalized_export_snapshot']['name']);
        $this->assertSame('Manual merchant description', $analysis['normalized_export_snapshot']['description']);
        $this->assertSame([
            'https://example.test/manual-1.jpg',
            'https://example.test/manual-2.jpg',
        ], $analysis['normalized_export_snapshot']['pictures']);
        $this->assertTrue((bool) data_get($analysis, 'enrichment.manual_override_active'));
    }
}
