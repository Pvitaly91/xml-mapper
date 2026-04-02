<?php

namespace Tests\Feature\Admin;

use App\Contracts\Feeds\FeedBuildServiceInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAdminContext;
use Tests\TestCase;

class UnresolvedMappingsWorkbenchTest extends TestCase
{
    use CreatesAdminContext;
    use RefreshDatabase;

    public function test_workbench_aggregates_problem_counts_and_filters_items(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));

        ['admin' => $admin, 'feedProfile' => $feedProfile, 'variant' => $variant] = $this->seedBuildableCatalog();
        $variant->update([
            'color' => null,
            'size' => null,
        ]);
        app(FeedBuildServiceInterface::class)->build($feedProfile);

        $this->actingAs($admin)
            ->get(route('admin.feed-profiles.workbench.index', ['feed_profile' => $feedProfile, 'problem' => 'invalid_color_size']))
            ->assertOk()
            ->assertSee('Invalid color or size')
            ->assertSee($variant->stable_offer_id);
    }

    public function test_workbench_bulk_confirmation_screen_renders(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));

        ['admin' => $admin, 'feedProfile' => $feedProfile] = $this->seedBuildableCatalog();
        app(FeedBuildServiceInterface::class)->build($feedProfile);
        $feedItem = $feedProfile->items()->firstOrFail();

        $this->actingAs($admin)
            ->post(route('admin.feed-profiles.workbench.bulk-confirm', $feedProfile), [
                'operation' => 'exclude_items',
                'feed_item_ids' => [$feedItem->id],
                'reason' => 'Exclude unresolved item',
            ])
            ->assertOk()
            ->assertSee('Confirm Bulk Action')
            ->assertSee('Exclude selected items');
    }

    public function test_workbench_can_apply_exact_match_value_suggestions(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));

        ['admin' => $admin, 'feedProfile' => $feedProfile, 'kastaCategory' => $kastaCategory, 'connection' => $connection, 'sourceCategory' => $sourceCategory] = $this->seedBuildableCatalog();
        $sourceAttribute = $this->createSourceAttribute($connection, 'Color', 'color', true);
        $sourceValue = $this->createSourceAttributeValue($sourceAttribute, 'Black');
        $kastaAttribute = $this->createKastaAttribute($kastaCategory, 'Color', 'color', true, false);
        $kastaValue = $this->createKastaAttributeValue($kastaAttribute, 'Black');

        \App\Models\AttributeMapping::create([
            'shop_id' => $feedProfile->shop_id,
            'source_connection_id' => $feedProfile->source_connection_id,
            'feed_profile_id' => $feedProfile->id,
            'source_category_id' => $sourceCategory->id,
            'source_attribute_id' => $sourceAttribute->id,
            'kasta_category_id' => $kastaCategory->id,
            'kasta_attribute_id' => $kastaAttribute->id,
            'mapping_strategy' => \App\Models\AttributeMapping::STRATEGY_MANUAL,
            'is_required' => true,
            'use_variant_value' => true,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.feed-profiles.workbench.value-suggestions', $feedProfile))
            ->assertRedirect();

        $this->assertDatabaseHas('value_mappings', [
            'feed_profile_id' => $feedProfile->id,
            'source_raw_value' => $sourceValue->raw_value,
            'kasta_attribute_value_id' => $kastaValue->id,
        ]);
    }
}
