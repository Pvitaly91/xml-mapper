<?php

namespace Tests\Feature\Admin;

use App\Contracts\Feeds\FeedBuildServiceInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAdminContext;
use Tests\TestCase;

class MultiShopIsolationTest extends TestCase
{
    use CreatesAdminContext;
    use RefreshDatabase;

    public function test_admin_cannot_access_another_shops_feed_profile_resources(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));

        ['admin' => $owner, 'feedProfile' => $feedProfile] = $this->seedBuildableCatalog();
        $generation = app(FeedBuildServiceInterface::class)->build($feedProfile);

        $otherShop = $this->createShop(['slug' => 'other-shop']);
        $otherAdmin = $this->createAdminUser($otherShop, ['email' => 'other@example.com']);

        $this->actingAs($otherAdmin)
            ->get(route('admin.feed-profiles.workbench.index', $feedProfile))
            ->assertNotFound();

        $this->actingAs($otherAdmin)
            ->get(route('admin.feed-profiles.mapping-presets.export', $feedProfile))
            ->assertNotFound();

        $this->actingAs($otherAdmin)
            ->get(route('admin.feed-profiles.generations.reports.diff', [$feedProfile, $generation]))
            ->assertNotFound();

        $this->actingAs($otherAdmin)
            ->get(route('admin.feed-profiles.acceptance.show', ['feed_profile' => $feedProfile, 'generation_id' => $generation->id]))
            ->assertNotFound();

        $this->actingAs($otherAdmin)
            ->get(route('admin.feed-profiles.generations.qa-bundle', [$feedProfile, $generation]))
            ->assertNotFound();

        $this->actingAs($otherAdmin)
            ->post(route('admin.feed-profiles.publish', $feedProfile), [
                'generation_id' => $generation->id,
            ])
            ->assertNotFound();
    }

    public function test_admin_cannot_open_another_shops_source_connection(): void
    {
        ['connection' => $connection] = $this->seedBuildableCatalog();

        $otherShop = $this->createShop(['slug' => 'other-shop']);
        $otherAdmin = $this->createAdminUser($otherShop, ['email' => 'other@example.com']);

        $this->actingAs($otherAdmin)
            ->get(route('admin.source-connections.show', $connection))
            ->assertNotFound();
    }
}
