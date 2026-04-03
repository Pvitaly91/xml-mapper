<?php

namespace Tests\Feature\Admin;

use App\Models\FeedGeneration;
use App\Models\FeedProfile;
use App\Services\Feeds\FeedReleaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAdminContext;
use Tests\TestCase;

class FeedProfileWorkflowTest extends TestCase
{
    use CreatesAdminContext;
    use RefreshDatabase;

    public function test_admin_can_create_and_update_feed_profile(): void
    {
        $shop = $this->createShop();
        $admin = $this->createAdminUser($shop);
        $connection = $this->createSourceConnection($shop);

        $this->actingAs($admin)
            ->post(route('admin.feed-profiles.store'), [
                'source_connection_id' => $connection->id,
                'name' => 'Kasta Main',
                'code' => 'kasta-main',
                'status' => FeedProfile::STATUS_ACTIVE,
                'currency' => 'UAH',
                'language' => 'uk',
                'include_unavailable' => '1',
                'auto_sync' => '1',
                'auto_build' => '1',
                'publish_guard_enabled' => '1',
                'block_publish_on_critical_conformance' => '1',
                'build_interval_minutes' => 30,
                'minimum_ready_items' => 10,
                'maximum_invalid_ratio' => 0.15,
                'minimum_pictures' => 2,
                'settings_json' => json_encode(['channel' => 'main'], JSON_THROW_ON_ERROR),
            ])
            ->assertRedirect();

        $feedProfile = FeedProfile::query()->where('code', 'kasta-main')->firstOrFail();

        $this->actingAs($admin)
            ->put(route('admin.feed-profiles.update', $feedProfile), [
                'source_connection_id' => $connection->id,
                'name' => 'Kasta Main Updated',
                'code' => 'kasta-main',
                'status' => FeedProfile::STATUS_INACTIVE,
                'currency' => 'UAH',
                'language' => 'uk',
                'build_interval_minutes' => 45,
                'publish_guard_enabled' => '1',
                'minimum_ready_items' => 5,
                'maximum_invalid_ratio' => 0.25,
                'minimum_pictures' => 3,
                'settings_json' => json_encode(['channel' => 'secondary'], JSON_THROW_ON_ERROR),
            ])
            ->assertRedirect(route('admin.feed-profiles.show', $feedProfile));

        $this->assertDatabaseHas('feed_profiles', [
            'id' => $feedProfile->id,
            'name' => 'Kasta Main Updated',
            'status' => FeedProfile::STATUS_INACTIVE,
            'build_interval_minutes' => 45,
        ]);
        $settings = $feedProfile->fresh()->settings;

        $this->assertSame('secondary', $settings['channel']);
        $this->assertTrue($settings['publish_guard_enabled']);
        $this->assertSame(5, $settings['minimum_ready_items']);
        $this->assertSame(0.25, $settings['maximum_invalid_ratio']);
        $this->assertFalse($settings['block_publish_on_critical_conformance']);
        $this->assertSame(3, $settings['minimum_pictures']);
        $this->assertArrayHasKey('signoff_required', $settings);
        $this->assertArrayHasKey('publish_window_enabled', $settings);
        $this->assertArrayHasKey('freeze_mode', $settings);
    }

    public function test_admin_can_build_and_publish_feed_profile_manually(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));

        ['admin' => $admin, 'feedProfile' => $feedProfile] = $this->seedBuildableCatalog();

        $this->actingAs($admin)
            ->post(route('admin.feed-profiles.build', $feedProfile))
            ->assertRedirect();

        $generation = FeedGeneration::query()->where('feed_profile_id', $feedProfile->id)->latest('id')->firstOrFail();

        $this->assertSame(FeedGeneration::STATUS_BUILT, $generation->status);
        Storage::disk(config('feed_mediator.storage_disk'))->assertExists($generation->file_path);

        $releaseService = app(FeedReleaseService::class);
        $releaseService->markCandidate($generation);
        $releaseService->approve($generation->fresh());

        $this->actingAs($admin)
            ->post(route('admin.feed-profiles.publish', $feedProfile))
            ->assertRedirect();

        $this->assertDatabaseHas('feed_generations', [
            'id' => $generation->id,
            'status' => FeedGeneration::STATUS_PUBLISHED,
        ]);
        $this->assertNotNull($feedProfile->fresh()->published_generation_id);
    }
}
