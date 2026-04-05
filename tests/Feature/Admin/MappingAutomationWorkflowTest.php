<?php

namespace Tests\Feature\Admin;

use App\Models\ApprovalRequest;
use App\Models\AttributeMapping;
use App\Models\CategoryMapping;
use App\Models\FeedbackImport;
use App\Models\FeedbackRecord;
use App\Models\FeedItem;
use App\Models\MappingBatch;
use App\Models\SourceCategory;
use App\Models\SourceProduct;
use App\Models\SourceVariant;
use App\Models\ValidationError;
use App\Models\ValueMapping;
use App\Services\Feeds\FeedItemDiagnosticsService;
use App\Services\Governance\ApprovalPolicyService;
use App\Services\Governance\GovernedActionService;
use App\Services\Mappings\Automation\FeedItemMappingExceptionService;
use App\Services\Mappings\Automation\MappingBatchService;
use App\Services\Mappings\Automation\MappingFeedbackRecommendationService;
use App\Services\Mappings\Automation\MappingTemplateLibraryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAdminContext;
use Tests\TestCase;

class MappingAutomationWorkflowTest extends TestCase
{
    use CreatesAdminContext;
    use RefreshDatabase;

    public function test_mapping_batch_dry_run_apply_and_rollback_work(): void
    {
        $shop = $this->createShop();
        $admin = $this->createAdminUser($shop);
        $connection = $this->createSourceConnection($shop);
        $feedProfile = $this->createFeedProfile($connection, $admin);

        $sourceCategory = SourceCategory::create([
            'shop_id' => $shop->id,
            'source_connection_id' => $connection->id,
            'external_id' => 'cat-1',
            'name' => 'T-Shirts',
            'full_path' => 'Apparel > T-Shirts',
            'rz_id' => '2001',
            'is_active' => true,
        ]);
        $this->createKastaCategory([
            'external_id' => 'KASTA-TS',
            'name' => 'T-Shirts',
            'full_path' => 'Apparel > T-Shirts',
            'rz_id' => '2001',
        ]);

        SourceProduct::create([
            'shop_id' => $shop->id,
            'source_connection_id' => $connection->id,
            'source_category_id' => $sourceCategory->id,
            'group_key' => 'grp-1',
            'name' => 'Basic Tee',
            'vendor' => 'Acme',
            'article' => 'ART-1',
            'brand' => 'Acme',
            'is_active' => true,
        ]);

        $batchService = app(MappingBatchService::class);
        $dryRun = $batchService->planSuggestionBatch($feedProfile, 'category', 0.9, 'safe', [], $admin, true);

        $this->assertSame(MappingBatch::STATUS_DRY_RUN, $dryRun->status);
        $this->assertSame(0, CategoryMapping::query()->where('feed_profile_id', $feedProfile->id)->count());

        $batch = $batchService->planSuggestionBatch($feedProfile, 'category', 0.9, 'safe', [], $admin, false);
        $batch = $batchService->executeBatch($batch, $admin);

        $mapping = CategoryMapping::query()->where('feed_profile_id', $feedProfile->id)->first();
        $this->assertNotNull($mapping);
        $this->assertSame(MappingBatch::STATUS_APPLIED, $batch->status);
        $this->assertSame('auto_rz_id', $mapping->mapping_strategy);

        $batchService->rollback($batch, $admin, 'Rollback test');
        $this->assertDatabaseMissing('category_mappings', ['feed_profile_id' => $feedProfile->id, 'source_category_id' => $sourceCategory->id]);
    }

    public function test_risky_auto_apply_creates_approval_request_in_production(): void
    {
        config([
            'feed_mediator.environment.class' => 'production',
            'feed_mediator.mapping_automation.bulk.high_volume_count' => 1,
        ]);

        $shop = $this->createShop();
        $admin = $this->createAdminUser($shop, [
            'mfa_secret' => 'encrypted-secret',
            'mfa_enabled_at' => now(),
        ]);
        $connection = $this->createSourceConnection($shop);
        $feedProfile = $this->createFeedProfile($connection, $admin);

        SourceCategory::create([
            'shop_id' => $shop->id,
            'source_connection_id' => $connection->id,
            'external_id' => 'cat-1',
            'name' => 'T-Shirts',
            'full_path' => 'Apparel > T-Shirts',
            'rz_id' => '2001',
            'is_active' => true,
        ]);
        $this->createKastaCategory([
            'external_id' => 'KASTA-TS',
            'name' => 'T-Shirts',
            'full_path' => 'Apparel > T-Shirts',
            'rz_id' => '2001',
        ]);

        $batch = app(MappingBatchService::class)->planSuggestionBatch($feedProfile, 'category', 0.9, 'safe', [], $admin, false);
        $result = app(GovernedActionService::class)->dispatch(
            ApprovalPolicyService::ACTION_MAPPING_BULK_APPLY,
            $admin,
            $shop,
            $feedProfile,
            ['mapping_batch_id' => $batch->id],
            ['batch_id' => $batch->id, 'risk_level' => $batch->risk_level],
            'Production mapping batch'
        );

        $this->assertSame('approval_required', $result->status);
        $this->assertDatabaseHas('approval_requests', [
            'id' => $result->approvalRequest?->id,
            'action' => ApprovalPolicyService::ACTION_MAPPING_BULK_APPLY,
            'status' => ApprovalRequest::STATUS_PENDING,
        ]);
    }

    public function test_template_apply_preserves_manual_mapping_until_overwrite_is_explicit(): void
    {
        $shop = $this->createShop();
        $admin = $this->createAdminUser($shop);
        $connection = $this->createSourceConnection($shop);
        $feedProfile = $this->createFeedProfile($connection, $admin);
        $sourceCategory = SourceCategory::create([
            'shop_id' => $shop->id,
            'source_connection_id' => $connection->id,
            'external_id' => 'cat-1',
            'name' => 'T-Shirts',
            'full_path' => 'Apparel > T-Shirts',
            'is_active' => true,
        ]);
        $firstCategory = $this->createKastaCategory(['external_id' => 'KASTA-A', 'name' => 'Category A', 'full_path' => 'Apparel > Category A']);
        $secondCategory = $this->createKastaCategory(['external_id' => 'KASTA-B', 'name' => 'Category B', 'full_path' => 'Apparel > Category B']);

        CategoryMapping::create([
            'shop_id' => $shop->id,
            'source_connection_id' => $connection->id,
            'feed_profile_id' => $feedProfile->id,
            'source_category_id' => $sourceCategory->id,
            'kasta_category_id' => $firstCategory->id,
            'mapping_strategy' => CategoryMapping::STRATEGY_MANUAL,
            'is_active' => true,
        ]);

        $payload = [
            'category_mappings' => [[
                'source_category' => ['external_id' => 'cat-1', 'name' => 'T-Shirts', 'full_path' => 'Apparel > T-Shirts'],
                'kasta_category' => ['external_id' => 'KASTA-B', 'name' => 'Category B'],
                'mapping_strategy' => 'auto_exact_normalized',
                'is_active' => true,
            ]],
            'attribute_mappings' => [],
            'value_mappings' => [],
            'rules' => [],
        ];

        $service = app(MappingTemplateLibraryService::class);
        $preview = $service->previewApply($feedProfile, $payload, 'skip_existing');
        $this->assertSame(1, $preview['mapping_plan']['summary']['category_mappings']['skip']);

        $service->applyPayload($feedProfile, $payload, 'skip_existing', $admin);
        $this->assertDatabaseHas('category_mappings', [
            'feed_profile_id' => $feedProfile->id,
            'source_category_id' => $sourceCategory->id,
            'kasta_category_id' => $firstCategory->id,
            'mapping_strategy' => CategoryMapping::STRATEGY_MANUAL,
        ]);

        $service->applyPayload($feedProfile, $payload, 'overwrite_existing', $admin);
        $this->assertDatabaseHas('category_mappings', [
            'feed_profile_id' => $feedProfile->id,
            'source_category_id' => $sourceCategory->id,
            'kasta_category_id' => $secondCategory->id,
        ]);
    }

    public function test_item_level_exceptions_are_visible_in_diagnostics_and_feedback_patterns_generate_recommendations(): void
    {
        $shop = $this->createShop();
        $admin = $this->createAdminUser($shop);
        $connection = $this->createSourceConnection($shop);
        $feedProfile = $this->createFeedProfile($connection, $admin);
        $sourceCategory = SourceCategory::create([
            'shop_id' => $shop->id,
            'source_connection_id' => $connection->id,
            'external_id' => 'cat-1',
            'name' => 'T-Shirts',
            'full_path' => 'Apparel > T-Shirts',
            'is_active' => true,
        ]);
        $mappedCategory = $this->createKastaCategory(['external_id' => 'KASTA-A', 'name' => 'Category A', 'full_path' => 'Apparel > Category A']);
        $overrideCategory = $this->createKastaCategory(['external_id' => 'KASTA-B', 'name' => 'Category B', 'full_path' => 'Apparel > Category B']);
        CategoryMapping::create([
            'shop_id' => $shop->id,
            'source_connection_id' => $connection->id,
            'feed_profile_id' => $feedProfile->id,
            'source_category_id' => $sourceCategory->id,
            'kasta_category_id' => $mappedCategory->id,
            'mapping_strategy' => CategoryMapping::STRATEGY_MANUAL,
            'is_active' => true,
        ]);

        $colorAttribute = $this->createSourceAttribute($connection, 'Color', 'color', true);
        $sizeAttribute = $this->createSourceAttribute($connection, 'Size', 'size', true);
        $this->createSourceAttributeValue($colorAttribute, 'Black');
        $this->createSourceAttributeValue($sizeAttribute, 'S');
        $kastaColor = $this->createKastaAttribute($mappedCategory, 'Color', 'color', true, false);
        $kastaSize = $this->createKastaAttribute($mappedCategory, 'Size', 'size', true, false);
        $this->createKastaAttribute($overrideCategory, 'Color', 'color', true, false);
        $this->createKastaAttribute($overrideCategory, 'Size', 'size', true, false);
        $black = $this->createKastaAttributeValue($kastaColor, 'Black');
        $s = $this->createKastaAttributeValue($kastaSize, 'S');

        $product = SourceProduct::create([
            'shop_id' => $shop->id,
            'source_connection_id' => $connection->id,
            'source_category_id' => $sourceCategory->id,
            'group_key' => 'grp-1',
            'name' => 'Basic Tee',
            'vendor' => 'Acme',
            'article' => 'TEE-1',
            'brand' => 'Acme',
            'description' => 'Basic tee',
            'primary_image_url' => 'https://example.test/img.jpg',
            'images_json' => ['https://example.test/img.jpg'],
            'attributes_snapshot' => ['Color' => 'Black', 'Size' => 'S'],
            'is_active' => true,
        ]);
        $variant = SourceVariant::create([
            'shop_id' => $shop->id,
            'source_connection_id' => $connection->id,
            'source_product_id' => $product->id,
            'external_offer_id' => 'SKU-1',
            'external_sku' => 'SKU-1',
            'stable_offer_id' => 'offer-1',
            'offer_identity_key' => 'SKU-1',
            'export_key_hash' => sha1('SKU-1'),
            'title' => 'Basic Tee Black S',
            'price' => 1000,
            'currency' => 'UAH',
            'quantity' => 3,
            'is_available' => true,
            'color' => 'Black',
            'size' => 'S',
            'images_json' => ['https://example.test/img.jpg'],
            'attributes_snapshot' => ['Color' => 'Black', 'Size' => 'S'],
            'is_enabled' => true,
        ]);
        $feedItem = FeedItem::create([
            'shop_id' => $shop->id,
            'feed_profile_id' => $feedProfile->id,
            'source_product_id' => $product->id,
            'source_variant_id' => $variant->id,
            'status' => FeedItem::STATUS_READY,
            'is_enabled' => true,
        ]);

        $colorMapping = AttributeMapping::create([
            'shop_id' => $shop->id,
            'source_connection_id' => $connection->id,
            'feed_profile_id' => $feedProfile->id,
            'source_category_id' => $sourceCategory->id,
            'source_attribute_id' => $colorAttribute->id,
            'kasta_category_id' => null,
            'kasta_attribute_id' => $kastaColor->id,
            'mapping_strategy' => AttributeMapping::STRATEGY_MANUAL,
            'use_variant_value' => true,
        ]);
        $sizeMapping = AttributeMapping::create([
            'shop_id' => $shop->id,
            'source_connection_id' => $connection->id,
            'feed_profile_id' => $feedProfile->id,
            'source_category_id' => $sourceCategory->id,
            'source_attribute_id' => $sizeAttribute->id,
            'kasta_category_id' => null,
            'kasta_attribute_id' => $kastaSize->id,
            'mapping_strategy' => AttributeMapping::STRATEGY_MANUAL,
            'use_variant_value' => true,
        ]);

        ValueMapping::create([
            'shop_id' => $shop->id,
            'feed_profile_id' => $feedProfile->id,
            'attribute_mapping_id' => $colorMapping->id,
            'source_raw_value' => 'Black',
            'normalized_source_value' => 'black',
            'kasta_attribute_value_id' => $black->id,
            'target_value' => 'Black',
            'mapping_strategy' => ValueMapping::STRATEGY_MANUAL,
            'is_active' => true,
        ]);
        ValueMapping::create([
            'shop_id' => $shop->id,
            'feed_profile_id' => $feedProfile->id,
            'attribute_mapping_id' => $sizeMapping->id,
            'source_raw_value' => 'S',
            'normalized_source_value' => 's',
            'kasta_attribute_value_id' => $s->id,
            'target_value' => 'S',
            'mapping_strategy' => ValueMapping::STRATEGY_MANUAL,
            'is_active' => true,
        ]);

        $exceptionService = app(FeedItemMappingExceptionService::class);
        $exceptionService->upsertCategoryException($feedProfile, $feedItem, $overrideCategory->id, $overrideCategory->full_path, 'Merchant-specific category override', $admin);
        $exceptionService->upsertAttributeException($feedProfile, $feedItem, 'color', 'Navy', 'Merchant asked for item-level color fix', $admin);

        $analysis = app(FeedItemDiagnosticsService::class)->analyze($feedProfile, $product, $variant, $feedItem->fresh());

        $this->assertSame($overrideCategory->id, $analysis['mapped_category']['id']);
        $this->assertSame('Navy', $analysis['mapped_attributes']['color']);
        $this->assertCount(2, $analysis['exception_rows']);

        ValidationError::create([
            'shop_id' => $shop->id,
            'feed_profile_id' => $feedProfile->id,
            'feed_item_id' => $feedItem->id,
            'source_product_id' => $product->id,
            'source_variant_id' => $variant->id,
            'code' => ValidationError::CODE_MISSING_VALUE_MAPPING,
            'severity' => 'warning',
            'message' => 'Missing value mapping',
            'payload' => ['source_attribute' => 'Color', 'source_value' => 'Azure'],
            'is_active' => true,
            'detected_at' => now(),
        ]);
        ValidationError::create([
            'shop_id' => $shop->id,
            'feed_profile_id' => $feedProfile->id,
            'feed_item_id' => $feedItem->id,
            'source_product_id' => $product->id,
            'source_variant_id' => $variant->id,
            'code' => ValidationError::CODE_INVALID_COLOR,
            'severity' => 'warning',
            'message' => 'Color issue',
            'payload' => [],
            'is_active' => true,
            'detected_at' => now(),
        ]);

        $feedbackImport = FeedbackImport::create([
            'shop_id' => $shop->id,
            'feed_profile_id' => $feedProfile->id,
            'user_id' => $admin->id,
            'format' => 'csv',
            'status' => 'imported',
            'original_filename' => 'feedback.csv',
            'source_path' => 'tests/feedback.csv',
            'checksum' => sha1('feedback'),
            'matched_total' => 3,
            'unmatched_total' => 0,
            'accepted_total' => 0,
            'rejected_total' => 3,
            'warnings_total' => 0,
            'imported_at' => now(),
        ]);

        foreach (range(1, 3) as $index) {
            FeedbackRecord::create([
                'shop_id' => $shop->id,
                'feed_profile_id' => $feedProfile->id,
                'feedback_import_id' => $feedbackImport->id,
                'feed_item_id' => $feedItem->id,
                'source_product_id' => $product->id,
                'source_variant_id' => $variant->id,
                'status' => FeedbackRecord::STATUS_REJECTED,
                'resolution_status' => $index <= 2 ? FeedbackRecord::RESOLUTION_EXCLUDED : FeedbackRecord::RESOLUTION_OPEN,
                'external_item_reference' => 'item-'.$index,
                'rejection_reason_code' => 'bad_color',
                'rejection_reason_message' => 'Color mismatch',
                'imported_at' => now(),
            ]);
        }

        $recommendations = app(MappingFeedbackRecommendationService::class)->recommend($feedProfile);

        $this->assertTrue(collect($recommendations)->contains(fn (array $row) => $row['recommendation_type'] === 'value_alias' && $row['safe_to_auto_apply'] === false));
        $this->assertTrue(collect($recommendations)->contains(fn (array $row) => $row['recommendation_type'] === 'exclusion'));
        $this->assertTrue(collect($recommendations)->contains(fn (array $row) => $row['recommendation_type'] === 'merchant_override'));
    }

    public function test_mapping_coverage_center_and_commands_render_successfully(): void
    {
        $shop = $this->createShop();
        $admin = $this->createAdminUser($shop);
        $connection = $this->createSourceConnection($shop);
        $feedProfile = $this->createFeedProfile($connection, $admin);
        $sourceCategory = SourceCategory::create([
            'shop_id' => $shop->id,
            'source_connection_id' => $connection->id,
            'external_id' => 'cat-1',
            'name' => 'T-Shirts',
            'full_path' => 'Apparel > T-Shirts',
            'rz_id' => '2001',
            'is_active' => true,
        ]);
        $this->createKastaCategory([
            'external_id' => 'KASTA-TS',
            'name' => 'T-Shirts',
            'full_path' => 'Apparel > T-Shirts',
            'rz_id' => '2001',
        ]);
        SourceProduct::create([
            'shop_id' => $shop->id,
            'source_connection_id' => $connection->id,
            'source_category_id' => $sourceCategory->id,
            'group_key' => 'grp-1',
            'name' => 'Basic Tee',
            'vendor' => 'Acme',
            'article' => 'ART-1',
            'brand' => 'Acme',
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.feed-profiles.mapping-coverage.show', $feedProfile))
            ->assertOk()
            ->assertSee('Mapping Coverage Center')
            ->assertSee('Category Suggestions');

        $this->artisan('mapping:suggest', ['feedProfileId' => $feedProfile->id, '--type' => 'category'])
            ->assertExitCode(0);
        $this->artisan('mapping:coverage', ['feedProfileId' => $feedProfile->id])
            ->assertExitCode(0);
        $this->artisan('mapping:apply-suggestions', ['feedProfileId' => $feedProfile->id, '--type' => 'category', '--dry-run' => true])
            ->assertExitCode(0);
        $this->artisan('mapping:feedback-recommendations', ['feedProfileId' => $feedProfile->id])
            ->assertExitCode(0);
        $this->artisan('mapping:template:export', ['feedProfileId' => $feedProfile->id])
            ->assertExitCode(0);
    }
}
