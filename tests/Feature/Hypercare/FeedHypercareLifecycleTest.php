<?php

namespace Tests\Feature\Hypercare;

use App\Models\FeedHypercareWindow;
use App\Models\OpsAlert;
use App\Services\Feeds\FeedHypercareService;
use App\Services\Feeds\FeedStabilityService;
use App\Services\Ops\OpsAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAdminContext;
use Tests\Concerns\CreatesPublishedHypercareContext;
use Tests\TestCase;

class FeedHypercareLifecycleTest extends TestCase
{
    use CreatesAdminContext;
    use CreatesPublishedHypercareContext;
    use RefreshDatabase;

    public function test_hypercare_can_be_started_extended_and_aborted_before_closeout(): void
    {
        $shop = $this->createShop();
        $admin = $this->createAdminUser($shop);
        $connection = $this->createSourceConnection($shop);
        $feedProfile = $this->createFeedProfile($connection, $admin);

        $service = app(FeedHypercareService::class);
        $window = $service->start($feedProfile->fresh(['publishedGeneration', 'latestGeneration', 'currentCutover']), 24, 'Plan launch watch', $admin);

        $this->assertSame(FeedHypercareWindow::STATUS_PLANNED, $window->status);

        $extended = $service->extend($window->fresh(), 24, 'Need another day', $admin);

        $this->assertSame(FeedHypercareWindow::STATUS_EXTENDED, $extended->status);
        $this->assertNotNull($extended->planned_end_at);

        $aborted = $service->abort($extended->fresh(), 'Merchant launch postponed', $admin);

        $this->assertSame(FeedHypercareWindow::STATUS_ABORTED, $aborted->status);
        $this->assertNotNull($aborted->actual_end_at);
    }

    public function test_hypercare_close_is_blocked_when_critical_alerts_are_open(): void
    {
        ['feedProfile' => $feedProfile, 'generation' => $generation, 'hypercare' => $hypercare] = $this->seedPublishedHypercareContext();

        app(OpsAlertService::class)->raiseForProfile(
            $feedProfile,
            OpsAlert::SOURCE_PUBLISH_FAILURE,
            OpsAlert::SEVERITY_CRITICAL,
            'Publish failed',
            'Critical publish issue',
            [],
            $generation,
            $hypercare
        );

        $this->expectExceptionMessage('Resolve all critical hypercare incidents before closing hypercare.');

        app(FeedHypercareService::class)->close($hypercare->fresh(), 'Attempt clean close');
    }

    public function test_hypercare_close_generates_closeout_when_no_critical_blockers_exist(): void
    {
        ['feedProfile' => $feedProfile, 'hypercare' => $hypercare] = $this->seedPublishedHypercareContext();

        $result = app(FeedHypercareService::class)->close($hypercare->fresh(), 'Stable after launch');

        $this->assertSame(FeedHypercareWindow::STATUS_COMPLETED, $result['window']->status);
        $this->assertStringContainsString('closeout', $result['report']['path']);
    }

    public function test_stability_score_is_computed_sanely_for_successful_launch_window(): void
    {
        ['feedProfile' => $feedProfile, 'hypercare' => $hypercare] = $this->seedPublishedHypercareContext();

        $stability = app(FeedStabilityService::class)->evaluate($feedProfile, $hypercare);

        $this->assertGreaterThanOrEqual(60, $stability['score']);
        $this->assertContains($stability['status'], ['stable', 'watch']);
    }
}
