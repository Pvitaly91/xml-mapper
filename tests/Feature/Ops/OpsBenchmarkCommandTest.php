<?php

namespace Tests\Feature\Ops;

use App\Contracts\Feeds\FeedBuildServiceInterface;
use App\Models\OpsRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAdminContext;
use Tests\TestCase;

class OpsBenchmarkCommandTest extends TestCase
{
    use CreatesAdminContext;
    use RefreshDatabase;

    public function test_benchmark_command_persists_summary(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));

        ['feedProfile' => $feedProfile] = $this->seedBuildableCatalog();
        app(FeedBuildServiceInterface::class)->build($feedProfile);

        $this->artisan('ops:benchmark-feed '.$feedProfile->id)
            ->assertSuccessful();

        $this->assertDatabaseHas('ops_runs', [
            'type' => OpsRun::TYPE_BENCHMARK,
            'feed_profile_id' => $feedProfile->id,
            'status' => OpsRun::STATUS_SUCCEEDED,
        ]);
    }
}
