<?php

namespace Tests\Feature\Admin;

use App\Models\CategoryMapping;
use App\Models\SourceCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAdminContext;
use Tests\TestCase;

class CategoryMappingWorkflowTest extends TestCase
{
    use CreatesAdminContext;
    use RefreshDatabase;

    public function test_admin_can_run_category_automap_by_rz_id(): void
    {
        $shop = $this->createShop();
        $admin = $this->createAdminUser($shop);
        $connection = $this->createSourceConnection($shop);
        $feedProfile = $this->createFeedProfile($connection, $admin);
        $sourceCategory = SourceCategory::create([
            'shop_id' => $shop->id,
            'source_connection_id' => $connection->id,
            'external_id' => '101',
            'name' => 'T-Shirts',
            'full_path' => 'Apparel > T-Shirts',
            'rz_id' => '2001',
            'is_active' => true,
        ]);
        $this->createKastaCategory([
            'external_id' => 'KASTA-TSHIRTS',
            'rz_id' => '2001',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.feed-profiles.category-mappings.automap', $feedProfile), [
                'source_category_ids' => [$sourceCategory->id],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('category_mappings', [
            'feed_profile_id' => $feedProfile->id,
            'source_category_id' => $sourceCategory->id,
            'mapping_strategy' => CategoryMapping::STRATEGY_RZ_ID,
            'is_active' => true,
        ]);
    }

    public function test_admin_can_create_update_deactivate_and_delete_manual_category_mapping(): void
    {
        ['admin' => $admin, 'connection' => $connection, 'feedProfile' => $feedProfile] = $this->seedBuildableCatalog();
        $sourceCategory = SourceCategory::create([
            'shop_id' => $feedProfile->shop_id,
            'source_connection_id' => $connection->id,
            'external_id' => '202',
            'name' => 'Other Shirts',
            'full_path' => 'Apparel > Other Shirts',
            'is_active' => true,
        ]);
        $otherCategory = $this->createKastaCategory([
            'external_id' => 'KASTA-OTHER',
            'rz_id' => '9999',
            'name' => 'Other',
            'full_path' => 'Apparel > Other',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.feed-profiles.category-mappings.store', $feedProfile), [
                'source_category_id' => $sourceCategory->id,
                'kasta_category_id' => $otherCategory->id,
                'is_active' => '1',
            ])
            ->assertRedirect();

        $mapping = CategoryMapping::query()
            ->where('feed_profile_id', $feedProfile->id)
            ->where('source_category_id', $sourceCategory->id)
            ->firstOrFail();

        $this->actingAs($admin)
            ->put(route('admin.feed-profiles.category-mappings.update', [$feedProfile, $mapping]), [
                'source_category_id' => $sourceCategory->id,
                'kasta_category_id' => $otherCategory->id,
                'is_active' => '1',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('category_mappings', [
            'id' => $mapping->id,
            'kasta_category_id' => $otherCategory->id,
            'mapping_strategy' => CategoryMapping::STRATEGY_MANUAL,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.feed-profiles.category-mappings.deactivate', [$feedProfile, $mapping]))
            ->assertRedirect();

        $this->assertDatabaseHas('category_mappings', [
            'id' => $mapping->id,
            'is_active' => false,
        ]);

        $this->actingAs($admin)
            ->delete(route('admin.feed-profiles.category-mappings.destroy', [$feedProfile, $mapping]))
            ->assertRedirect();

        $this->assertDatabaseMissing('category_mappings', ['id' => $mapping->id]);
    }
}
