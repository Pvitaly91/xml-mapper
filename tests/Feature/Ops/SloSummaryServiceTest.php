<?php

namespace Tests\Feature\Ops;

use App\Models\FeedFirstPullVerification;
use App\Models\FeedGeneration;
use App\Models\FeedGenerationSmokeCheck;
use App\Models\FeedReleaseEvent;
use App\Models\SourceImport;
use App\Services\Ops\SloSummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAdminContext;
use Tests\TestCase;

class SloSummaryServiceTest extends TestCase
{
    use CreatesAdminContext;
    use RefreshDatabase;

    public function test_slo_metrics_aggregate_and_detect_degraded_state(): void
    {
        ['feedProfile' => $feedProfile] = $this->seedBuildableCatalog();

        SourceImport::create([
            'shop_id' => $feedProfile->shop_id,
            'source_connection_id' => $feedProfile->source_connection_id,
            'status' => SourceImport::STATUS_NORMALIZED,
            'started_at' => now()->subHours(2),
            'finished_at' => now()->subHours(2),
        ]);
        SourceImport::create([
            'shop_id' => $feedProfile->shop_id,
            'source_connection_id' => $feedProfile->source_connection_id,
            'status' => SourceImport::STATUS_FAILED,
            'started_at' => now()->subHour(),
            'finished_at' => now()->subHour(),
            'error_message' => 'sync failed',
        ]);

        $builtGeneration = FeedGeneration::create([
            'shop_id' => $feedProfile->shop_id,
            'feed_profile_id' => $feedProfile->id,
            'status' => FeedGeneration::STATUS_BUILT,
            'release_status' => FeedGeneration::RELEASE_STATUS_BUILT,
            'built_at' => now()->subHour(),
        ]);
        $failedGeneration = FeedGeneration::create([
            'shop_id' => $feedProfile->shop_id,
            'feed_profile_id' => $feedProfile->id,
            'status' => FeedGeneration::STATUS_FAILED,
            'release_status' => FeedGeneration::RELEASE_STATUS_BUILT,
            'created_at' => now()->subMinutes(30),
            'updated_at' => now()->subMinutes(30),
        ]);

        FeedReleaseEvent::create([
            'shop_id' => $feedProfile->shop_id,
            'feed_profile_id' => $feedProfile->id,
            'feed_generation_id' => $builtGeneration->id,
            'action' => 'published',
            'occurred_at' => now()->subMinutes(50),
        ]);
        FeedReleaseEvent::create([
            'shop_id' => $feedProfile->shop_id,
            'feed_profile_id' => $feedProfile->id,
            'feed_generation_id' => $failedGeneration->id,
            'action' => 'publish_failed',
            'occurred_at' => now()->subMinutes(20),
        ]);

        FeedGenerationSmokeCheck::create([
            'shop_id' => $feedProfile->shop_id,
            'feed_profile_id' => $feedProfile->id,
            'feed_generation_id' => $builtGeneration->id,
            'trigger_source' => 'manual',
            'status' => FeedGenerationSmokeCheck::STATUS_OK,
            'http_status' => 200,
            'latency_ms' => 100,
            'response_size_bytes' => 200,
            'checked_at' => now()->subMinutes(40),
            'warnings' => [],
            'errors' => [],
        ]);
        FeedGenerationSmokeCheck::create([
            'shop_id' => $feedProfile->shop_id,
            'feed_profile_id' => $feedProfile->id,
            'feed_generation_id' => $builtGeneration->id,
            'trigger_source' => 'manual',
            'status' => FeedGenerationSmokeCheck::STATUS_FAILED,
            'http_status' => 500,
            'latency_ms' => 100,
            'response_size_bytes' => 0,
            'checked_at' => now()->subMinutes(10),
            'warnings' => [],
            'errors' => ['bad xml'],
        ]);

        FeedFirstPullVerification::create([
            'shop_id' => $feedProfile->shop_id,
            'feed_profile_id' => $feedProfile->id,
            'feed_generation_id' => $builtGeneration->id,
            'status' => FeedFirstPullVerification::STATUS_FAILED,
            'latency_ms' => 200,
            'response_size_bytes' => 100,
            'verified_at' => now()->subMinutes(5),
            'warnings' => [],
            'errors' => ['verification failed'],
        ]);

        $summary = app(SloSummaryService::class)->summarize($feedProfile->shop, $feedProfile);

        $this->assertSame('degraded', $summary['status']);
        $this->assertSame(0.5, $summary['windows']['24h']['sync']['rate']);
        $this->assertSame(0.5, $summary['windows']['24h']['publish']['rate']);
        $this->assertSame(0.0, $summary['windows']['24h']['first_pull']['rate']);
    }
}
