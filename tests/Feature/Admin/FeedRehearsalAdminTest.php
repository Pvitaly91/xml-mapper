<?php

namespace Tests\Feature\Admin;

use App\Contracts\Feeds\FeedBuildServiceInterface;
use App\Models\OpsRun;
use App\Services\Ops\RestoreDrillService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAdminContext;
use Tests\TestCase;

class FeedRehearsalAdminTest extends TestCase
{
    use CreatesAdminContext;
    use RefreshDatabase;

    public function test_rehearsal_screen_renders_and_launch_pack_downloads(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));

        ['admin' => $admin, 'feedProfile' => $feedProfile] = $this->seedBuildableCatalog();
        app(FeedBuildServiceInterface::class)->build($feedProfile);

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
        app(RestoreDrillService::class)->run($feedProfile, $admin, 'Admin rehearsal render');

        $this->actingAs($admin)
            ->get(route('admin.feed-profiles.rehearsal.show', $feedProfile))
            ->assertOk()
            ->assertSee('Staging Rehearsal');

        $this->actingAs($admin)
            ->get(route('admin.feed-profiles.launch-pack.show', $feedProfile))
            ->assertOk()
            ->assertHeader('content-disposition');
    }
}
