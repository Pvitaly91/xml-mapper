<?php

namespace App\Jobs;

use App\Actions\Ops\ResolveDueSourceConnectionsAction;
use App\Contracts\Source\SourceImportServiceInterface;
use App\Models\SourceConnection;
use App\Services\Ops\ProcessLockService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncSourceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

    public int $tries = 3;

    public int $maxExceptions = 2;

    public function __construct(
        public readonly int $sourceConnectionId,
        public readonly bool $onlyIfDue = false,
        public readonly ?string $dispatchLockOwner = null,
    ) {
        $this->onQueue((string) config('feed_mediator.queues.imports'));
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(
        SourceImportServiceInterface $sourceImportService,
        ResolveDueSourceConnectionsAction $resolveDueSourceConnections,
        ProcessLockService $lockService,
    ): void
    {
        try {
            $connection = SourceConnection::findOrFail($this->sourceConnectionId);

            if ($this->onlyIfDue && ! $resolveDueSourceConnections->handle(null, $connection->id)->contains('id', $connection->id)) {
                return;
            }

            $import = $sourceImportService->sync($connection);

            NormalizeProductsJob::dispatch($import->id);
        } finally {
            $lockService->releaseDispatchLock($lockService->sourceSyncKey($this->sourceConnectionId), $this->dispatchLockOwner);
        }
    }
}
