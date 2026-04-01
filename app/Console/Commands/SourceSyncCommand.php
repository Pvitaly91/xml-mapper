<?php

namespace App\Console\Commands;

use App\Jobs\SyncSourceJob;
use App\Models\SourceConnection;
use Illuminate\Console\Command;

class SourceSyncCommand extends Command
{
    protected $signature = 'source:sync {sourceConnectionId? : Source connection ID} {--due : Sync all due source connections} {--queue : Dispatch to queue instead of sync execution}';

    protected $description = 'Sync source feed XML from Prom.';

    public function handle(): int
    {
        $connections = $this->resolveConnections();

        if ($connections->isEmpty()) {
            $this->warn('No source connections found for sync.');

            return self::SUCCESS;
        }

        foreach ($connections as $connection) {
            if ($this->option('queue')) {
                SyncSourceJob::dispatch($connection->id);
                $this->line("Queued source sync for connection #{$connection->id}.");
            } else {
                SyncSourceJob::dispatchSync($connection->id);
                $this->line("Synced source connection #{$connection->id}.");
            }
        }

        return self::SUCCESS;
    }

    private function resolveConnections()
    {
        return SourceConnection::query()
            ->when($this->argument('sourceConnectionId'), fn ($query, $id) => $query->whereKey($id))
            ->when($this->option('due'), fn ($query) => $query->where('status', SourceConnection::STATUS_ACTIVE)->where(function ($innerQuery): void {
                $innerQuery->whereNull('next_sync_at')->orWhere('next_sync_at', '<=', now());
            }))
            ->orderBy('id')
            ->get();
    }
}
