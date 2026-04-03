<?php

namespace Tests\Feature\Feeds;

use App\Contracts\Feeds\FeedBuildServiceInterface;
use App\Models\FeedItem;
use App\Models\ValidationError;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAdminContext;
use Tests\TestCase;

class FeedProfileOverrideTest extends TestCase
{
    use CreatesAdminContext;
    use RefreshDatabase;

    public function test_profile_without_overrides_keeps_item_ready(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));

        ['feedProfile' => $feedProfile] = $this->seedBuildableCatalog();
        app(FeedBuildServiceInterface::class)->build($feedProfile);

        $this->assertDatabaseHas('feed_items', [
            'feed_profile_id' => $feedProfile->id,
            'status' => FeedItem::STATUS_READY,
        ]);
    }

    public function test_minimum_price_and_excluded_vendor_overrides_affect_export_behavior(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));

        ['feedProfile' => $feedProfile] = $this->seedBuildableCatalog();

        $feedProfile->update([
            'settings' => array_merge($feedProfile->exportSettings(), [
                'minimum_price_threshold' => 1000,
                'excluded_vendors' => ['Acme'],
            ]),
        ]);

        app(FeedBuildServiceInterface::class)->build($feedProfile->fresh());

        $this->assertDatabaseHas('feed_items', [
            'feed_profile_id' => $feedProfile->id,
            'status' => FeedItem::STATUS_EXCLUDED,
        ]);
        $this->assertDatabaseHas('validation_errors', [
            'feed_profile_id' => $feedProfile->id,
            'code' => ValidationError::CODE_PRICE_BELOW_THRESHOLD,
        ]);
    }
}
