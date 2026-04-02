<?php

namespace Tests\Feature\Admin;

use App\Models\AttributeMapping;
use App\Models\ValueMapping;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAdminContext;
use Tests\TestCase;

class AttributeAndValueMappingWorkflowTest extends TestCase
{
    use CreatesAdminContext;
    use RefreshDatabase;

    public function test_admin_can_create_update_and_delete_attribute_and_value_mappings(): void
    {
        ['admin' => $admin, 'connection' => $connection, 'feedProfile' => $feedProfile, 'sourceCategory' => $sourceCategory, 'kastaCategory' => $kastaCategory] = $this->seedBuildableCatalog();

        $sourceAttribute = $this->createSourceAttribute($connection, 'Material', 'material');
        $kastaAttribute = $this->createKastaAttribute($kastaCategory, 'Material', 'material');

        $this->actingAs($admin)
            ->post(route('admin.feed-profiles.attribute-mappings.store', $feedProfile), [
                'source_category_id' => $sourceCategory->id,
                'source_attribute_id' => $sourceAttribute->id,
                'kasta_category_id' => $kastaCategory->id,
                'kasta_attribute_id' => $kastaAttribute->id,
                'default_value' => 'Cotton',
                'use_variant_value' => '1',
            ])
            ->assertRedirect();

        $attributeMapping = AttributeMapping::query()
            ->where('feed_profile_id', $feedProfile->id)
            ->where('source_attribute_id', $sourceAttribute->id)
            ->firstOrFail();

        $this->actingAs($admin)
            ->put(route('admin.feed-profiles.attribute-mappings.update', [$feedProfile, $attributeMapping]), [
                'source_category_id' => $sourceCategory->id,
                'source_attribute_id' => $sourceAttribute->id,
                'kasta_category_id' => $kastaCategory->id,
                'kasta_attribute_id' => $kastaAttribute->id,
                'default_value' => 'Polyester',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('attribute_mappings', [
            'id' => $attributeMapping->id,
            'default_value' => 'Polyester',
        ]);

        $sourceValue = $this->createSourceAttributeValue($sourceAttribute, 'Cotton');
        $targetValue = $this->createKastaAttributeValue($kastaAttribute, 'Cotton');

        $this->actingAs($admin)
            ->post(route('admin.feed-profiles.value-mappings.store', [$feedProfile, $attributeMapping]), [
                'source_attribute_value_id' => $sourceValue->id,
                'source_raw_value' => 'Cotton',
                'kasta_attribute_value_id' => $targetValue->id,
                'is_active' => '1',
            ])
            ->assertRedirect();

        $valueMapping = ValueMapping::query()
            ->where('attribute_mapping_id', $attributeMapping->id)
            ->where('source_raw_value', 'Cotton')
            ->firstOrFail();

        $this->actingAs($admin)
            ->put(route('admin.feed-profiles.value-mappings.update', [$feedProfile, $attributeMapping, $valueMapping]), [
                'source_attribute_value_id' => $sourceValue->id,
                'source_raw_value' => 'Cotton',
                'target_value' => 'Organic Cotton',
                'is_active' => '1',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('value_mappings', [
            'id' => $valueMapping->id,
            'target_value' => 'Organic Cotton',
        ]);

        $this->actingAs($admin)
            ->delete(route('admin.feed-profiles.value-mappings.destroy', [$feedProfile, $attributeMapping, $valueMapping]))
            ->assertRedirect();

        $this->assertDatabaseMissing('value_mappings', ['id' => $valueMapping->id]);

        $this->actingAs($admin)
            ->delete(route('admin.feed-profiles.attribute-mappings.destroy', [$feedProfile, $attributeMapping]))
            ->assertRedirect();

        $this->assertDatabaseMissing('attribute_mappings', ['id' => $attributeMapping->id]);
    }

    public function test_admin_can_apply_exact_match_attribute_and_value_suggestions(): void
    {
        ['admin' => $admin, 'connection' => $connection, 'feedProfile' => $feedProfile, 'sourceCategory' => $sourceCategory, 'kastaCategory' => $kastaCategory] = $this->seedBuildableCatalog();

        $sourceColor = $this->createSourceAttribute($connection, 'Color', 'color', true);
        $kastaColor = $this->createKastaAttribute($kastaCategory, 'Color', 'color', true, false);
        $sourceColorValue = $this->createSourceAttributeValue($sourceColor, 'Black');
        $targetColorValue = $this->createKastaAttributeValue($kastaColor, 'Black');

        $this->actingAs($admin)
            ->post(route('admin.feed-profiles.attribute-mappings.suggestions', $feedProfile), [
                'source_category_id' => $sourceCategory->id,
                'source_attribute_ids' => [$sourceColor->id],
            ])
            ->assertRedirect();

        $attributeMapping = AttributeMapping::query()
            ->where('feed_profile_id', $feedProfile->id)
            ->where('source_attribute_id', $sourceColor->id)
            ->firstOrFail();

        $this->actingAs($admin)
            ->post(route('admin.feed-profiles.value-mappings.suggestions', [$feedProfile, $attributeMapping]), [
                'source_attribute_value_ids' => [$sourceColorValue->id],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('value_mappings', [
            'attribute_mapping_id' => $attributeMapping->id,
            'source_attribute_value_id' => $sourceColorValue->id,
            'kasta_attribute_value_id' => $targetColorValue->id,
            'mapping_strategy' => ValueMapping::STRATEGY_NORMALIZED_EXACT,
        ]);
    }
}
