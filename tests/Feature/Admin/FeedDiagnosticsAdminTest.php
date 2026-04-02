<?php

namespace Tests\Feature\Admin;

use App\Contracts\Feeds\FeedBuildServiceInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAdminContext;
use Tests\TestCase;

class FeedDiagnosticsAdminTest extends TestCase
{
    use CreatesAdminContext;
    use RefreshDatabase;

    public function test_diagnostics_page_renders(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));

        ['admin' => $admin, 'feedProfile' => $feedProfile] = $this->seedBuildableCatalog();
        app(FeedBuildServiceInterface::class)->build($feedProfile);
        $feedItem = $feedProfile->items()->firstOrFail();

        $this->actingAs($admin)
            ->get(route('admin.feed-profiles.feed-items.show', [$feedProfile, $feedItem]))
            ->assertOk()
            ->assertSee('XML Preview')
            ->assertSee('Normalized Export Snapshot')
            ->assertSee('Required Attribute Diagnostics');
    }

    public function test_feed_profile_show_renders_pilot_readiness_state(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));

        ['admin' => $admin, 'feedProfile' => $feedProfile] = $this->seedBuildableCatalog();
        app(FeedBuildServiceInterface::class)->build($feedProfile);

        $this->actingAs($admin)
            ->get(route('admin.feed-profiles.show', $feedProfile))
            ->assertOk()
            ->assertSee('Pilot Readiness')
            ->assertSee('Generation Preview Summary')
            ->assertSee('Mappings complete');
    }

    public function test_feed_items_index_renders_diagnostic_filters(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));

        ['admin' => $admin, 'feedProfile' => $feedProfile, 'product' => $product, 'variant' => $variant] = $this->seedBuildableCatalog();
        $product->update([
            'primary_image_url' => null,
            'images_json' => [],
        ]);
        $variant->update([
            'images_json' => [],
        ]);
        app(FeedBuildServiceInterface::class)->build($feedProfile);

        $this->actingAs($admin)
            ->get(route('admin.feed-profiles.feed-items.index', ['feed_profile' => $feedProfile, 'diagnostic' => 'missing_images']))
            ->assertOk()
            ->assertSee($variant->stable_offer_id)
            ->assertSee('Missing images');
    }
}
