<?php

namespace Tests\Feature\Feeds;

use App\Contracts\Feeds\FeedBuildServiceInterface;
use App\Models\SourceProduct;
use App\Models\SourceVariant;
use App\Services\Feeds\FeedReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAdminContext;
use Tests\TestCase;

class FeedReconciliationReportTest extends TestCase
{
    use CreatesAdminContext;
    use RefreshDatabase;

    public function test_reconciliation_summary_and_download_work(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));

        ['admin' => $admin, 'connection' => $connection, 'feedProfile' => $feedProfile, 'sourceCategory' => $sourceCategory] = $this->seedBuildableCatalog();

        $product = SourceProduct::create([
            'shop_id' => $connection->shop_id,
            'source_connection_id' => $connection->id,
            'source_category_id' => $sourceCategory->id,
            'group_key' => 'grp-2',
            'name' => 'Broken Tee',
            'vendor' => 'Acme',
            'article' => 'TSHIRT-002',
            'brand' => 'Acme',
            'description' => 'Broken tee',
            'primary_image_url' => 'https://example.test/img-2.jpg',
            'images_json' => ['https://example.test/img-2.jpg'],
            'is_active' => true,
        ]);

        SourceVariant::create([
            'shop_id' => $connection->shop_id,
            'source_connection_id' => $connection->id,
            'source_product_id' => $product->id,
            'external_offer_id' => 'SKU-2',
            'external_sku' => 'SKU-2',
            'stable_offer_id' => 'ofr_test_002',
            'offer_identity_key' => 'SKU-2',
            'export_key_hash' => sha1('SKU-2'),
            'title' => 'Broken Tee',
            'price' => 499,
            'currency' => 'UAH',
            'quantity' => 1,
            'is_available' => true,
            'color' => null,
            'size' => null,
            'images_json' => [],
            'is_enabled' => true,
        ]);

        $generation = app(FeedBuildServiceInterface::class)->build($feedProfile);
        $report = app(FeedReconciliationService::class)->summarize($feedProfile->fresh(), $generation->fresh());

        $this->assertSame(2, $report['summary']['source_variants_total']);
        $this->assertSame(1, $report['summary']['ready_total']);
        $this->assertSame(1, $report['summary']['invalid_total']);

        $this->actingAs($admin)
            ->get(route('admin.feed-profiles.reports.reconciliation', $feedProfile))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/json; charset=UTF-8');

        $this->actingAs($admin)
            ->get(route('admin.feed-profiles.reports.reconciliation', ['feed_profile' => $feedProfile, 'format' => 'csv']))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    }
}
