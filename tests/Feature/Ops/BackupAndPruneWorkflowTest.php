<?php

namespace Tests\Feature\Ops;

use App\Contracts\Feeds\FeedBuildServiceInterface;
use App\Models\FeedGenerationSmokeCheck;
use App\Models\OpsRun;
use App\Services\Feeds\FeedPreviewLinkService;
use App\Services\Feeds\FeedReleaseService;
use App\Services\Feeds\FeedSmokeCheckService;
use App\Services\Ops\BackupService;
use App\Services\Ops\PruneService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAdminContext;
use Tests\TestCase;

class BackupAndPruneWorkflowTest extends TestCase
{
    use CreatesAdminContext;
    use RefreshDatabase;

    public function test_backup_status_is_persisted(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));
        ['feedProfile' => $feedProfile] = $this->seedBuildableCatalog();
        app(FeedBuildServiceInterface::class)->build($feedProfile);

        $result = app(BackupService::class)->backupDatabase();

        Storage::disk(config('feed_mediator.storage_disk'))->assertExists($result['path']);
        $this->assertDatabaseHas('ops_runs', [
            'type' => OpsRun::TYPE_BACKUP_DB,
            'status' => OpsRun::STATUS_SUCCEEDED,
            'artifact_path' => $result['path'],
        ]);
    }

    public function test_prune_removes_old_artifacts_but_keeps_published_generation(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));

        ['feedProfile' => $feedProfile, 'variant' => $variant] = $this->seedBuildableCatalog();
        $releaseService = app(FeedReleaseService::class);
        $publishedGeneration = app(FeedBuildServiceInterface::class)->build($feedProfile);
        $releaseService->markCandidate($publishedGeneration);
        $releaseService->approve($publishedGeneration->fresh());
        $releaseService->publish($feedProfile->fresh(), $publishedGeneration->fresh());

        $variant->update(['price' => 915]);
        $oldGeneration = app(FeedBuildServiceInterface::class)->build($feedProfile->fresh());
        $oldGeneration->update([
            'built_at' => now()->subDays(20),
        ]);

        $previewLink = app(FeedPreviewLinkService::class)->create($oldGeneration, 60);
        $recentSmokeCheck = app(FeedSmokeCheckService::class)->run($feedProfile->fresh(), $publishedGeneration->fresh(), FeedGenerationSmokeCheck::TRIGGER_MANUAL);
        $oldSmokeCheck = app(FeedSmokeCheckService::class)->runPreview($previewLink->fresh(), FeedGenerationSmokeCheck::TRIGGER_MANUAL);
        $latestPreviewSmokeCheck = app(FeedSmokeCheckService::class)->runPreview($previewLink->fresh(), FeedGenerationSmokeCheck::TRIGGER_MANUAL);
        $previewLink->update(['expires_at' => now()->subDays(10)]);
        $oldSmokeCheck->update(['checked_at' => now()->subDays(40)]);

        $result = app(PruneService::class)->run();

        $this->assertGreaterThanOrEqual(1, $result['summary']['generation_artifacts_pruned']);
        $this->assertGreaterThanOrEqual(1, $result['summary']['preview_links_pruned']);
        $this->assertGreaterThanOrEqual(1, $result['summary']['smoke_checks_pruned']);

        $this->assertDatabaseMissing('feed_generation_preview_links', [
            'id' => $previewLink->id,
        ]);
        $this->assertDatabaseMissing('feed_generation_smoke_checks', [
            'id' => $oldSmokeCheck->id,
        ]);

        $publishedGeneration->refresh();
        $oldGeneration->refresh();

        Storage::disk(config('feed_mediator.storage_disk'))->assertExists((string) $publishedGeneration->published_path);
        $this->assertNotNull($publishedGeneration->published_path);
        $this->assertNull($oldGeneration->file_path);
        $this->assertDatabaseHas('feed_generation_smoke_checks', [
            'id' => $recentSmokeCheck->id,
        ]);
        $this->assertDatabaseHas('feed_generation_smoke_checks', [
            'id' => $latestPreviewSmokeCheck->id,
        ]);
    }
}
