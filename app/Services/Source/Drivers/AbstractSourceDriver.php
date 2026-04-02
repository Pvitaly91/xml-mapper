<?php

namespace App\Services\Source\Drivers;

use App\Contracts\Source\SourceDriverInterface;
use App\Models\SourceConnection;
use App\Models\SourceImport;
use App\Models\SyncLog;

abstract class AbstractSourceDriver implements SourceDriverInterface
{
    protected function log(
        SourceConnection $connection,
        ?SourceImport $import,
        string $level,
        string $event,
        string $message,
        array $context = [],
    ): void {
        SyncLog::create([
            'shop_id' => $connection->shop_id,
            'source_connection_id' => $connection->id,
            'source_import_id' => $import?->id,
            'level' => $level,
            'event' => $event,
            'message' => $message,
            'context' => $context,
            'occurred_at' => now(),
        ]);
    }
}
