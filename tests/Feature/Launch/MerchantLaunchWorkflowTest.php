<?php

namespace Tests\Feature\Launch;

use App\Models\MerchantLaunch;
use App\Models\MerchantLaunchDefect;
use App\Models\MerchantLaunchObservation;
use App\Models\MerchantLaunchTuningAction;
use App\Services\Launch\MerchantLaunchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\CreatesAdminContext;
use Tests\Concerns\CreatesPilotFixtureContext;
use Tests\TestCase;

class MerchantLaunchWorkflowTest extends TestCase
{
    use CreatesAdminContext;
    use CreatesPilotFixtureContext;
    use RefreshDatabase;

    public function test_launch_start_without_completed_pilot_stays_planned(): void
    {
        ['admin' => $admin, 'feedProfile' => $feedProfile] = $this->seedPilotFixtureCatalog();
        $launchService = app(MerchantLaunchService::class);

        $launch = $launchService->start($feedProfile, ['note' => 'Plan the first live launch'], $admin);

        $this->assertSame(MerchantLaunch::STATE_PLANNED, $launch->state);
        $this->assertSame(MerchantLaunch::HANDOVER_BLOCKED, $launch->handover_state);
        $this->assertContains(
            'A completed pilot run is not linked to this launch.',
            $launchService->check($launch)['critical_blockers']
        );
    }

    public function test_launch_lifecycle_transitions_from_validating_to_handover_and_close(): void
    {
        ['admin' => $admin, 'feedProfile' => $feedProfile, 'run' => $pilotRun] = $this->completePilotRunFromFixtures();
        $this->recordSuccessfulDeployRun($feedProfile, $admin);
        $launchService = app(MerchantLaunchService::class);

        $launch = $launchService->start($feedProfile, [
            'pilot_run_id' => $pilotRun->id,
            'note' => 'First live launch',
        ], $admin);

        $this->assertSame(MerchantLaunch::STATE_VALIDATING, $launch->state);
        $this->assertSame(MerchantLaunch::HANDOVER_BLOCKED, $launch->handover_state);

        $launchService->addObservation($launch, [
            'type' => MerchantLaunchObservation::TYPE_MERCHANT_CONFIRMATION,
            'severity' => MerchantLaunchObservation::SEVERITY_MEDIUM,
            'note' => 'Merchant confirmed the live listing is visible.',
        ], $admin);

        $launch = $launchService->refresh($launch->fresh());

        $this->assertSame(MerchantLaunch::STATE_STABILIZED, $launch->state);
        $this->assertSame(MerchantLaunch::HANDOVER_READY, $launch->handover_state);

        $launch = $launchService->handover($launch->fresh(), 'Stable after first live confirmation.', $admin);

        $this->assertSame(MerchantLaunch::HANDOVER_DONE, $launch->handover_state);

        $launch = $launchService->close($launch->fresh(), 'Launch closed after stable handover.', $admin);

        $this->assertSame(MerchantLaunch::STATE_CLOSED, $launch->state);
        $this->assertNotNull($launch->closed_at);
    }

    public function test_handover_is_blocked_when_critical_defect_exists(): void
    {
        ['admin' => $admin, 'feedProfile' => $feedProfile, 'run' => $pilotRun] = $this->completePilotRunFromFixtures();
        $this->recordSuccessfulDeployRun($feedProfile, $admin);
        $service = app(MerchantLaunchService::class);

        $launch = $service->start($feedProfile, [
            'pilot_run_id' => $pilotRun->id,
        ], $admin);

        $service->addObservation($launch, [
            'type' => MerchantLaunchObservation::TYPE_MERCHANT_CONFIRMATION,
            'severity' => MerchantLaunchObservation::SEVERITY_MEDIUM,
            'note' => 'Merchant confirmed the listing.',
        ], $admin);

        $service->addDefect($launch, [
            'type' => MerchantLaunchDefect::TYPE_OPS,
            'severity' => MerchantLaunchDefect::SEVERITY_CRITICAL,
            'title' => 'Critical live ops issue',
            'note' => 'Marketplace pulled stale content during launch.',
        ], $admin);

        $launch = $service->refresh($launch->fresh());

        $this->assertSame(MerchantLaunch::STATE_DEGRADED, $launch->state);

        $this->expectException(RuntimeException::class);
        $service->handover($launch, 'Should not hand over while critical defect is open.', $admin);
    }

    public function test_baseline_out_of_range_raises_alert_and_surfaces_blocker(): void
    {
        ['admin' => $admin, 'feedProfile' => $feedProfile, 'run' => $pilotRun] = $this->completePilotRunFromFixtures();
        $this->recordSuccessfulDeployRun($feedProfile, $admin);
        $service = app(MerchantLaunchService::class);

        $launch = $service->start($feedProfile, [
            'pilot_run_id' => $pilotRun->id,
        ], $admin);

        $launch = $service->updateBaseline($launch, [
            'expected_ready_items' => 9999,
        ], $admin);

        $this->assertSame('out_of_range', data_get($launch->summary, 'baseline.metrics.ready_items.status'));
        $this->assertSame(MerchantLaunch::STATE_DEGRADED, $launch->state);
        $this->assertDatabaseHas('ops_alerts', [
            'feed_profile_id' => $feedProfile->id,
            'fingerprint' => 'launch-'.$launch->id.'-ready_items',
        ]);
        $this->assertContains('Actual launch metrics are outside the expected band.', $service->check($launch)['critical_blockers']);
    }

    public function test_tuning_persists_reason_actor_and_updates_feed_profile_settings(): void
    {
        ['admin' => $admin, 'feedProfile' => $feedProfile, 'run' => $pilotRun] = $this->completePilotRunFromFixtures();
        $this->recordSuccessfulDeployRun($feedProfile, $admin);
        $service = app(MerchantLaunchService::class);
        $launch = $service->start($feedProfile, [
            'pilot_run_id' => $pilotRun->id,
        ], $admin);

        $action = $service->applyTuning($launch, [
            'type' => MerchantLaunchTuningAction::TYPE_EXCLUDED_VENDOR,
            'mode' => MerchantLaunchTuningAction::MODE_EMERGENCY,
            'value' => 'Bad Brand',
            'reason' => 'Emergency suppression for repeated launch defects.',
        ], $admin);

        $this->assertSame($admin->id, $action->user_id);
        $this->assertContains('Bad Brand', $feedProfile->fresh()->exportSettings()['excluded_vendors']);
        $this->assertTrue(
            $service->history($launch->fresh())->contains(fn ($event) => $event->action === 'live_launch_tuning_applied')
        );
    }
}
