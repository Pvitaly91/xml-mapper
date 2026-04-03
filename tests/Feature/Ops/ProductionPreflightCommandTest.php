<?php

namespace Tests\Feature\Ops;

use App\Models\OpsRun;
use App\Services\Ops\HeartbeatService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductionPreflightCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_preflight_passes_in_healthy_state(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));

        foreach (config('feed_mediator.preflight.required_directories') as $directory) {
            Storage::disk(config('feed_mediator.storage_disk'))->makeDirectory($directory);
        }

        config()->set('app.env', 'production');
        config()->set('app.debug', false);
        config()->set('app.url', 'https://xml-mapper.example.com');
        config()->set('queue.default', 'redis');

        Redis::shouldReceive('connection->ping')->once()->andReturn('PONG');
        Queue::shouldReceive('size')->andReturn(0);
        app(HeartbeatService::class)->recordSchedulerHeartbeat(CarbonImmutable::now());

        $this->artisan('ops:preflight-production')
            ->assertSuccessful();

        $this->assertDatabaseHas('ops_runs', [
            'type' => OpsRun::TYPE_PREFLIGHT,
            'status' => OpsRun::STATUS_SUCCEEDED,
        ]);
    }

    public function test_preflight_detects_missing_storage_and_redis(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));

        config()->set('app.env', 'production');
        config()->set('app.debug', false);
        config()->set('app.url', 'https://xml-mapper.example.com');
        config()->set('queue.default', 'redis');

        Redis::shouldReceive('connection->ping')->once()->andThrow(new \RuntimeException('redis offline'));
        Queue::shouldReceive('size')->andReturn(0);

        $this->artisan('ops:preflight-production')
            ->expectsOutputToContain('redis')
            ->expectsOutputToContain('storage')
            ->assertFailed();

        $this->assertDatabaseHas('ops_runs', [
            'type' => OpsRun::TYPE_PREFLIGHT,
            'status' => OpsRun::STATUS_FAILED,
        ]);
    }
}
