<?php

namespace App\Services\Ops;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

class HeartbeatService
{
    public function recordSchedulerHeartbeat(?CarbonImmutable $timestamp = null): void
    {
        $this->record((string) config('feed_mediator.ops.scheduler_heartbeat_key'), $timestamp);
    }

    public function recordWorkerHeartbeat(?CarbonImmutable $timestamp = null): void
    {
        $this->record((string) config('feed_mediator.ops.worker_heartbeat_key'), $timestamp);
    }

    public function schedulerHeartbeat(): ?CarbonImmutable
    {
        return $this->read((string) config('feed_mediator.ops.scheduler_heartbeat_key'));
    }

    public function workerHeartbeat(): ?CarbonImmutable
    {
        return $this->read((string) config('feed_mediator.ops.worker_heartbeat_key'));
    }

    private function record(string $key, ?CarbonImmutable $timestamp = null): void
    {
        $timestamp ??= CarbonImmutable::now();

        Cache::forever($key, $timestamp->toIso8601String());
    }

    private function read(string $key): ?CarbonImmutable
    {
        $value = Cache::get($key);

        if (! is_string($value) || $value === '') {
            return null;
        }

        return CarbonImmutable::parse($value);
    }
}
