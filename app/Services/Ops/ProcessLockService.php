<?php

namespace App\Services\Ops;

use App\Exceptions\ProcessAlreadyRunningException;
use Illuminate\Support\Facades\Cache;

class ProcessLockService
{
    public function runExclusive(string $key, int $ttlSeconds, string $message, callable $callback): mixed
    {
        $lock = Cache::lock($this->qualify($key), $ttlSeconds);

        if (! $lock->get()) {
            throw new ProcessAlreadyRunningException($message);
        }

        try {
            return $callback();
        } finally {
            rescue(static fn () => $lock->release(), report: false);
        }
    }

    public function acquireDispatchLock(string $key, int $ttlSeconds): ?string
    {
        $lock = Cache::lock($this->qualify('dispatch:'.$key), $ttlSeconds);

        if (! $lock->get()) {
            return null;
        }

        return $lock->owner();
    }

    public function releaseDispatchLock(string $key, ?string $owner): void
    {
        if ($owner === null || $owner === '') {
            return;
        }

        rescue(function () use ($key, $owner): void {
            Cache::restoreLock($this->qualify('dispatch:'.$key), $owner)->release();
        }, report: false);
    }

    public function sourceSyncKey(int $sourceConnectionId): string
    {
        return 'source-sync:'.$sourceConnectionId;
    }

    public function feedBuildKey(int $feedProfileId): string
    {
        return 'feed-build:'.$feedProfileId;
    }

    public function feedPublishProfileKey(int $feedProfileId): string
    {
        return 'feed-publish:profile:'.$feedProfileId;
    }

    public function feedPublishGenerationKey(int $feedGenerationId): string
    {
        return 'feed-publish:generation:'.$feedGenerationId;
    }

    public function dictionaryImportKey(string $type, string $checksum): string
    {
        return 'dictionary-import:'.$type.':'.$checksum;
    }

    private function qualify(string $key): string
    {
        $prefix = trim((string) config('feed_mediator.locks.prefix'), ':');

        return $prefix === '' ? $key : $prefix.':'.$key;
    }
}
