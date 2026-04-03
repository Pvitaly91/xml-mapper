<?php

namespace Tests\Feature\Feeds;

use App\Contracts\Feeds\FeedBuildServiceInterface;
use App\Models\FeedGenerationSmokeCheck;
use App\Services\Feeds\FeedPreviewLinkService;
use App\Services\Feeds\FeedReleaseService;
use App\Services\Feeds\FeedSmokeCheckService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAdminContext;
use Tests\TestCase;

class FeedCandidatePreviewTest extends TestCase
{
    use CreatesAdminContext;
    use RefreshDatabase;

    public function test_signed_preview_url_works_and_can_be_smoke_checked(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));

        ['feedProfile' => $feedProfile] = $this->seedBuildableCatalog();
        $generation = app(FeedBuildServiceInterface::class)->build($feedProfile);
        app(FeedReleaseService::class)->markCandidate($generation);

        $previewLink = app(FeedPreviewLinkService::class)->create($generation, 60);
        $previewUrl = app(FeedPreviewLinkService::class)->urlFor($previewLink);

        $this->get($previewUrl)
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=UTF-8');

        $smokeCheck = app(FeedSmokeCheckService::class)->runPreview($previewLink, FeedGenerationSmokeCheck::TRIGGER_MANUAL);

        $this->assertSame(FeedGenerationSmokeCheck::STATUS_OK, $smokeCheck->status);
        $this->assertDatabaseHas('feed_generation_smoke_checks', [
            'feed_generation_id' => $generation->id,
            'status' => FeedGenerationSmokeCheck::STATUS_OK,
        ]);
    }

    public function test_expired_preview_url_is_blocked(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));

        ['feedProfile' => $feedProfile] = $this->seedBuildableCatalog();
        $generation = app(FeedBuildServiceInterface::class)->build($feedProfile);
        $previewLink = app(FeedPreviewLinkService::class)->create($generation, 60);
        $previewUrl = app(FeedPreviewLinkService::class)->urlFor($previewLink);
        $previewLink->update(['expires_at' => now()->subMinute()]);

        $this->get($previewUrl)->assertForbidden();
    }

    public function test_revoked_preview_url_is_blocked(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));

        ['feedProfile' => $feedProfile] = $this->seedBuildableCatalog();
        $generation = app(FeedBuildServiceInterface::class)->build($feedProfile);
        $previewService = app(FeedPreviewLinkService::class);
        $previewLink = $previewService->create($generation, 60);
        $previewUrl = $previewService->urlFor($previewLink);

        $previewService->revoke($previewLink, null, 'Preview review finished');

        $this->get($previewUrl)->assertForbidden();
    }

    public function test_preview_url_does_not_replace_published_feed_url(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));

        ['feedProfile' => $feedProfile, 'variant' => $variant] = $this->seedBuildableCatalog();
        $releaseService = app(FeedReleaseService::class);

        $publishedGeneration = app(FeedBuildServiceInterface::class)->build($feedProfile);
        $releaseService->markCandidate($publishedGeneration);
        $releaseService->approve($publishedGeneration->fresh());
        $releaseService->publish($feedProfile->fresh(), $publishedGeneration->fresh());

        $variant->update(['price' => 915]);
        $candidateGeneration = app(FeedBuildServiceInterface::class)->build($feedProfile->fresh());
        $releaseService->markCandidate($candidateGeneration);

        $previewUrl = app(FeedPreviewLinkService::class)->urlFor(
            app(FeedPreviewLinkService::class)->create($candidateGeneration, 60)
        );

        $publishedResponse = $this->get(route('feeds.public', $feedProfile->public_token));
        $previewResponse = $this->get($previewUrl);

        $publishedResponse->assertOk();
        $previewResponse->assertOk();
        $this->assertStringContainsString('799', $publishedResponse->getContent());
        $this->assertStringContainsString('915', $previewResponse->getContent());
        $this->assertStringNotContainsString('915', $publishedResponse->getContent());
    }
}
