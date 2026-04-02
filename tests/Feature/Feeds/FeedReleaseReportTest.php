<?php

namespace Tests\Feature\Feeds;

use App\Contracts\Feeds\FeedBuildServiceInterface;
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
}
