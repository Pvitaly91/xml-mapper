<?php

namespace Tests\Feature\Feeds;

use App\Contracts\Feeds\FeedBuildServiceInterface;
use App\Notifications\PilotEventNotification;
use App\Services\Feeds\FeedReleaseReadinessService;
use App\Services\Feeds\FeedReleaseService;
use App\Services\Feeds\FeedSmokeCheckService;
use App\Services\Ops\HeartbeatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Concerns\CreatesAdminContext;
use Tests\TestCase;

class FeedReleaseReadinessTest extends TestCase
{
    use CreatesAdminContext;
    use RefreshDatabase;

    public function test_release_readiness_detects_blocking_issues(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));

        ['feedProfile' => $feedProfile] = $this->seedBuildableCatalog();
        $generation = app(FeedBuildServiceInterface::class)->build($feedProfile);

        $readiness = app(FeedReleaseReadinessService::class)->evaluate($feedProfile, $generation);

        $this->assertSame('blocked', $readiness['status']);
        $this->assertContains('Generation must be approved before publishing.', $readiness['blocking_issues']);
    }

    public function test_release_readiness_surfaces_warnings(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));

        ['feedProfile' => $feedProfile, 'variant' => $variant] = $this->seedBuildableCatalog();
        $releaseService = app(FeedReleaseService::class);

        $publishedGeneration = app(FeedBuildServiceInterface::class)->build($feedProfile);
        $releaseService->markCandidate($publishedGeneration);
        $releaseService->approve($publishedGeneration->fresh());
        $releaseService->publish($feedProfile->fresh(), $publishedGeneration->fresh());

        Storage::disk(config('feed_mediator.storage_disk'))->put($feedProfile->fresh()->published_path, '<broken-xml');
        app(FeedSmokeCheckService::class)->run($feedProfile->fresh(), $publishedGeneration->fresh(), 'manual');

        $variant->update(['price' => 899]);
        $candidate = app(FeedBuildServiceInterface::class)->build($feedProfile->fresh());
        $releaseService->markCandidate($candidate);
        $releaseService->approve($candidate->fresh());

        $readiness = app(FeedReleaseReadinessService::class)->evaluate($feedProfile->fresh(), $candidate->fresh());

        $this->assertSame('warning', $readiness['status']);
        $this->assertContains('Latest smoke check failed. Review the published feed before go-live.', $readiness['warnings']);
    }

    public function test_publish_is_blocked_when_readiness_fails_and_emits_notification(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));
        Notification::fake();

        ['admin' => $admin, 'feedProfile' => $feedProfile] = $this->seedBuildableCatalog();
        $generation = app(FeedBuildServiceInterface::class)->build($feedProfile);

        try {
            app(FeedReleaseService::class)->publish($feedProfile->fresh(), $generation->fresh());
            $this->fail('Expected publish to be blocked.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Publish blocked:', $exception->getMessage());
        }

        Notification::assertSentTo(
            $admin,
            PilotEventNotification::class,
            fn (PilotEventNotification $notification) => ($notification->toArray($admin)['event'] ?? null) === 'feed.publish_blocked'
        );
        $this->assertDatabaseHas('feed_release_events', [
            'feed_generation_id' => $generation->id,
            'action' => 'publish_blocked',
        ]);
    }

    public function test_release_readiness_passes_for_healthy_generation(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));

        ['feedProfile' => $feedProfile] = $this->seedBuildableCatalog();
        app(HeartbeatService::class)->recordSchedulerHeartbeat();
        $generation = app(FeedBuildServiceInterface::class)->build($feedProfile);
        $releaseService = app(FeedReleaseService::class);
        $releaseService->markCandidate($generation);
        $releaseService->approve($generation->fresh());

        $readiness = app(FeedReleaseReadinessService::class)->evaluate($feedProfile->fresh(), $generation->fresh());

        $this->assertSame('ready', $readiness['status']);
        $this->assertSame([], $readiness['blocking_issues']);
    }
}
