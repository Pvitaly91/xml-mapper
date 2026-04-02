<?php

namespace App\Services\Source;

use App\Contracts\Source\SourceImportServiceInterface;
use App\Models\SourceConnection;
use App\Models\SourceImport;
use App\Services\Ops\ProcessLockService;
use Throwable;

class SourceImportService implements SourceImportServiceInterface
{
    public function __construct(
        private readonly ProcessLockService $lockService,
        private readonly SourceDriverRegistry $drivers,
        private readonly SourceConnectionStateService $stateService,
    ) {
    }

    public function sync(SourceConnection $connection): SourceImport
    {
        return $this->lockService->runExclusive(
            $this->lockService->sourceSyncKey($connection->id),
            (int) config('feed_mediator.locks.source_sync_ttl_seconds'),
            'Source connection sync is already in progress.',
            function () use ($connection): SourceImport {
                try {
                    $import = $this->drivers->forConnection($connection)->sync($connection);

                    $connection->update([
                        'last_synced_at' => now(),
                        'next_sync_at' => now()->addMinutes($connection->sync_interval_minutes),
                    ]);

                    return $import->refresh();
                } catch (Throwable $exception) {
                    $this->stateService->recordSyncFailure($connection, $exception);

                    throw $exception;
                }
            }
        );
    }
}
