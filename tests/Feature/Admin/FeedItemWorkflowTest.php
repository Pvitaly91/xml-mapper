<?php

namespace Tests\Feature\Admin;

use App\Contracts\Feeds\FeedBuildServiceInterface;
use App\Models\FeedItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAdminContext;
use Tests\TestCase;

class FeedItemWorkflowTest extends TestCase
{
    use CreatesAdminContext;
    use RefreshDatabase;

    public function test_admin_can_disable_and_enable_feed_item(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));

        ['admin' => $admin, 'feedProfile' => $feedProfile] = $this->seedBuildableCatalog();
        app(FeedBuildServiceInterface::class)->build($feedProfile);

        $feedItem = FeedItem::query()->where('feed_profile_id', $feedProfile->id)->firstOrFail();

        $this->actingAs($admin)
            ->post(route('admin.feed-profiles.feed-items.bulk', $feedProfile), [
                'feed_item_ids' => [$feedItem->id],
                'operation' => 'disable',
                'reason' => 'Disabled in admin',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('feed_items', [
            'id' => $feedItem->id,
            'status' => FeedItem::STATUS_EXCLUDED,
            'is_enabled' => false,
        ]);
        $this->assertDatabaseHas('source_variants', [
            'id' => $feedItem->source_variant_id,
            'is_enabled' => false,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.feed-profiles.feed-items.bulk', $feedProfile), [
                'feed_item_ids' => [$feedItem->id],
                'operation' => 'enable',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('feed_items', [
            'id' => $feedItem->id,
            'is_enabled' => true,
        ]);
        $this->assertDatabaseHas('source_variants', [
            'id' => $feedItem->source_variant_id,
            'is_enabled' => true,
        ]);
    }

    public function test_admin_can_save_manual_feed_item_override(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));

        ['admin' => $admin, 'feedProfile' => $feedProfile] = $this->seedBuildableCatalog();
        app(FeedBuildServiceInterface::class)->build($feedProfile);

        $feedItem = FeedItem::query()->where('feed_profile_id', $feedProfile->id)->firstOrFail();

        $this->actingAs($admin)
            ->put(route('admin.feed-profiles.feed-items.override', [$feedProfile, $feedItem]), [
                'is_enabled' => '0',
                'excluded_reason' => 'Manual exclude',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('feed_items', [
            'id' => $feedItem->id,
            'is_manual_override' => true,
            'is_enabled' => false,
            'excluded_reason' => 'Manual exclude',
        ]);
    }
}
