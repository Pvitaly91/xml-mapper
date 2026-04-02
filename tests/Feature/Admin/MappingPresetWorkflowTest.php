<?php

namespace Tests\Feature\Admin;

use App\Models\AttributeMapping;
use App\Models\CategoryMapping;
use App\Models\SourceCategory;
use App\Models\ValueMapping;
use App\Services\Shops\MappingPresetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAdminContext;
use Tests\TestCase;

class MappingPresetWorkflowTest extends TestCase
{
    use CreatesAdminContext;
    use RefreshDatabase;

    public function test_mapping_preset_export_and_import_dry_run_work(): void
    {
        ['admin' => $sourceAdmin, 'feedProfile' => $sourceProfile, 'connection' => $sourceConnection, 'sourceCategory' => $sourceCategory, 'kastaCategory' => $kastaCategory] = $this->seedBuildableCatalog();
        $sourceAttribute = $this->createSourceAttribute($sourceConnection, 'Color', 'color', true);
        $sourceAttributeValue = $this->createSourceAttributeValue($sourceAttribute, 'Black');
        $kastaAttribute = $this->createKastaAttribute($kastaCategory, 'Color', 'color', true, false);
        $kastaAttributeValue = $this->createKastaAttributeValue($kastaAttribute, 'Black');

        $attributeMapping = AttributeMapping::create([
            'shop_id' => $sourceProfile->shop_id,
            'source_connection_id' => $sourceProfile->source_connection_id,
            'feed_profile_id' => $sourceProfile->id,
            'source_category_id' => $sourceCategory->id,
            'source_attribute_id' => $sourceAttribute->id,
            'kasta_category_id' => $kastaCategory->id,
            'kasta_attribute_id' => $kastaAttribute->id,
            'mapping_strategy' => AttributeMapping::STRATEGY_MANUAL,
            'is_required' => true,
            'use_variant_value' => true,
        ]);

        ValueMapping::create([
            'shop_id' => $sourceProfile->shop_id,
            'feed_profile_id' => $sourceProfile->id,
            'attribute_mapping_id' => $attributeMapping->id,
            'source_attribute_value_id' => $sourceAttributeValue->id,
            'kasta_attribute_value_id' => $kastaAttributeValue->id,
            'source_raw_value' => 'Black',
            'normalized_source_value' => 'black',
            'target_value' => 'Black',
            'mapping_strategy' => ValueMapping::STRATEGY_MANUAL,
            'is_active' => true,
        ]);

        $response = $this->actingAs($sourceAdmin)->get(route('admin.feed-profiles.mapping-presets.export', $sourceProfile));
        $response->assertOk();
        $presetJson = $response->streamedContent();
        $this->assertStringContainsString('category_mappings', $presetJson);

        $targetShop = $this->createShop(['slug' => 'target-shop']);
        $targetAdmin = $this->createAdminUser($targetShop, ['email' => 'target@example.com']);
        $targetConnection = $this->createSourceConnection($targetShop, ['code' => 'target-source']);
        $targetProfile = $this->createFeedProfile($targetConnection, $targetAdmin, ['code' => 'target-profile']);
        SourceCategory::create([
            'shop_id' => $targetShop->id,
            'source_connection_id' => $targetConnection->id,
            'external_id' => $sourceCategory->external_id,
            'name' => $sourceCategory->name,
            'full_path' => $sourceCategory->full_path,
            'rz_id' => $sourceCategory->rz_id,
            'is_active' => true,
        ]);
        $targetSourceAttribute = $this->createSourceAttribute($targetConnection, 'Color', 'color', true);
        $this->createSourceAttributeValue($targetSourceAttribute, 'Black');

        $this->actingAs($targetAdmin)
            ->post(route('admin.feed-profiles.mapping-presets.preview', $targetProfile), [
                'preset_json' => $presetJson,
                'collision_strategy' => 'skip_existing',
            ])
            ->assertOk()
            ->assertSee('Dry-run Preview');

        $this->actingAs($targetAdmin)
            ->post(route('admin.feed-profiles.mapping-presets.store', $targetProfile), [
                'preset_json' => $presetJson,
                'collision_strategy' => 'skip_existing',
            ])
            ->assertRedirect(route('admin.feed-profiles.mapping-presets.import', $targetProfile));

        $this->assertDatabaseHas('category_mappings', [
            'feed_profile_id' => $targetProfile->id,
            'kasta_category_id' => $kastaCategory->id,
        ]);
        $this->assertDatabaseHas('attribute_mappings', [
            'feed_profile_id' => $targetProfile->id,
            'kasta_attribute_id' => $kastaAttribute->id,
        ]);
        $this->assertDatabaseHas('value_mappings', [
            'feed_profile_id' => $targetProfile->id,
            'target_value' => 'Black',
        ]);
    }

    public function test_mapping_preset_collision_strategies_behave_correctly(): void
    {
        ['feedProfile' => $feedProfile, 'connection' => $connection, 'sourceCategory' => $sourceCategory, 'kastaCategory' => $kastaCategory] = $this->seedBuildableCatalog();
        $sourceAttribute = $this->createSourceAttribute($connection, 'Color', 'color', true);
        $sourceAttributeValue = $this->createSourceAttributeValue($sourceAttribute, 'Black');
        $kastaAttribute = $this->createKastaAttribute($kastaCategory, 'Color', 'color', true, false);
        $kastaAttributeValue = $this->createKastaAttributeValue($kastaAttribute, 'Black');

        $attributeMapping = AttributeMapping::create([
            'shop_id' => $feedProfile->shop_id,
            'source_connection_id' => $feedProfile->source_connection_id,
            'feed_profile_id' => $feedProfile->id,
            'source_category_id' => $sourceCategory->id,
            'source_attribute_id' => $sourceAttribute->id,
            'kasta_category_id' => $kastaCategory->id,
            'kasta_attribute_id' => $kastaAttribute->id,
            'mapping_strategy' => AttributeMapping::STRATEGY_MANUAL,
            'is_required' => true,
            'use_variant_value' => true,
        ]);

        ValueMapping::create([
            'shop_id' => $feedProfile->shop_id,
            'feed_profile_id' => $feedProfile->id,
            'attribute_mapping_id' => $attributeMapping->id,
            'source_attribute_value_id' => $sourceAttributeValue->id,
            'kasta_attribute_value_id' => $kastaAttributeValue->id,
            'source_raw_value' => 'Black',
            'normalized_source_value' => 'black',
            'target_value' => 'Black',
            'mapping_strategy' => ValueMapping::STRATEGY_MANUAL,
            'is_active' => true,
        ]);

        $service = app(MappingPresetService::class);
        $preset = $service->export($feedProfile);
        $newKastaCategory = $this->createKastaCategory([
            'external_id' => 'KASTA-NEW',
            'rz_id' => '9999',
            'name' => 'Jackets',
        ]);
        $newKastaAttribute = $this->createKastaAttribute($newKastaCategory, 'Color', 'color', true, false);
        $newKastaValue = $this->createKastaAttributeValue($newKastaAttribute, 'Graphite');

        $preset['category_mappings'][0]['kasta_category']['external_id'] = $newKastaCategory->external_id;
        $preset['category_mappings'][0]['kasta_category']['rz_id'] = $newKastaCategory->rz_id;
        $preset['attribute_mappings'][0]['kasta_category']['external_id'] = $newKastaCategory->external_id;
        $preset['attribute_mappings'][0]['kasta_attribute']['external_id'] = $newKastaAttribute->external_id;
        $preset['attribute_mappings'][0]['kasta_attribute']['code'] = $newKastaAttribute->code;
        $preset['value_mappings'][0]['kasta_attribute_value']['external_id'] = $newKastaValue->external_id;
        $preset['value_mappings'][0]['kasta_attribute_value']['value'] = $newKastaValue->value;

        $skipPreview = $service->previewImport($feedProfile, $preset, 'skip_existing');
        $overwritePreview = $service->previewImport($feedProfile, $preset, 'overwrite_existing');
        $mergePreview = $service->previewImport($feedProfile, $preset, 'merge_if_safe');

        $this->assertSame(1, $skipPreview['summary']['category_mappings']['skip']);
        $this->assertSame(1, $overwritePreview['summary']['category_mappings']['update']);
        $this->assertSame(1, $mergePreview['summary']['category_mappings']['collisions']);
    }
}
