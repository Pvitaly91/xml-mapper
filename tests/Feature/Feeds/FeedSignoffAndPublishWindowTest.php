<?php

namespace Tests\Feature\Feeds;

use App\Contracts\Feeds\FeedBuildServiceInterface;
use App\Models\FeedGenerationSignoff;
use App\Services\Feeds\FeedPublishWindowService;
use App\Services\Feeds\FeedReleaseService;
use App\Services\Feeds\FeedSignoffService;
use App\Actions\Ops\ResolveDueFeedPublishesAction;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Concerns\CreatesAdminContext;
use Tests\TestCase;

class FeedSignoffAndPublishWindowTest extends TestCase
{
    use CreatesAdminContext;
    use RefreshDatabase;

    public function test_signoff_statuses_persist_and_publish_is_blocked_without_required_signoff(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));

        ['admin' => $admin, 'feedProfile' => $feedProfile] = $this->seedBuildableCatalog();
        $feedProfile->update([
            'settings' => array_merge($feedProfile->exportSettings(), [
                'signoff_required' => true,
                'required_signoff_status' => FeedGenerationSignoff::STATUS_INTERNAL_APPROVED,
            ]),
        ]);

        $generation = app(FeedBuildServiceInterface::class)->build($feedProfile->fresh());
        $releaseService = app(FeedReleaseService::class);
        $releaseService->markCandidate($generation, $admin);
        $releaseService->approve($generation->fresh(), $admin);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Sign-off is required');
        $releaseService->publish($feedProfile->fresh(), $generation->fresh());
    }

    public function test_manual_override_with_reason_works_when_signoff_is_missing(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));

        ['feedProfile' => $feedProfile] = $this->seedBuildableCatalog();
        $feedProfile->update([
            'settings' => array_merge($feedProfile->exportSettings(), [
                'signoff_required' => true,
                'required_signoff_status' => FeedGenerationSignoff::STATUS_INTERNAL_APPROVED,
            ]),
        ]);

        $generation = app(FeedBuildServiceInterface::class)->build($feedProfile->fresh());
        $releaseService = app(FeedReleaseService::class);
        $releaseService->markCandidate($generation);
        $releaseService->approve($generation->fresh());

        $published = $releaseService->publish($feedProfile->fresh(), $generation->fresh(), true, 'Emergency override');

        $this->assertSame('published', $published->release_status);
        $this->assertDatabaseHas('feed_release_events', [
            'feed_generation_id' => $generation->id,
            'action' => 'guardrails_overridden',
            'reason' => 'Emergency override',
        ]);
    }

    public function test_publish_window_blocks_publish_outside_allowed_window(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));

        ['admin' => $admin, 'feedProfile' => $feedProfile] = $this->seedBuildableCatalog();
        $feedProfile->update([
            'settings' => array_merge($feedProfile->exportSettings(), [
                'signoff_required' => true,
                'required_signoff_status' => FeedGenerationSignoff::STATUS_INTERNAL_APPROVED,
                'publish_window_enabled' => true,
                'publish_window_days' => ['mon'],
                'publish_window_start' => '09:00',
                'publish_window_end' => '10:00',
                'publish_window_timezone' => 'Europe/Kiev',
            ]),
        ]);

        $generation = app(FeedBuildServiceInterface::class)->build($feedProfile->fresh());
        $releaseService = app(FeedReleaseService::class);
        $releaseService->markCandidate($generation, $admin);
        $releaseService->approve($generation->fresh(), $admin);
        app(FeedSignoffService::class)->record($generation->fresh(), FeedGenerationSignoff::STATUS_INTERNAL_APPROVED, $admin, 'Ops');

        $previousNow = CarbonImmutable::getTestNow();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-04 08:30:00', 'Europe/Kiev'));

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Publishing is not allowed');
            $releaseService->publish($feedProfile->fresh(), $generation->fresh());
        } finally {
            CarbonImmutable::setTestNow($previousNow);
        }
    }

    public function test_freeze_blocks_auto_publish_and_force_publish_works_with_reason(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));

        ['feedProfile' => $feedProfile] = $this->seedBuildableCatalog();
        app(FeedPublishWindowService::class)->setFreezeMode($feedProfile, true, 'Pilot freeze');

        $generation = app(FeedBuildServiceInterface::class)->build($feedProfile->fresh());
        $releaseService = app(FeedReleaseService::class);
        $releaseService->markCandidate($generation);
        $releaseService->approve($generation->fresh());

        $this->assertNull(app(ResolveDueFeedPublishesAction::class)->candidateForProfile($feedProfile->fresh()));

        $published = $releaseService->publish($feedProfile->fresh(), $generation->fresh(), true, 'Emergency go-live');

        $this->assertSame('published', $published->release_status);
        $this->assertDatabaseHas('feed_release_events', [
            'feed_profile_id' => $feedProfile->id,
            'action' => 'freeze_enabled',
            'reason' => 'Pilot freeze',
        ]);
    }
}
