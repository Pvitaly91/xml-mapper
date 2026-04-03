<?php

namespace Tests\Feature\Promotion;

use App\Models\AttributeMapping;
use App\Models\CategoryMapping;
use App\Models\FeedProfile;
use App\Models\FeedReleaseEvent;
use App\Models\KastaAttribute;
use App\Models\KastaAttributeValue;
use App\Models\KastaCategory;
use App\Models\PromotionRun;
use App\Models\SourceAttribute;
use App\Models\SourceAttributeValue;
use App\Models\SourceCategory;
use App\Models\SourceConnection;
use App\Models\User;
use App\Models\ValueMapping;
use App\Services\Promotion\PromotionPlannerService;
use App\Services\Promotion\PromotionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAdminContext;
use Tests\TestCase;

class PromotionWorkflowTest extends TestCase
{
    use CreatesAdminContext;
    use RefreshDatabase;

    public function test_snapshot_generation_excludes_plain_secrets_and_keeps_fingerprints(): void
    {
        ['admin' => $admin, 'sourceProfile' => $sourceProfile, 'sourceConnection' => $sourceConnection] = $this->seedPromotionProfiles(true);
        $sourceConnection->forceFill([
            'options' => [
                'region' => 'ua',
                'secret_hint' => 'do-not-copy',
            ],
        ])->save();

        $snapshot = app(PromotionService::class)->generateSnapshot($sourceProfile->fresh(['sourceConnection']), $admin, 'staging', 'Staging');

        $this->assertSame('staging', data_get($snapshot->payload, 'environment.class'));
        $this->assertSame(SourceConnection::DRIVER_PROM_API, data_get($snapshot->payload, 'source_connection.driver'));
        $this->assertNull(data_get($snapshot->payload, 'source_connection.metadata.api_token'));
        $this->assertSame('[redacted]', data_get($snapshot->payload, 'source_connection.metadata.options.secret_hint'));
        $this->assertContains('api_token', data_get($snapshot->payload, 'source_connection.secret_policy.required_fields', []));
        $this->assertNotEmpty(data_get($snapshot->payload, 'fingerprints.overall'));
        $this->assertNotEmpty($snapshot->mapping_fingerprint);
    }

    public function test_drift_detection_reports_no_drift_and_incompatible_states(): void
    {
        ['admin' => $admin, 'sourceProfile' => $sourceProfile, 'targetProfile' => $targetProfile, 'mirror' => $mirror] = $this->seedPromotionProfiles();
        $sourceProfile->update([
            'settings' => [
                'minimum_ready_items' => 5,
                'publish_guard_enabled' => true,
            ],
        ]);
        $targetProfile->update([
            'settings' => [
                'minimum_ready_items' => 5,
                'publish_guard_enabled' => true,
            ],
        ]);

        CategoryMapping::create([
            'shop_id' => $targetProfile->shop_id,
            'source_connection_id' => $targetProfile->source_connection_id,
            'feed_profile_id' => $targetProfile->id,
            'source_category_id' => $mirror['targetCategory']->id,
            'kasta_category_id' => $mirror['kastaCategory']->id,
            'mapping_strategy' => CategoryMapping::STRATEGY_MANUAL,
            'is_active' => true,
        ]);
        AttributeMapping::create([
            'shop_id' => $targetProfile->shop_id,
            'source_connection_id' => $targetProfile->source_connection_id,
            'feed_profile_id' => $targetProfile->id,
            'source_category_id' => $mirror['targetCategory']->id,
            'source_attribute_id' => $mirror['targetAttribute']->id,
            'kasta_category_id' => $mirror['kastaCategory']->id,
            'kasta_attribute_id' => $mirror['kastaAttribute']->id,
            'mapping_strategy' => AttributeMapping::STRATEGY_MANUAL,
            'is_required' => false,
            'use_variant_value' => false,
        ]);
        ValueMapping::create([
            'shop_id' => $targetProfile->shop_id,
            'feed_profile_id' => $targetProfile->id,
            'attribute_mapping_id' => AttributeMapping::query()->where('feed_profile_id', $targetProfile->id)->firstOrFail()->id,
            'source_attribute_value_id' => $mirror['targetAttributeValue']->id,
            'kasta_attribute_value_id' => $mirror['kastaAttributeValue']->id,
            'source_raw_value' => 'Black',
            'normalized_source_value' => 'black',
            'target_value' => 'Black',
            'mapping_strategy' => ValueMapping::STRATEGY_MANUAL,
            'is_active' => true,
        ]);

        $snapshot = app(PromotionService::class)->generateSnapshot($sourceProfile, $admin, 'staging', 'Staging');
        $drift = app(PromotionPlannerService::class)->driftReport($snapshot, $targetProfile->fresh());
        $this->assertSame('no_drift', $drift['status']);

        $targetProfile->update(['settings' => ['minimum_ready_items' => 1]]);
        $driftDetected = app(PromotionPlannerService::class)->driftReport($snapshot, $targetProfile->fresh());
        $this->assertSame('drift_detected', $driftDetected['status']);

        ['sourceProfile' => $apiSource, 'targetProfile' => $ymlTarget, 'admin' => $otherAdmin] = $this->seedPromotionProfiles(true, SourceConnection::DRIVER_PROM_YML);
        $apiSnapshot = app(PromotionService::class)->generateSnapshot($apiSource, $otherAdmin, 'staging', 'Staging');
        $incompatible = app(PromotionPlannerService::class)->driftReport($apiSnapshot, $ymlTarget->fresh());
        $this->assertSame('incompatible', $incompatible['status']);
    }

    public function test_apply_persists_history_creates_mappings_and_keeps_target_secret(): void
    {
        ['admin' => $admin, 'sourceProfile' => $sourceProfile, 'targetProfile' => $targetProfile, 'targetConnection' => $targetConnection] = $this->seedPromotionProfiles(true);
        $sourceProfile->update([
            'settings' => [
                'minimum_ready_items' => 7,
                'publish_guard_enabled' => true,
            ],
        ]);
        $targetProfile->update([
            'settings' => [
                'minimum_ready_items' => 1,
            ],
        ]);

        $run = app(PromotionService::class)->applyBetweenProfiles(
            $sourceProfile,
            $targetProfile,
            PromotionRun::STRATEGY_SAFE_MERGE,
            $admin,
            'staging',
            'Staging',
            'Apply staged merchant config'
        );

        $this->assertSame(PromotionRun::STATUS_SUCCEEDED, $run->status);
        $this->assertSame(1, CategoryMapping::query()->where('feed_profile_id', $targetProfile->id)->count());
        $this->assertSame(1, AttributeMapping::query()->where('feed_profile_id', $targetProfile->id)->count());
        $this->assertSame(1, ValueMapping::query()->where('feed_profile_id', $targetProfile->id)->count());
        $this->assertSame(7, (int) ($targetProfile->fresh()->exportSettings()['minimum_ready_items'] ?? 0));
        $this->assertSame('target-token', $targetConnection->fresh()->api_token);
        $this->assertNotNull($run->resultSnapshot);
        $this->assertTrue(FeedReleaseEvent::query()
            ->where('feed_profile_id', $targetProfile->id)
            ->where('action', 'promotion_applied')
            ->exists());
        $this->assertNotEmpty(data_get($run->summary, 'report.path'));
    }

    public function test_rollback_restores_previous_config_and_blocks_when_target_changed_after_apply(): void
    {
        ['admin' => $admin, 'sourceProfile' => $sourceProfile, 'targetProfile' => $targetProfile] = $this->seedPromotionProfiles();
        $sourceProfile->update([
            'settings' => [
                'minimum_ready_items' => 9,
                'publish_guard_enabled' => true,
            ],
        ]);

        $run = app(PromotionService::class)->applyBetweenProfiles(
            $sourceProfile,
            $targetProfile,
            PromotionRun::STRATEGY_SAFE_MERGE,
            $admin,
            'staging',
            'Staging',
            'Apply before rollback'
        );
        $this->assertSame(PromotionRun::STATUS_SUCCEEDED, $run->status);

        $rollback = app(PromotionService::class)->rollback($run->fresh(['targetFeedProfile', 'targetSnapshot', 'resultSnapshot']), $admin, 'Revert config');
        $this->assertSame(PromotionRun::STATUS_SUCCEEDED, $rollback->status);
        $this->assertSame(0, CategoryMapping::query()->where('feed_profile_id', $targetProfile->id)->count());
        $this->assertSame(0, (int) ($targetProfile->fresh()->exportSettings()['minimum_ready_items'] ?? 0));

        $rerun = app(PromotionService::class)->applyBetweenProfiles(
            $sourceProfile->fresh(),
            $targetProfile->fresh(),
            PromotionRun::STRATEGY_SAFE_MERGE,
            $admin,
            'staging',
            'Staging',
            'Apply before risky rollback'
        );
        $targetProfile->fresh()->update([
            'settings' => [
                'minimum_ready_items' => 12,
            ],
        ]);

        $blocked = app(PromotionService::class)->rollback($rerun->fresh(['targetFeedProfile', 'targetSnapshot', 'resultSnapshot']), $admin, 'Risky rollback');
        $this->assertSame(PromotionRun::STATUS_BLOCKED, $blocked->status);
        $this->assertStringContainsString('target config changed', strtolower(implode(' ', $blocked->errors ?? [])));
    }

    public function test_promotion_center_and_reports_routes_are_available(): void
    {
        ['admin' => $admin, 'sourceProfile' => $sourceProfile, 'targetProfile' => $targetProfile] = $this->seedPromotionProfiles();
        $run = app(PromotionService::class)->dryRunBetweenProfiles(
            $sourceProfile,
            $targetProfile,
            PromotionRun::STRATEGY_SAFE_MERGE,
            $admin,
            'staging',
            'Staging',
            'UI dry-run'
        );

        $snapshot = $run->sourceSnapshot()->firstOrFail();

        $this->actingAs($admin)
            ->get(route('admin.feed-profiles.promotion.show', $targetProfile))
            ->assertOk();
        $this->actingAs($admin)
            ->get(route('admin.feed-profiles.promotion.runs.show', [$targetProfile, $run]))
            ->assertOk();
        $this->actingAs($admin)
            ->get(route('admin.feed-profiles.promotion.runs.download', [$targetProfile, $run]))
            ->assertOk();
        $this->actingAs($admin)
            ->get(route('admin.feed-profiles.promotion.snapshots.download', [$targetProfile, $snapshot]))
            ->assertOk();
    }

    public function test_promotion_commands_generate_compare_apply_and_rollback(): void
    {
        ['sourceProfile' => $sourceProfile, 'targetProfile' => $targetProfile] = $this->seedPromotionProfiles();

        $this->artisan('promotion:snapshot', ['feedProfileId' => $sourceProfile->id, '--env' => 'staging'])
            ->assertSuccessful();
        $this->artisan('promotion:diff', ['sourceFeedProfileId' => $sourceProfile->id, 'targetFeedProfileId' => $targetProfile->id])
            ->assertSuccessful();
        $this->artisan('promotion:dry-run', ['sourceFeedProfileId' => $sourceProfile->id, 'targetFeedProfileId' => $targetProfile->id])
            ->assertSuccessful();
        $this->artisan('promotion:apply', ['sourceFeedProfileId' => $sourceProfile->id, 'targetFeedProfileId' => $targetProfile->id, '--reason' => 'command apply'])
            ->assertSuccessful();

        $applyRun = PromotionRun::query()->where('mode', PromotionRun::MODE_APPLY)->latest('id')->firstOrFail();

        $this->artisan('promotion:rollback', ['promotionRunId' => $applyRun->id, '--reason' => 'command rollback'])
            ->assertSuccessful();
    }

    /**
     * @return array<string, mixed>
     */
    private function seedPromotionProfiles(bool $promApi = false, ?string $targetDriver = null): array
    {
        Storage::fake(config('feed_mediator.storage_disk'));

        $shop = $this->createShop();
        $admin = $this->createAdminUser($shop);
        $driver = $promApi ? SourceConnection::DRIVER_PROM_API : SourceConnection::DRIVER_PROM_YML;
        $sourceConnection = $this->createSourceConnection($shop, [
            'driver' => $driver,
            'code' => 'source-connection',
            'source_url' => $driver === SourceConnection::DRIVER_PROM_YML ? 'https://staging.example.test/feed.yml' : null,
            'api_base_url' => $driver === SourceConnection::DRIVER_PROM_API ? SourceConnection::defaultPromApiBaseUrl() : null,
            'api_version' => $driver === SourceConnection::DRIVER_PROM_API ? SourceConnection::defaultPromApiVersion() : null,
            'api_token' => $driver === SourceConnection::DRIVER_PROM_API ? 'source-token' : null,
            'last_connection_check_status' => SourceConnection::CHECK_STATUS_OK,
        ]);
        $targetConnection = $this->createSourceConnection($shop, [
            'driver' => $targetDriver ?: $driver,
            'code' => 'target-connection',
            'source_url' => ($targetDriver ?: $driver) === SourceConnection::DRIVER_PROM_YML ? 'https://staging.example.test/feed.yml' : null,
            'api_base_url' => ($targetDriver ?: $driver) === SourceConnection::DRIVER_PROM_API ? SourceConnection::defaultPromApiBaseUrl() : null,
            'api_version' => ($targetDriver ?: $driver) === SourceConnection::DRIVER_PROM_API ? SourceConnection::defaultPromApiVersion() : null,
            'api_token' => ($targetDriver ?: $driver) === SourceConnection::DRIVER_PROM_API ? 'target-token' : null,
            'last_connection_check_status' => SourceConnection::CHECK_STATUS_OK,
        ]);
        $sourceProfile = $this->createFeedProfile($sourceConnection, $admin, ['name' => 'Stage Merchant', 'code' => 'merchant-stage']);
        $targetProfile = $this->createFeedProfile($targetConnection, $admin, ['name' => 'Prod Merchant', 'code' => 'merchant-prod']);
        $categoryRzId = (string) mt_rand(2001, 9999);
        $categoryExternalId = 'KASTA-TSHIRTS-'.mt_rand(1000, 9999);

        $sourceCategory = SourceCategory::create([
            'shop_id' => $shop->id,
            'source_connection_id' => $sourceConnection->id,
            'external_id' => '101',
            'name' => 'T-Shirts',
            'full_path' => 'Apparel > T-Shirts',
            'rz_id' => '2001',
            'is_active' => true,
        ]);
        $targetCategory = SourceCategory::create([
            'shop_id' => $shop->id,
            'source_connection_id' => $targetConnection->id,
            'external_id' => '101',
            'name' => 'T-Shirts',
            'full_path' => 'Apparel > T-Shirts',
            'rz_id' => '2001',
            'is_active' => true,
        ]);
        $sourceAttribute = $this->createSourceAttribute($sourceConnection, 'Color', 'color');
        $targetAttribute = $this->createSourceAttribute($targetConnection, 'Color', 'color');
        $sourceAttributeValue = $this->createSourceAttributeValue($sourceAttribute, 'Black');
        $targetAttributeValue = $this->createSourceAttributeValue($targetAttribute, 'Black');
        $kastaCategory = $this->createKastaCategory([
            'external_id' => $categoryExternalId,
            'rz_id' => $categoryRzId,
            'name' => 'T-Shirts',
            'full_path' => 'Apparel > T-Shirts',
        ]);
        $kastaAttribute = $this->createKastaAttribute($kastaCategory, 'Color', 'color');
        $kastaAttributeValue = $this->createKastaAttributeValue($kastaAttribute, 'Black');

        CategoryMapping::create([
            'shop_id' => $shop->id,
            'source_connection_id' => $sourceConnection->id,
            'feed_profile_id' => $sourceProfile->id,
            'source_category_id' => $sourceCategory->id,
            'kasta_category_id' => $kastaCategory->id,
            'mapping_strategy' => CategoryMapping::STRATEGY_MANUAL,
            'is_active' => true,
        ]);
        $attributeMapping = AttributeMapping::create([
            'shop_id' => $shop->id,
            'source_connection_id' => $sourceConnection->id,
            'feed_profile_id' => $sourceProfile->id,
            'source_category_id' => $sourceCategory->id,
            'source_attribute_id' => $sourceAttribute->id,
            'kasta_category_id' => $kastaCategory->id,
            'kasta_attribute_id' => $kastaAttribute->id,
            'mapping_strategy' => AttributeMapping::STRATEGY_MANUAL,
            'is_required' => false,
            'use_variant_value' => false,
        ]);
        ValueMapping::create([
            'shop_id' => $shop->id,
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

        return [
            'shop' => $shop,
            'admin' => $admin,
            'sourceConnection' => $sourceConnection,
            'targetConnection' => $targetConnection,
            'sourceProfile' => $sourceProfile,
            'targetProfile' => $targetProfile,
            'mirror' => compact('sourceCategory', 'targetCategory', 'sourceAttribute', 'targetAttribute', 'sourceAttributeValue', 'targetAttributeValue', 'kastaCategory', 'kastaAttribute', 'kastaAttributeValue'),
        ];
    }
}
