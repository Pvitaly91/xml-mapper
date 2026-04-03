<?php

namespace Tests\Feature\Pilot;

use App\Models\PilotRun;
use App\Models\SourceConnection;
use App\Services\Pilot\PilotExecutionService;
use App\Services\Source\SourceConnectionTestService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAdminContext;
use Tests\Concerns\CreatesPilotFixtureContext;
use Tests\TestCase;

class PilotExecutionWorkflowTest extends TestCase
{
    use CreatesAdminContext;
    use CreatesPilotFixtureContext;
    use RefreshDatabase;

    public function test_plan_and_resume_manual_publish_happy_path_with_prom_yml_fixtures(): void
    {
        ['admin' => $admin, 'feedProfile' => $feedProfile] = $this->seedPilotFixtureCatalog();
        $service = app(PilotExecutionService::class);

        $run = $service->plan($feedProfile, ['note' => 'Pilot fixture run'], $admin);
        $this->assertSame(PilotRun::STATE_PLANNED, $run->state);

        $run = $service->runUntilPauseOrCompletion($run, [
            'with_sync' => true,
            'with_build' => true,
            'with_publish' => false,
        ], $admin);

        $this->assertSame(PilotRun::STATE_PUBLISH_PENDING, $run->state, json_encode($run->meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $this->assertTrue((bool) data_get($run->summary, 'execution.manual_publish_required'));
        $this->assertNotNull($run->candidate_generation_id);

        $run = $service->resume($run->fresh(), [
            'with_publish' => true,
            'with_feedback_fixtures' => true,
        ], $admin);

        $this->assertSame(PilotRun::STATE_COMPLETED, $run->state, json_encode($run->meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $this->assertNotNull($run->published_generation_id);
        $this->assertSame('stable_after_launch', data_get($run->summary, 'readiness.status'));
        $this->assertGreaterThanOrEqual(2, (int) data_get($run->summary, 'sections.feedback.imports_total', 0));
    }

    public function test_prom_api_happy_path_completes_with_fixture_backed_sync_and_build(): void
    {
        ['admin' => $admin, 'connection' => $connection, 'feedProfile' => $feedProfile] = $this->seedPilotFixtureCatalog(SourceConnection::DRIVER_PROM_API);
        $service = app(PilotExecutionService::class);

        $run = $service->run($feedProfile, [
            'with_sync' => true,
            'with_build' => true,
            'with_publish' => true,
            'with_feedback_fixtures' => true,
        ], $admin);

        $this->assertSame(PilotRun::STATE_COMPLETED, $run->state, json_encode([
            'state' => $run->state,
            'current_step' => $run->current_step,
            'blocking_reason' => $run->blocking_reason,
            'summary' => $run->summary,
            'meta' => $run->meta,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $this->assertSame(SourceConnection::DRIVER_PROM_API, $connection->driver);
        $this->assertSame(2, (int) data_get($connection->fresh('latestImport')->latestImport?->meta, 'products_pages_total', 0));
        $this->assertSame('succeeded', data_get($run->summary, 'sections.promotion.apply_status'));
        $this->assertSame('ok', $feedProfile->fresh()->publishedGeneration?->last_smoke_check_status);
    }

    public function test_secret_rebind_path_blocks_then_resumes_after_operator_validation(): void
    {
        ['admin' => $admin, 'connection' => $connection, 'feedProfile' => $feedProfile] = $this->seedPilotFixtureCatalog(
            SourceConnection::DRIVER_PROM_API,
            [
                'last_connection_check_status' => SourceConnection::CHECK_STATUS_CONFIG_ERROR,
                'last_sync_status' => SourceConnection::CHECK_STATUS_OK,
            ]
        );
        $service = app(PilotExecutionService::class);

        $run = $service->run($feedProfile, [
            'with_sync' => true,
            'with_build' => true,
            'with_publish' => true,
            'with_feedback_fixtures' => true,
        ], $admin);

        $this->assertSame(PilotRun::STATE_BLOCKED, $run->state);
        $this->assertSame('secret_rebind_missing', data_get($run->summary, 'blocker.code'));

        app(SourceConnectionTestService::class)->test($connection->fresh());

        $run = $service->resume($run->fresh(), [
            'with_sync' => true,
            'with_publish' => true,
            'with_feedback_fixtures' => true,
        ], $admin);

        $this->assertSame(PilotRun::STATE_COMPLETED, $run->state);
        $this->assertSame('stable_after_launch', data_get($run->summary, 'readiness.status'));
    }

    public function test_publish_window_blocker_is_detected_and_resume_succeeds_after_window_change(): void
    {
        Carbon::setTestNow('2026-04-03 12:00:00');

        ['admin' => $admin, 'feedProfile' => $feedProfile] = $this->seedPilotFixtureCatalog(
            SourceConnection::DRIVER_PROM_YML,
            [],
            [
                'settings' => [
                    'signoff_required' => true,
                    'required_signoff_status' => 'internal_approved',
                    'publish_guard_enabled' => false,
                    'publish_window_enabled' => true,
                    'publish_window_days' => ['fri'],
                    'publish_window_start' => '23:00',
                    'publish_window_end' => '23:30',
                ],
            ]
        );
        $service = app(PilotExecutionService::class);

        $run = $service->run($feedProfile, [
            'with_sync' => true,
            'with_build' => true,
            'with_publish' => true,
        ], $admin);

        $this->assertSame(PilotRun::STATE_BLOCKED, $run->state);
        $this->assertSame('publish_window_blocked', data_get($run->summary, 'blocker.code'));

        $feedProfile->fresh()->update([
            'settings' => [
                'signoff_required' => true,
                'required_signoff_status' => 'internal_approved',
                'publish_guard_enabled' => false,
                'publish_window_enabled' => true,
                'publish_window_days' => ['fri'],
                'publish_window_start' => '11:00',
                'publish_window_end' => '13:00',
            ],
        ]);

        $run = $service->resume($run->fresh(), [
            'with_publish' => true,
            'with_feedback_fixtures' => true,
        ], $admin);

        $this->assertSame(PilotRun::STATE_COMPLETED, $run->state);

        Carbon::setTestNow();
    }

    public function test_smoke_failure_blocks_release_verification_and_can_resume_after_feed_restore(): void
    {
        ['admin' => $admin, 'feedProfile' => $feedProfile] = $this->seedPilotFixtureCatalog();
        $service = app(PilotExecutionService::class);
        $run = $service->plan($feedProfile, [], $admin);
        $options = [
            'with_sync' => true,
            'with_build' => true,
            'with_publish' => true,
        ];

        while ($run->state !== PilotRun::STATE_PUBLISHED) {
            $result = $service->executeNextStep($run, $options, $admin);
            $run = $result['run'];
            $this->assertTrue($result['progressed']);
        }

        Storage::disk(config('feed_mediator.storage_disk'))->delete($feedProfile->fresh()->published_path);

        $result = $service->executeNextStep($run->fresh(), $options, $admin);
        $run = $result['run'];

        $this->assertSame(PilotRun::STATE_BLOCKED, $run->state);
        $this->assertSame('smoke_failure', data_get($run->summary, 'blocker.code'));

        Storage::disk(config('feed_mediator.storage_disk'))->put(
            $feedProfile->fresh()->published_path,
            Storage::disk(config('feed_mediator.storage_disk'))->get($run->publishedGeneration->file_path)
        );

        $run = $service->resume($run->fresh(), [
            'with_feedback_fixtures' => true,
        ], $admin);

        $this->assertSame(PilotRun::STATE_COMPLETED, $run->state);
    }
}
