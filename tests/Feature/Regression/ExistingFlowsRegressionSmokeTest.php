<?php

namespace Tests\Feature\Regression;

use App\Models\SourceConnection;
use App\Services\Feeds\FeedPreviewLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAdminContext;
use Tests\Concerns\CreatesPublishedHypercareContext;
use Tests\TestCase;

class ExistingFlowsRegressionSmokeTest extends TestCase
{
    use CreatesAdminContext;
    use CreatesPublishedHypercareContext;
    use RefreshDatabase;

    public function test_public_feed_preview_release_center_and_war_room_routes_stay_available(): void
    {
        ['admin' => $admin, 'feedProfile' => $feedProfile, 'generation' => $generation] = $this->seedPublishedHypercareContext();
        $previewLink = app(FeedPreviewLinkService::class)->create($generation, 60, $admin, 'Regression preview');

        $this->actingAs($admin)
            ->get(route('admin.feed-profiles.release-center', $feedProfile))
            ->assertOk();
        $this->actingAs($admin)
            ->get(route('admin.feed-profiles.promotion.show', $feedProfile))
            ->assertOk();
        $this->actingAs($admin)
            ->get(route('admin.feed-profiles.operations.show', $feedProfile))
            ->assertOk();
        $this->actingAs($admin)
            ->get(route('admin.feed-profiles.hypercare.show', $feedProfile))
            ->assertOk();
        $this->actingAs($admin)
            ->get(route('admin.onboarding.show'))
            ->assertOk();
        $this->actingAs($admin)
            ->get(route('admin.shop-control.show'))
            ->assertOk();
        $this->actingAs($admin)
            ->get(route('admin.pilot-runs.index'))
            ->assertOk();
        $this->actingAs($admin)
            ->get(route('admin.performance.index'))
            ->assertOk();
        $this->actingAs($admin)
            ->get(route('admin.feed-profiles.mapping-coverage.show', $feedProfile))
            ->assertOk();
        $this->actingAs($admin)
            ->get(route('admin.feed-profiles.content-enrichment.index', $feedProfile))
            ->assertOk();

        $this->get(route('feeds.public', $feedProfile->public_token))
            ->assertOk()
            ->assertHeader('content-type', 'application/xml; charset=UTF-8');

        $this->get(app(FeedPreviewLinkService::class)->urlFor($previewLink))
            ->assertOk()
            ->assertHeader('content-type', 'application/xml; charset=UTF-8');
    }

    public function test_build_publish_pipeline_is_not_broken_for_prom_yml_profiles(): void
    {
        ['feedProfile' => $promYmlProfile] = $this->seedPublishedHypercareContext();

        $this->assertSame(SourceConnection::DRIVER_PROM_YML, $promYmlProfile->sourceConnection->driver);
        $this->assertNotNull($promYmlProfile->published_generation_id);
    }

    public function test_build_publish_pipeline_is_not_broken_for_prom_api_profiles(): void
    {
        ['feedProfile' => $promApiProfile] = $this->seedPublishedHypercareContext(true);

        $this->assertSame(SourceConnection::DRIVER_PROM_API, $promApiProfile->sourceConnection->driver);
        $this->assertNotNull($promApiProfile->published_generation_id);
    }
}
