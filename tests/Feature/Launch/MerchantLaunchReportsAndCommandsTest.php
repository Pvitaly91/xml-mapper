<?php

namespace Tests\Feature\Launch;

use App\Models\MerchantLaunch;
use App\Models\MerchantLaunchDefect;
use App\Models\MerchantLaunchObservation;
use App\Services\Launch\MerchantLaunchReportService;
use App\Services\Launch\MerchantLaunchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAdminContext;
use Tests\Concerns\CreatesPilotFixtureContext;
use Tests\TestCase;

class MerchantLaunchReportsAndCommandsTest extends TestCase
{
    use CreatesAdminContext;
    use CreatesPilotFixtureContext;
    use RefreshDatabase;

    public function test_observations_defects_and_reports_resolve_linked_entities(): void
    {
        ['admin' => $admin, 'feedProfile' => $feedProfile, 'run' => $pilotRun] = $this->completePilotRunFromFixtures();
        $this->recordSuccessfulDeployRun($feedProfile, $admin);
        $service = app(MerchantLaunchService::class);
        $reportService = app(MerchantLaunchReportService::class);
        $launch = $service->start($feedProfile, [
            'pilot_run_id' => $pilotRun->id,
        ], $admin);
        $feedbackImport = $feedProfile->feedbackImports()->latest('id')->first();
        $feedbackRecord = $feedProfile->feedbackRecords()->latest('id')->first();
        $feedItem = $feedProfile->items()->latest('id')->first();

        $observation = $service->addObservation($launch, [
            'type' => MerchantLaunchObservation::TYPE_UNEXPECTED_REJECTION_PATTERN,
            'severity' => MerchantLaunchObservation::SEVERITY_HIGH,
            'source' => 'feedback-import',
            'note' => 'Unexpected rejection spike on the first live merchant batch.',
            'feed_generation_id' => $launch->published_generation_id,
            'feed_item_id' => $feedItem?->id,
            'feedback_import_id' => $feedbackImport?->id,
        ], $admin);
        $defect = $service->addDefect($launch, [
            'type' => MerchantLaunchDefect::TYPE_FEEDBACK_MATCHING,
            'severity' => MerchantLaunchDefect::SEVERITY_HIGH,
            'title' => 'Feedback rows require matching fix',
            'note' => 'Feedback records need triage after the first import.',
            'merchant_launch_observation_id' => $observation->id,
            'feed_item_id' => $feedItem?->id,
            'feedback_record_id' => $feedbackRecord?->id,
        ], $admin);

        $this->assertSame($launch->published_generation_id, $observation->feed_generation_id);
        $this->assertSame($feedbackImport?->id, $observation->feedback_import_id);
        $this->assertSame($observation->id, $defect->merchant_launch_observation_id);
        $this->assertSame($feedbackRecord?->id, $defect->feedback_record_id);

        $summary = $reportService->generate($launch, 'summary');
        $observations = $reportService->generate($launch, 'observations');
        $defects = $reportService->generate($launch, 'defects');
        $closeout = $reportService->generate($launch, 'closeout');

        $this->assertFileExists($summary['absolute_path']);
        $this->assertFileExists($observations['absolute_path']);
        $this->assertFileExists($defects['absolute_path']);
        $this->assertFileExists($closeout['absolute_path']);
        $this->assertStringContainsString('Live Merchant Launch Closeout', $closeout['content']);
    }

    public function test_launch_commands_and_operator_pages_work(): void
    {
        ['admin' => $admin, 'feedProfile' => $feedProfile, 'run' => $pilotRun] = $this->completePilotRunFromFixtures();
        $this->recordSuccessfulDeployRun($feedProfile, $admin);

        $this->artisan('launch:start', [
            'feedProfileId' => $feedProfile->id,
            '--pilot' => $pilotRun->id,
            '--note' => 'Live launch via artisan',
        ])->assertSuccessful();

        $launch = MerchantLaunch::query()->latest('id')->firstOrFail();

        $this->artisan('launch:status', ['launchId' => $launch->id])->assertSuccessful();
        $this->artisan('launch:observe', [
            'launchId' => $launch->id,
            'type' => MerchantLaunchObservation::TYPE_MERCHANT_CONFIRMATION,
            '--note' => 'Merchant confirmed live visibility',
        ])->assertSuccessful();
        $this->artisan('launch:defect', [
            'launchId' => $launch->id,
            'type' => MerchantLaunchDefect::TYPE_FALSE_POSITIVE,
            '--severity' => MerchantLaunchDefect::SEVERITY_LOW,
            '--note' => 'False alarm from launch monitoring',
        ])->assertSuccessful();

        $defect = MerchantLaunchDefect::query()->latest('id')->firstOrFail();
        app(MerchantLaunchService::class)->updateDefect($defect, [
            'status' => MerchantLaunchDefect::STATUS_RESOLVED,
            'severity' => MerchantLaunchDefect::SEVERITY_LOW,
            'resolution_note' => 'Confirmed false alarm and resolved.',
        ], $admin);

        $this->artisan('launch:check', ['launchId' => $launch->id])->assertSuccessful();
        $this->artisan('launch:handover', [
            'launchId' => $launch->id,
            '--reason' => 'Stable after merchant confirmation',
        ])->assertSuccessful();
        $this->artisan('launch:close', [
            'launchId' => $launch->id,
            '--reason' => 'Launch closeout finished',
        ])->assertSuccessful();

        $closedLaunch = $launch->fresh();
        $this->assertSame(MerchantLaunch::STATE_CLOSED, $closedLaunch->state);

        $this->actingAs($admin)
            ->get(route('admin.merchant-launches.index'))
            ->assertOk();
        $this->actingAs($admin)
            ->get(route('admin.merchant-launches.show', $closedLaunch))
            ->assertOk();
        $this->actingAs($admin)
            ->get(route('admin.feed-profiles.operations.show', $feedProfile))
            ->assertOk();
        $this->actingAs($admin)
            ->get(route('admin.feed-profiles.hypercare.show', $feedProfile))
            ->assertOk();
        $this->actingAs($admin)
            ->get(route('admin.feed-profiles.release-center', $feedProfile))
            ->assertOk();
        $this->actingAs($admin)
            ->get(route('admin.feed-profiles.promotion.show', $feedProfile))
            ->assertOk();
        $this->actingAs($admin)
            ->get(route('admin.pilot-runs.show', $pilotRun))
            ->assertOk();
        $this->actingAs($admin)
            ->get(route('admin.feed-profiles.feedback-workbench.index', $feedProfile))
            ->assertOk();
        $this->get(route('feeds.public', $feedProfile->public_token))
            ->assertOk();
    }
}
