<?php

namespace Tests\Feature\Feeds;

use App\Contracts\Feeds\FeedBuildServiceInterface;
use App\Models\OpsRun;
use App\Services\Feeds\FeedRehearsalService;
use App\Services\Feeds\FeedReleaseService;
use App\Services\Ops\HeartbeatService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAdminContext;
use Tests\TestCase;

class FeedRehearsalWorkflowTest extends TestCase
{
    use CreatesAdminContext;
    use RefreshDatabase;

    public function test_rehearsal_statuses_are_persisted_and_canary_does_not_replace_published_feed(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));

        foreach (config('feed_mediator.preflight.required_directories') as $directory) {
            Storage::disk(config('feed_mediator.storage_disk'))->makeDirectory($directory);
        }

        config()->set('app.env', 'staging');
        config()->set('app.debug', false);
        config()->set('feed_mediator.environment.class', 'staging');
        config()->set('queue.default', 'redis');

        Redis::shouldReceive('connection->ping')->once()->andReturn('PONG');
        Queue::shouldReceive('size')->atLeast()->times(count((array) config('feed_mediator.queues')))->andReturn(0);
        app(HeartbeatService::class)->recordSchedulerHeartbeat(CarbonImmutable::now());

        ['admin' => $admin, 'feedProfile' => $feedProfile, 'variant' => $variant] = $this->seedBuildableCatalog();
        $releaseService = app(FeedReleaseService::class);
        $buildService = app(FeedBuildServiceInterface::class);

        $publishedGeneration = $buildService->build($feedProfile);
        $releaseService->markCandidate($publishedGeneration, $admin);
        $releaseService->approve($publishedGeneration->fresh(), $admin);
        $releaseService->publish($feedProfile->fresh(), $publishedGeneration->fresh());

        $variant->update(['price' => 915]);
        $candidateGeneration = $buildService->build($feedProfile->fresh());

        $result = app(FeedRehearsalService::class)->run($feedProfile->fresh(), [
            'with_preview' => true,
            'with_smoke' => true,
        ], $admin);

        $this->assertSame('passed', $result['status']);
        $this->assertNotNull($result['preview_url']);
        $this->assertSame($publishedGeneration->id, $feedProfile->fresh()->published_generation_id);
        $this->assertDatabaseHas('ops_runs', [
            'type' => OpsRun::TYPE_REHEARSAL,
            'status' => OpsRun::STATUS_SUCCEEDED,
            'feed_profile_id' => $feedProfile->id,
        ]);

        $publishedResponse = $this->get(route('feeds.public', $feedProfile->public_token));
        $previewResponse = $this->get($result['preview_url']);

        $publishedResponse->assertOk();
        $previewResponse->assertOk();
        $this->assertStringContainsString('799', $publishedResponse->getContent());
        $this->assertStringContainsString('915', $previewResponse->getContent());
        $this->assertStringNotContainsString('915', $publishedResponse->getContent());
        $this->assertSame($candidateGeneration->id, $result['generation']?->id);
    }
}
