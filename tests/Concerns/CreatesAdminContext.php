<?php

namespace Tests\Concerns;

use App\Models\CategoryMapping;
use App\Models\FeedProfile;
use App\Models\KastaAttribute;
use App\Models\KastaAttributeValue;
use App\Models\KastaCategory;
use App\Models\ShopMembership;
use App\Models\Shop;
use App\Models\SourceAttribute;
use App\Models\SourceAttributeValue;
use App\Models\SourceCategory;
use App\Models\SourceConnection;
use App\Models\SourceProduct;
use App\Models\SourceVariant;
use App\Models\User;

trait CreatesAdminContext
{
    protected function createShop(array $overrides = []): Shop
    {
        return Shop::create(array_merge([
            'name' => 'Demo Shop',
            'slug' => 'demo-shop-'.mt_rand(1000, 9999),
            'currency' => 'UAH',
            'locale' => 'uk',
            'timezone' => 'Europe/Kiev',
            'is_active' => true,
        ], $overrides));
    }

    protected function createAdminUser(?Shop $shop = null, array $overrides = []): User
    {
        $shop ??= $this->createShop();

        $user = User::factory()->create(array_merge([
            'shop_id' => $shop->id,
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ], $overrides));

        ShopMembership::query()->firstOrCreate(
            [
                'user_id' => $user->id,
                'shop_id' => $shop->id,
            ],
            [
                'role' => ShopMembership::ROLE_SHOP_ADMIN,
                'status' => ShopMembership::STATUS_ACTIVE,
            ]
        );

        return $user;
    }

    protected function createPlatformAdminUser(array $overrides = []): User
    {
        $user = User::factory()->create(array_merge([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
            'shop_id' => null,
        ], $overrides));

        $this->grantMembership($user, null, ShopMembership::ROLE_PLATFORM_ADMIN);

        return $user;
    }

    protected function grantMembership(
        User $user,
        ?Shop $shop,
        string $role,
        string $status = ShopMembership::STATUS_ACTIVE,
        array $overrides = []
    ): ShopMembership {
        return ShopMembership::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'shop_id' => $shop?->id,
            ],
            array_merge([
                'role' => $role,
                'status' => $status,
            ], $overrides)
        );
    }

    protected function createSourceConnection(Shop $shop, array $overrides = []): SourceConnection
    {
        return SourceConnection::create(array_merge([
            'shop_id' => $shop->id,
            'name' => 'Prom Feed',
            'code' => 'prom-main',
            'driver' => SourceConnection::DRIVER_PROM_YML,
            'status' => SourceConnection::STATUS_ACTIVE,
            'source_url' => base_path('tests/Fixtures/prom_sample.yml'),
            'last_connection_check_status' => SourceConnection::CHECK_STATUS_OK,
            'last_sync_status' => SourceConnection::CHECK_STATUS_OK,
            'last_synced_at' => now(),
            'sync_interval_minutes' => 60,
        ], $overrides));
    }

    protected function createFeedProfile(SourceConnection $connection, ?User $user = null, array $overrides = []): FeedProfile
    {
        return FeedProfile::create(array_merge([
            'shop_id' => $connection->shop_id,
            'source_connection_id' => $connection->id,
            'user_id' => $user?->id,
            'name' => 'Kasta Main',
            'code' => 'kasta-main-'.mt_rand(1000, 9999),
            'status' => FeedProfile::STATUS_ACTIVE,
            'build_interval_minutes' => 60,
        ], $overrides));
    }

    protected function createSourceAttribute(SourceConnection $connection, string $name, string $code, bool $isVariantAxis = false): SourceAttribute
    {
        return SourceAttribute::create([
            'shop_id' => $connection->shop_id,
            'source_connection_id' => $connection->id,
            'name' => $name,
            'code' => $code,
            'data_type' => 'string',
            'usage_scope' => 'variant',
            'is_variant_axis' => $isVariantAxis,
        ]);
    }

    protected function createSourceAttributeValue(SourceAttribute $attribute, string $value): SourceAttributeValue
    {
        return SourceAttributeValue::create([
            'shop_id' => $attribute->shop_id,
            'source_connection_id' => $attribute->source_connection_id,
            'source_attribute_id' => $attribute->id,
            'raw_value' => $value,
            'normalized_value' => mb_strtolower($value),
            'value_hash' => sha1($value),
        ]);
    }

    protected function createKastaCategory(array $overrides = []): KastaCategory
    {
        return KastaCategory::create(array_merge([
            'external_id' => 'KASTA-CAT-'.mt_rand(1000, 9999),
            'name' => 'T-Shirts',
            'full_path' => 'Apparel > T-Shirts',
            'is_active' => true,
        ], $overrides));
    }

    protected function createKastaAttribute(KastaCategory $category, string $name, string $code, bool $isRequired = false, bool $allowsCustomValue = true): KastaAttribute
    {
        return KastaAttribute::create([
            'kasta_category_id' => $category->id,
            'external_id' => $code,
            'name' => $name,
            'code' => $code,
            'data_type' => 'string',
            'is_required' => $isRequired,
            'allows_custom_value' => $allowsCustomValue,
            'sort_order' => 10,
        ]);
    }

    protected function createKastaAttributeValue(KastaAttribute $attribute, string $value): KastaAttributeValue
    {
        return KastaAttributeValue::create([
            'kasta_attribute_id' => $attribute->id,
            'external_id' => $value,
            'value' => $value,
            'normalized_value' => mb_strtolower($value),
            'value_hash' => sha1($value),
            'sort_order' => 10,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function seedBuildableCatalog(): array
    {
        $shop = $this->createShop();
        $admin = $this->createAdminUser($shop);
        $connection = $this->createSourceConnection($shop);
        $feedProfile = $this->createFeedProfile($connection, $admin, ['code' => 'kasta-build']);
        $sourceCategory = SourceCategory::create([
            'shop_id' => $shop->id,
            'source_connection_id' => $connection->id,
            'external_id' => '101',
            'name' => 'T-Shirts',
            'full_path' => 'Apparel > T-Shirts',
            'rz_id' => '2001',
            'is_active' => true,
        ]);
        $product = SourceProduct::create([
            'shop_id' => $shop->id,
            'source_connection_id' => $connection->id,
            'source_category_id' => $sourceCategory->id,
            'group_key' => 'grp-1',
            'name' => 'Basic Tee',
            'vendor' => 'Acme',
            'article' => 'TSHIRT-001',
            'brand' => 'Acme',
            'description' => 'Basic tee',
            'primary_image_url' => 'https://example.test/img-1.jpg',
            'images_json' => ['https://example.test/img-1.jpg'],
            'attributes_snapshot' => ['Material' => 'Cotton'],
            'is_active' => true,
        ]);
        $variant = SourceVariant::create([
            'shop_id' => $shop->id,
            'source_connection_id' => $connection->id,
            'source_product_id' => $product->id,
            'external_offer_id' => 'SKU-1',
            'external_sku' => 'SKU-1',
            'stable_offer_id' => 'ofr_test_001',
            'offer_identity_key' => 'SKU-1',
            'export_key_hash' => sha1('SKU-1'),
            'title' => 'Basic Tee Black S',
            'price' => 799,
            'currency' => 'UAH',
            'quantity' => 10,
            'is_available' => true,
            'color' => 'Black',
            'size' => 'S',
            'images_json' => ['https://example.test/img-1.jpg'],
            'attributes_snapshot' => ['Color' => 'Black', 'Size' => 'S'],
            'is_enabled' => true,
        ]);
        $kastaCategory = $this->createKastaCategory([
            'external_id' => 'KASTA-TSHIRTS',
            'rz_id' => '2001',
        ]);
        $kastaAttribute = $this->createKastaAttribute($kastaCategory, 'Seed marker', 'seed_marker', false);
        $this->createKastaAttributeValue($kastaAttribute, 'Seed value');

        CategoryMapping::create([
            'shop_id' => $shop->id,
            'source_connection_id' => $connection->id,
            'feed_profile_id' => $feedProfile->id,
            'source_category_id' => $sourceCategory->id,
            'kasta_category_id' => $kastaCategory->id,
            'mapping_strategy' => CategoryMapping::STRATEGY_MANUAL,
            'is_active' => true,
        ]);

        return compact('shop', 'admin', 'connection', 'feedProfile', 'sourceCategory', 'product', 'variant', 'kastaCategory');
    }
}
