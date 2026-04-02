<?php

namespace App\Services\Source;

use App\Data\Source\SourceConnectionCheckResult;
use App\Exceptions\Source\SourceDriverException;
use App\Models\SourceConnection;
use App\Models\SourceImport;
use Throwable;

class SourceConnectionStateService
{
    public function __construct(
        private readonly \App\Services\Feeds\PilotNotificationService $notificationService,
    ) {
    }

    public function recordConnectionCheck(SourceConnection $connection, SourceConnectionCheckResult $result): void
    {
        $previousStatus = $connection->last_connection_check_status;

        $connection->forceFill([
            'last_connection_check_at' => now(),
            'last_connection_check_status' => $result->status,
            'last_connection_check_message' => $result->message,
        ])->save();

        $this->notifyBrokenAuthIfNeeded($connection->fresh(), $result->status, $previousStatus, 'Source connection test reported broken authentication.');
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
        $status = $this->statusForThrowable($exception);
        $previousStatus = $connection->last_sync_status;

        $connection->forceFill([
            'last_sync_status' => $status,
            'last_sync_message' => $exception->getMessage(),
            'last_sync_summary' => null,
        ])->save();

        $this->notifyBrokenAuthIfNeeded($connection->fresh(), $status, $previousStatus, 'Source sync reported broken authentication.');
    }

    public function statusForThrowable(Throwable $exception): string
    {
        return $exception instanceof SourceDriverException
            ? $exception->status
            : SourceConnection::CHECK_STATUS_FAILED;
    }

    private function notifyBrokenAuthIfNeeded(
        SourceConnection $connection,
        string $currentStatus,
        ?string $previousStatus,
        string $message
    ): void {
        if ($currentStatus !== SourceConnection::CHECK_STATUS_AUTH_FAILED || $previousStatus === SourceConnection::CHECK_STATUS_AUTH_FAILED) {
            return;
        }

        $this->notificationService->notifySourceConnectionAdmins(
            $connection,
            'source.auth_broken',
            'Source connection authentication failed',
            $message,
            [
                'source_connection_id' => $connection->id,
                'driver' => $connection->driver,
                'status' => $currentStatus,
                'connection_message' => $connection->last_connection_check_message,
                'sync_message' => $connection->last_sync_message,
            ],
            'error'
        );
    }
}
