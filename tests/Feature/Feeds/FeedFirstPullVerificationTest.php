<?php

namespace Tests\Feature\Feeds;

use App\Contracts\Feeds\FeedBuildServiceInterface;
use App\Models\FeedFirstPullVerification;
use App\Services\Feeds\FeedFirstPullVerificationService;
use App\Services\Feeds\FeedReleaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAdminContext;
use Tests\TestCase;

class FeedFirstPullVerificationTest extends TestCase
{
    use CreatesAdminContext;
    use RefreshDatabase;

    public function test_successful_first_pull_verification_is_persisted(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));

        ['feedProfile' => $feedProfile] = $this->seedBuildableCatalog();
        $generation = app(FeedBuildServiceInterface::class)->build($feedProfile);
        $releaseService = app(FeedReleaseService::class);
        $releaseService->markCandidate($generation);
        $releaseService->approve($generation->fresh());
        $releaseService->publish($feedProfile->fresh(), $generation->fresh());

        $this->assertDatabaseHas('feed_first_pull_verifications', [
            'feed_generation_id' => $generation->id,
            'status' => FeedFirstPullVerification::STATUS_OK,
        ]);
    }

    public function test_first_pull_failure_is_persisted_and_manual_rerun_works(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));

        ['admin' => $admin, 'feedProfile' => $feedProfile] = $this->seedBuildableCatalog();
        $generation = app(FeedBuildServiceInterface::class)->build($feedProfile);
        $releaseService = app(FeedReleaseService::class);
        $releaseService->markCandidate($generation, $admin);
        $releaseService->approve($generation->fresh(), $admin);
        $releaseService->publish($feedProfile->fresh(), $generation->fresh());

        Storage::disk(config('feed_mediator.storage_disk'))->put($feedProfile->fresh()->published_path, '<broken-xml');
        $verification = app(FeedFirstPullVerificationService::class)->run($feedProfile->fresh(), $generation->fresh(), 'manual', $admin, 'Broken XML check');

        $this->assertSame(FeedFirstPullVerification::STATUS_FAILED, $verification->status);
        $this->assertContains('Published feed XML is not well-formed.', $verification->errors);

        $this->actingAs($admin)
            ->post(route('admin.feed-profiles.generations.first-pull-verify', [$feedProfile, $generation]), [
                'reason' => 'Operator rerun',
            ])
            ->assertRedirect();

        $this->assertDatabaseCount('feed_first_pull_verifications', 3);
    }
}
