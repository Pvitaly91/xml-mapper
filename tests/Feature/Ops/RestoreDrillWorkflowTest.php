<?php

namespace Tests\Feature\Ops;

use App\Models\OpsRun;
use App\Services\Ops\RestoreDrillService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAdminContext;
use Tests\TestCase;

class RestoreDrillWorkflowTest extends TestCase
{
    use CreatesAdminContext;
    use RefreshDatabase;

    public function test_restore_drill_history_is_persisted_and_report_generated(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));

        ['admin' => $admin, 'feedProfile' => $feedProfile] = $this->seedBuildableCatalog();

        OpsRun::create([
            'shop_id' => $feedProfile->shop_id,
            'type' => OpsRun::TYPE_BACKUP_DB,
            'status' => OpsRun::STATUS_SUCCEEDED,
            'artifact_path' => 'ops/backups/db/latest.sql',
            'started_at' => now()->subHour(),
            'finished_at' => now()->subHour(),
        ]);
        OpsRun::create([
            'shop_id' => $feedProfile->shop_id,
            'type' => OpsRun::TYPE_BACKUP_FILES,
            'status' => OpsRun::STATUS_SUCCEEDED,
            'artifact_path' => 'ops/backups/files/latest.zip',
            'started_at' => now()->subHour(),
            'finished_at' => now()->subHour(),
        ]);

        $result = app(RestoreDrillService::class)->run($feedProfile, $admin, 'Staging restore rehearsal');

        $this->assertSame(OpsRun::STATUS_SUCCEEDED, $result['run']->status);
        Storage::disk(config('feed_mediator.storage_disk'))->assertExists($result['report_path']);
        $this->assertDatabaseHas('ops_runs', [
            'type' => OpsRun::TYPE_RESTORE_DRILL,
            'feed_profile_id' => $feedProfile->id,
            'status' => OpsRun::STATUS_SUCCEEDED,
        ]);
    }
}
