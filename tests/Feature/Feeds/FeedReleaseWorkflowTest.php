<?php

namespace Tests\Feature\Feeds;

use App\Contracts\Feeds\FeedBuildServiceInterface;
use App\Models\FeedGeneration;
use App\Services\Feeds\FeedReleaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAdminContext;
use Tests\TestCase;

class FeedReleaseWorkflowTest extends TestCase
{
    use CreatesAdminContext;
    use RefreshDatabase;

    public function test_generation_can_be_marked_candidate_and_approved(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));

        ['feedProfile' => $feedProfile] = $this->seedBuildableCatalog();
        $generation = app(FeedBuildServiceInterface::class)->build($feedProfile);
        $releaseService = app(FeedReleaseService::class);

        $candidate = $releaseService->markCandidate($generation, null, 'Ready for review');
        $this->assertSame(FeedGeneration::RELEASE_STATUS_CANDIDATE, $candidate->release_status);

        $approved = $releaseService->approve($candidate, null, 'Approved for pilot');

        $this->assertSame(FeedGeneration::RELEASE_STATUS_APPROVED, $approved->release_status);
        $this->assertNotNull($approved->approved_at);
        $this->assertDatabaseHas('feed_release_events', [
            'feed_generation_id' => $generation->id,
            'action' => 'approved',
            'reason' => 'Approved for pilot',
        ]);
    }

    public function test_publish_approved_generation_works(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));

        ['feedProfile' => $feedProfile] = $this->seedBuildableCatalog();
        $generation = app(FeedBuildServiceInterface::class)->build($feedProfile);
        $releaseService = app(FeedReleaseService::class);
        $releaseService->markCandidate($generation);
        $releaseService->approve($generation->fresh());

        $published = $releaseService->publish($feedProfile->fresh(), $generation->fresh());

        $this->assertSame(FeedGeneration::STATUS_PUBLISHED, $published->status);
        $this->assertSame(FeedGeneration::RELEASE_STATUS_PUBLISHED, $published->release_status);
        $this->assertNotNull($feedProfile->fresh()->published_generation_id);
        $this->assertDatabaseHas('feed_generation_smoke_checks', [
            'feed_generation_id' => $generation->id,
            'status' => 'ok',
        ]);
    }

    public function test_force_publish_path_works_and_records_override(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));

        ['feedProfile' => $feedProfile] = $this->seedBuildableCatalog();
        $generation = app(FeedBuildServiceInterface::class)->build($feedProfile);

        $published = app(FeedReleaseService::class)->publish($feedProfile->fresh(), $generation, true, 'Operator override');

        $this->assertSame(FeedGeneration::STATUS_PUBLISHED, $published->status);
        $this->assertDatabaseHas('feed_release_events', [
            'feed_generation_id' => $generation->id,
            'action' => 'guardrails_overridden',
            'reason' => 'Operator override',
        ]);
        $this->assertDatabaseHas('feed_release_events', [
            'feed_generation_id' => $generation->id,
            'action' => 'force_published',
            'reason' => 'Operator override',
        ]);
    }

    public function test_rollback_switches_published_generation_correctly(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));

        ['feedProfile' => $feedProfile, 'variant' => $variant] = $this->seedBuildableCatalog();
        $releaseService = app(FeedReleaseService::class);

        $firstGeneration = app(FeedBuildServiceInterface::class)->build($feedProfile);
        $releaseService->markCandidate($firstGeneration);
        $releaseService->approve($firstGeneration->fresh());
        $releaseService->publish($feedProfile->fresh(), $firstGeneration->fresh());

        $variant->update(['price' => 999]);

        $secondGeneration = app(FeedBuildServiceInterface::class)->build($feedProfile->fresh());
        $releaseService->markCandidate($secondGeneration);
        $releaseService->approve($secondGeneration->fresh());
        $releaseService->publish($feedProfile->fresh(), $secondGeneration->fresh());

        $rolledBack = $releaseService->rollback($feedProfile->fresh(), $firstGeneration->fresh(), 'Restore first published version');

        $this->assertSame($firstGeneration->id, $rolledBack->id);
        $this->assertSame($firstGeneration->id, $feedProfile->fresh()->published_generation_id);
        $this->assertDatabaseHas('feed_generations', [
            'id' => $secondGeneration->id,
            'release_status' => FeedGeneration::RELEASE_STATUS_ROLLED_BACK,
        ]);
    }
}
