<?php

namespace Tests\Feature\Feeds;

use App\Contracts\Feeds\FeedBuildServiceInterface;
use App\Models\FeedProfileCutover;
use App\Services\Feeds\FeedCutoverService;
use App\Services\Feeds\FeedReleaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAdminContext;
use Tests\TestCase;

class FeedCutoverWorkflowTest extends TestCase
{
    use CreatesAdminContext;
    use RefreshDatabase;

    public function test_cutover_statuses_transition_and_are_visible_in_ui(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));

        ['admin' => $admin, 'feedProfile' => $feedProfile] = $this->seedBuildableCatalog();
        $generation = app(FeedBuildServiceInterface::class)->build($feedProfile);
        $releaseService = app(FeedReleaseService::class);
        $cutoverService = app(FeedCutoverService::class);

        $releaseService->markCandidate($generation, $admin);
        $candidateCutover = $cutoverService->begin($feedProfile->fresh(), $generation->fresh(), $admin, 'Prepare merchant cutover');

        $this->assertSame(FeedProfileCutover::STATUS_CANDIDATE_READY, $candidateCutover->status);

        $releaseService->approve($generation->fresh(), $admin);
        $scheduledCutover = $cutoverService->begin(
            $feedProfile->fresh(),
            $generation->fresh(),
            $admin,
            'Schedule launch',
            '2026-04-03 10:00:00',
            '2026-04-03 11:00:00',
        );

        $this->assertSame(FeedProfileCutover::STATUS_CUTOVER_SCHEDULED, $scheduledCutover->status);

        $releaseService->publish($feedProfile->fresh(), $generation->fresh());

        $currentCutover = $cutoverService->current($feedProfile->fresh());

        $this->assertNotNull($currentCutover);
        $this->assertSame(FeedProfileCutover::STATUS_FIRST_PULL_VERIFIED, $currentCutover->status);
        $this->assertNotNull($currentCutover->actual_published_at);
        $this->assertNotNull($currentCutover->first_verified_at);

        $this->actingAs($admin)
            ->get(route('admin.feed-profiles.operations.show', $feedProfile))
            ->assertOk()
            ->assertSee('Current Cutover')
            ->assertSee(FeedProfileCutover::STATUS_FIRST_PULL_VERIFIED);
    }
}
