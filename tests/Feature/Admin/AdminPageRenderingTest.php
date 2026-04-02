<?php

namespace Tests\Feature\Admin;

use App\Contracts\Feeds\FeedBuildServiceInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAdminContext;
use Tests\TestCase;

class AdminPageRenderingTest extends TestCase
{
    use CreatesAdminContext;
    use RefreshDatabase;

    public function test_key_admin_pages_render_successfully(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));

        ['admin' => $admin, 'feedProfile' => $feedProfile, 'sourceCategory' => $sourceCategory] = $this->seedBuildableCatalog();
        $this->actingAs($admin)->post(route('admin.dictionaries.import'));
        app(FeedBuildServiceInterface::class)->build($feedProfile);
        $feedItem = $feedProfile->items()->firstOrFail();

        $this->actingAs($admin)->get(route('admin.source-connections.index'))->assertOk();
        $this->actingAs($admin)->get(route('admin.feed-profiles.index'))->assertOk();
        $this->actingAs($admin)->get(route('admin.dictionaries.index'))->assertOk();
        $this->actingAs($admin)->get(route('admin.dictionaries.categories'))->assertOk();
        $this->actingAs($admin)->get(route('admin.feed-profiles.category-mappings.index', $feedProfile))->assertOk();
        $this->actingAs($admin)->get(route('admin.feed-profiles.attribute-mappings.index', ['feed_profile' => $feedProfile, 'source_category_id' => $sourceCategory->id]))->assertOk();
        $this->actingAs($admin)->get(route('admin.feed-profiles.value-mappings.index', $feedProfile))->assertOk();
        $this->actingAs($admin)->get(route('admin.feed-profiles.feed-items.index', $feedProfile))->assertOk();
        $this->actingAs($admin)->get(route('admin.feed-profiles.feed-items.show', [$feedProfile, $feedItem]))->assertOk();
    }
}
