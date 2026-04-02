<?php

namespace Tests\Feature\Feeds;

use App\Contracts\Feeds\FeedBuildServiceInterface;
use App\Models\FeedGenerationSmokeCheck;
use App\Notifications\PilotEventNotification;
use App\Services\Feeds\FeedReleaseService;
use App\Services\Feeds\FeedSmokeCheckService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAdminContext;
use Tests\TestCase;

class FeedSmokeCheckTest extends TestCase
{
    use CreatesAdminContext;
    use RefreshDatabase;

    public function test_successful_smoke_check_result_is_persisted(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));

        ['feedProfile' => $feedProfile] = $this->seedBuildableCatalog();
        $generation = app(FeedBuildServiceInterface::class)->build($feedProfile);
        $releaseService = app(FeedReleaseService::class);
        $releaseService->markCandidate($generation);
        $releaseService->approve($generation->fresh());
        $releaseService->publish($feedProfile->fresh(), $generation->fresh());

        $this->assertDatabaseHas('feed_generation_smoke_checks', [
            'feed_generation_id' => $generation->id,
            'status' => FeedGenerationSmokeCheck::STATUS_OK,
        ]);
    }

    public function test_smoke_check_handles_invalid_xml_and_wrong_status(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));

        ['feedProfile' => $feedProfile] = $this->seedBuildableCatalog();
        $generation = app(FeedBuildServiceInterface::class)->build($feedProfile);
        $releaseService = app(FeedReleaseService::class);
        $releaseService->markCandidate($generation);
        $releaseService->approve($generation->fresh());
        $releaseService->publish($feedProfile->fresh(), $generation->fresh());

        Storage::disk(config('feed_mediator.storage_disk'))->put($feedProfile->fresh()->published_path, '<broken-xml');
        $failedXml = app(FeedSmokeCheckService::class)->run($feedProfile->fresh(), $generation->fresh(), FeedGenerationSmokeCheck::TRIGGER_MANUAL);

        $feedProfile->update(['status' => 'inactive']);
        $wrongStatus = app(FeedSmokeCheckService::class)->run($feedProfile->fresh(), $generation->fresh(), FeedGenerationSmokeCheck::TRIGGER_MANUAL);

        $this->assertSame(FeedGenerationSmokeCheck::STATUS_FAILED, $failedXml->status);
        $this->assertContains('Published feed XML is not well-formed.', $failedXml->errors);
        $this->assertSame(404, $wrongStatus->http_status);
        $this->assertContains('Expected HTTP 200, got 404.', $wrongStatus->errors);
        $this->assertDatabaseCount('feed_generation_smoke_checks', 3);
    }

    public function test_manual_smoke_check_rerun_works_and_records_audit_event(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));

        ['admin' => $admin, 'feedProfile' => $feedProfile] = $this->seedBuildableCatalog();
        $generation = app(FeedBuildServiceInterface::class)->build($feedProfile);
        $releaseService = app(FeedReleaseService::class);
        $releaseService->markCandidate($generation);
        $releaseService->approve($generation->fresh());
        $releaseService->publish($feedProfile->fresh(), $generation->fresh());

        $this->actingAs($admin)
            ->post(route('admin.feed-profiles.generations.smoke-check', [$feedProfile, $generation]), [
                'reason' => 'Manual operator re-check',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('feed_release_events', [
            'feed_generation_id' => $generation->id,
            'action' => 'smoke_check_rerun',
            'user_id' => $admin->id,
            'reason' => 'Manual operator re-check',
        ]);
    }

    public function test_smoke_check_failure_emits_notification(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));
        Notification::fake();

        ['admin' => $admin, 'feedProfile' => $feedProfile] = $this->seedBuildableCatalog();
        $generation = app(FeedBuildServiceInterface::class)->build($feedProfile);
        $releaseService = app(FeedReleaseService::class);
        $releaseService->markCandidate($generation);
        $releaseService->approve($generation->fresh());
        $releaseService->publish($feedProfile->fresh(), $generation->fresh());

        Storage::disk(config('feed_mediator.storage_disk'))->put($feedProfile->fresh()->published_path, '<broken-xml');
        app(FeedSmokeCheckService::class)->run($feedProfile->fresh(), $generation->fresh(), FeedGenerationSmokeCheck::TRIGGER_MANUAL);

        Notification::assertSentTo(
            $admin,
            PilotEventNotification::class,
            fn (PilotEventNotification $notification) => ($notification->toArray($admin)['event'] ?? null) === 'feed.smoke_check_failed'
        );
    }
}
