<?php

namespace App\Services\Source;

use App\Data\Source\SourceConnectionCheckResult;
use App\Exceptions\Source\SourceDriverException;
use App\Models\SourceConnection;
use App\Models\SourceImport;
use Throwable;

class SourceConnectionStateService
{
    public function recordConnectionCheck(SourceConnection $connection, SourceConnectionCheckResult $result): void
    {
        $connection->forceFill([
            'last_connection_check_at' => now(),
            'last_connection_check_status' => $result->status,
            'last_connection_check_message' => $result->message,
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    public function recordSyncSuccess(SourceConnection $connection, SourceImport $import, array $summary): void
    {
        $connection->forceFill([
            'last_sync_status' => SourceConnection::CHECK_STATUS_OK,
            'last_sync_message' => 'Source sync completed successfully.',
            'last_sync_summary' => array_merge($summary, [
                'import_id' => $import->id,
                'finished_at' => now()->toIso8601String(),
            ]),
        ])->save();
    }

    public function recordSyncFailure(SourceConnection $connection, Throwable $exception): void
    {
        $connection->forceFill([
            'last_sync_status' => $this->statusForThrowable($exception),
            'last_sync_message' => $exception->getMessage(),
            'last_sync_summary' => null,
        ])->save();
    }

    public function statusForThrowable(Throwable $exception): string
    {
        return $exception instanceof SourceDriverException
            ? $exception->status
            : SourceConnection::CHECK_STATUS_FAILED;
    }
}
