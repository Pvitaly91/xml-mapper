<?php

namespace Tests\Feature\Feeds;

use App\Contracts\Feeds\FeedBuildServiceInterface;
use App\Models\SourceProduct;
use App\Models\SourceVariant;
use App\Services\Feeds\FeedReleaseService;
use App\Services\Ops\HeartbeatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAdminContext;
use Tests\TestCase;

class FeedReleaseReportTest extends TestCase
{
    use CreatesAdminContext;
    use RefreshDatabase;

    public function test_invalid_items_report_downloads(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));

        ['admin' => $admin, 'feedProfile' => $feedProfile, 'product' => $product, 'variant' => $variant] = $this->seedBuildableCatalog();
        $product->update(['primary_image_url' => null, 'images_json' => []]);
        $variant->update(['images_json' => []]);
        $generation = app(FeedBuildServiceInterface::class)->build($feedProfile);

        $response = $this->actingAs($admin)
            ->get(route('admin.feed-profiles.reports.invalid-items', ['feed_profile' => $feedProfile, 'generation_id' => $generation->id]));

        $response->assertOk();
        $this->assertStringContainsString('blocking_reasons', $response->streamedContent());
    }

    public function test_generation_diff_report_downloads(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));

        ['admin' => $admin, 'feedProfile' => $feedProfile, 'variant' => $variant] = $this->seedBuildableCatalog();
        $releaseService = app(FeedReleaseService::class);

        $firstGeneration = app(FeedBuildServiceInterface::class)->build($feedProfile);
        $releaseService->markCandidate($firstGeneration);
        $releaseService->approve($firstGeneration->fresh());
        $releaseService->publish($feedProfile->fresh(), $firstGeneration->fresh());

        $variant->update(['price' => 915]);
        $secondGeneration = app(FeedBuildServiceInterface::class)->build($feedProfile->fresh());

        $response = $this->actingAs($admin)
            ->get(route('admin.feed-profiles.generations.reports.diff', [$feedProfile, $secondGeneration]));

        $response->assertOk();
        $this->assertStringContainsString('"changed_items_total"', $response->streamedContent());
    }

    public function test_readiness_report_downloads(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));

        ['admin' => $admin, 'feedProfile' => $feedProfile] = $this->seedBuildableCatalog();
        app(HeartbeatService::class)->recordSchedulerHeartbeat();
        $generation = app(FeedBuildServiceInterface::class)->build($feedProfile);
        $releaseService = app(FeedReleaseService::class);
        $releaseService->markCandidate($generation);
        $releaseService->approve($generation->fresh());

        $response = $this->actingAs($admin)
            ->get(route('admin.feed-profiles.generations.reports.readiness', [$feedProfile, $generation]));

        $response->assertOk();
        $this->assertStringContainsString('"status": "ready"', $response->streamedContent());
    }

    public function test_candidate_xml_artifact_preview_and_download_work(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));

        ['admin' => $admin, 'feedProfile' => $feedProfile, 'variant' => $variant] = $this->seedBuildableCatalog();
        $generation = app(FeedBuildServiceInterface::class)->build($feedProfile);

        $previewResponse = $this->actingAs($admin)
            ->get(route('admin.feed-profiles.generations.artifact.preview', [$feedProfile, $generation]));

        $previewResponse->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
        $this->assertStringContainsString($variant->stable_offer_id, $previewResponse->getContent());

        $downloadResponse = $this->actingAs($admin)
            ->get(route('admin.feed-profiles.generations.artifact.download', [$feedProfile, $generation]));

        $downloadResponse->assertOk();
        $this->assertStringContainsString($variant->stable_offer_id, $downloadResponse->streamedContent());
    }

    public function test_functional_xml_report_and_downloads_include_traceability(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));

        ['admin' => $admin, 'connection' => $connection, 'feedProfile' => $feedProfile, 'sourceCategory' => $sourceCategory, 'variant' => $variant] = $this->seedBuildableCatalog();

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
            'primary_image_url' => null,
            'images_json' => [],
            'is_active' => true,
        ]);

        $brokenVariant = SourceVariant::create([
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

        $jsonResponse = $this->actingAs($admin)
            ->get(route('admin.feed-profiles.generations.reports.final-xml', [$feedProfile, $generation]));

        $jsonResponse->assertOk();
        $report = json_decode($jsonResponse->streamedContent(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, data_get($report, 'summary.included_items_count'));
        $this->assertSame(1, data_get($report, 'summary.excluded_items_count'));
        $this->assertSame('needs_fixes', data_get($report, 'summary.functional_status'));
        $this->assertFalse((bool) data_get($report, 'summary.publish_ready'));

        $included = collect($report['included_items'])->firstWhere('stable_offer_id', $variant->stable_offer_id);
        $excluded = collect($report['excluded_items'])->firstWhere('stable_offer_id', $brokenVariant->stable_offer_id);

        $this->assertTrue((bool) $included['included_in_xml']);
        $this->assertSame($variant->stable_offer_id, data_get($included, 'trace.xml_snapshot.offer_id'));
        $this->assertFalse((bool) $excluded['included_in_xml']);
        $this->assertContains('invalid_color', collect($excluded['errors'])->pluck('code')->all());

        $includedCsv = $this->actingAs($admin)
            ->get(route('admin.feed-profiles.generations.reports.final-xml.download', [$feedProfile, $generation, 'included']));
        $excludedCsv = $this->actingAs($admin)
            ->get(route('admin.feed-profiles.generations.reports.final-xml.download', [$feedProfile, $generation, 'excluded']));
        $issuesCsv = $this->actingAs($admin)
            ->get(route('admin.feed-profiles.generations.reports.final-xml.download', [$feedProfile, $generation, 'issues']));
        $blockersCsv = $this->actingAs($admin)
            ->get(route('admin.feed-profiles.generations.reports.final-xml.download', [$feedProfile, $generation, 'blockers']));

        $includedCsv->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $excludedCsv->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $issuesCsv->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $blockersCsv->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $this->assertStringContainsString($variant->stable_offer_id, $includedCsv->streamedContent());
        $this->assertStringContainsString($brokenVariant->stable_offer_id, $excludedCsv->streamedContent());
        $this->assertStringContainsString('invalid_color', $issuesCsv->streamedContent());
        $this->assertStringContainsString('size_color_unresolved', $blockersCsv->streamedContent());
    }
}
