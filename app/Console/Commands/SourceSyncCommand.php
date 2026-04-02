<?php

namespace App\Console\Commands;

use App\Actions\Ops\ResolveDueSourceConnectionsAction;
use App\Jobs\SyncSourceJob;
use App\Models\SourceConnection;
use App\Services\Ops\ProcessLockService;
use Illuminate\Console\Command;

class SourceSyncCommand extends Command
{
    protected $signature = 'source:sync {sourceConnectionId? : Source connection ID} {--due : Sync all due source connections} {--queue : Dispatch to queue instead of sync execution}';

    protected $description = 'Sync source feed XML from Prom.';

    public function handle(ResolveDueSourceConnectionsAction $resolveDueSourceConnections, ProcessLockService $lockService): int
    {
        $connections = $this->resolveConnections($resolveDueSourceConnections);

        if ($connections->isEmpty()) {
            $this->warn('No source connections found for sync.');

            return self::SUCCESS;
        }

        foreach ($connections as $connection) {
            if ($this->option('queue')) {
                $dispatchOwner = $lockService->acquireDispatchLock(
                    $lockService->sourceSyncKey($connection->id),
                    (int) config('feed_mediator.locks.dispatch_ttl_seconds')
                );

                if ($dispatchOwner === null) {
                    $this->warn("Skipped source sync for connection #{$connection->id}: already queued.");

                    continue;
                }

                SyncSourceJob::dispatch($connection->id, (bool) $this->option('due'), $dispatchOwner);
                $this->line("Queued source sync for connection #{$connection->id}.");
            } else {
                SyncSourceJob::dispatchSync($connection->id, (bool) $this->option('due'));
                $this->line("Synced source connection #{$connection->id}.");
            }
        }

        return self::SUCCESS;
    }

    private function resolveConnections(ResolveDueSourceConnectionsAction $resolveDueSourceConnections)
    {
        if ($this->option('due')) {
            return $resolveDueSourceConnections->handle(null, $this->argument('sourceConnectionId') ? (int) $this->argument('sourceConnectionId') : null);
        }

        return SourceConnection::query()
            ->when($this->argument('sourceConnectionId'), fn ($query, $id) => $query->whereKey($id))
            ->orderBy('id')
            ->get();
    }
}
