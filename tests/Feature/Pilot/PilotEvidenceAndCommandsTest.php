<?php

namespace Tests\Feature\Pilot;

use App\Models\PilotRun;
use App\Models\SourceConnection;
use App\Services\Pilot\PilotEvidencePackService;
use App\Services\Pilot\PilotReadinessScoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ZipArchive;
use Tests\Concerns\CreatesAdminContext;
use Tests\Concerns\CreatesPilotFixtureContext;
use Tests\TestCase;

class PilotEvidenceAndCommandsTest extends TestCase
{
    use CreatesAdminContext;
    use CreatesPilotFixtureContext;
    use RefreshDatabase;

    public function test_evidence_pack_contains_expected_sections_for_completed_run(): void
    {
        ['run' => $run] = $this->completePilotRunFromFixtures();
        $bundle = app(PilotEvidencePackService::class)->generate($run);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($bundle['absolute_path']) === true);
        $this->assertNotFalse($zip->locateName('summary.json'));
        $this->assertNotFalse($zip->locateName('state-history.json'));
        $this->assertNotFalse($zip->locateName('publish-summary.json'));
        $this->assertNotFalse($zip->locateName('feedback-summary.json'));
        $this->assertNotFalse($zip->locateName('hypercare-summary.json'));
        $this->assertNotFalse($zip->locateName('index.html'));
        $this->assertNotFalse($zip->locateName('candidate.xml'));
        $summary = json_decode((string) $zip->getFromName('summary.json'), true, 512, JSON_THROW_ON_ERROR);
        $zip->close();

        $this->assertSame($run->id, $summary['pilot_run_id']);
        $this->assertSame('completed', $summary['state']);
    }

    public function test_readiness_score_reports_not_ready_for_broken_merchant_and_stable_after_launch_for_healthy_pilot(): void
    {
        $shop = $this->createShop();
        $admin = $this->createAdminUser($shop);
        $connection = $this->createSourceConnection($shop, [
            'last_connection_check_status' => SourceConnection::CHECK_STATUS_CONFIG_ERROR,
            'last_sync_status' => SourceConnection::CHECK_STATUS_CONFIG_ERROR,
        ]);
        $feedProfile = $this->createFeedProfile($connection, $admin, ['status' => 'active']);
        $service = app(PilotReadinessScoreService::class);

        $notReady = $service->score($feedProfile);

        $this->assertSame('not_ready', $notReady['status']);

        ['feedProfile' => $healthyProfile, 'run' => $run] = $this->completePilotRunFromFixtures();
        $healthy = $service->score($healthyProfile->fresh(['publishedGeneration', 'currentHypercareWindow']), $run->fresh());

        $this->assertSame('stable_after_launch', $healthy['status']);
        $this->assertGreaterThanOrEqual(80, $healthy['score']);
    }

    public function test_pilot_commands_plan_run_resume_status_evidence_and_abort(): void
    {
        ['feedProfile' => $feedProfile] = $this->seedPilotFixtureCatalog();

        $this->artisan('pilot:plan', ['feedProfileId' => $feedProfile->id])
            ->assertSuccessful();

        $plannedRun = PilotRun::query()->latest('id')->firstOrFail();

        $this->artisan('pilot:status', ['pilotRunId' => $plannedRun->id])
            ->assertSuccessful();
        $this->artisan('pilot:evidence', ['pilotRunId' => $plannedRun->id])
            ->assertSuccessful();

        $this->artisan('pilot:run', [
            'feedProfileId' => $feedProfile->id,
            '--with-sync' => true,
            '--with-build' => true,
        ])->assertSuccessful();

        $run = PilotRun::query()->latest('id')->firstOrFail();
        $this->assertSame(PilotRun::STATE_PUBLISH_PENDING, $run->state);

        $this->artisan('pilot:resume', [
            'pilotRunId' => $run->id,
            '--with-publish' => true,
            '--with-feedback-fixtures' => true,
        ])->assertSuccessful();

        $resumed = $run->fresh();
        $this->assertSame(PilotRun::STATE_COMPLETED, $resumed->state);

        $this->artisan('pilot:plan', ['feedProfileId' => $feedProfile->id])
            ->assertSuccessful();

        $abortable = PilotRun::query()->latest('id')->firstOrFail();

        $this->artisan('pilot:abort', [
            'pilotRunId' => $abortable->id,
            '--reason' => 'Operator stop',
        ])->assertSuccessful();

        $this->assertSame(PilotRun::STATE_ABORTED, $abortable->fresh()->state);
    }
}
