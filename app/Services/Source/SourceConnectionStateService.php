<?php

namespace App\Services\Source;

use App\Data\Source\SourceConnectionCheckResult;
use App\Exceptions\Source\SourceDriverException;
use App\Models\SourceConnection;
use App\Models\SourceImport;
use App\Models\OpsAlert;
use App\Services\Feeds\PilotNotificationService;
use App\Services\Ops\OpsAlertService;
use Throwable;

class SourceConnectionStateService
{
    public function __construct(
        private readonly PilotNotificationService $notificationService,
        private readonly OpsAlertService $alertService,
    ) {}

    public function recordConnectionCheck(SourceConnection $connection, SourceConnectionCheckResult $result): void
    {
        $previousStatus = $connection->last_connection_check_status;

        $connection->forceFill([
            'last_connection_check_at' => now(),
            'last_connection_check_status' => $result->status,
            'last_connection_check_message' => $result->message,
        ])->save();
        $connection->savePromotionMeta([
            'secret_state' => $connection->promotionSecretState(),
            'secret_rebind_required' => $connection->promotionSecretRebindRequired(),
            'validated_at' => $result->status === SourceConnection::CHECK_STATUS_OK
                ? now()->toIso8601String()
                : data_get($connection->promotionMeta(), 'validated_at'),
            'last_connection_check_status' => $result->status,
        ]);

        $this->notifyBrokenAuthIfNeeded($connection->fresh(), $result->status, $previousStatus, 'Source connection test reported broken authentication.');

        if ($result->status === SourceConnection::CHECK_STATUS_AUTH_FAILED) {
            $this->alertService->syncConnectionAlert(
                $connection->fresh(),
                OpsAlert::SOURCE_SOURCE_AUTH_BROKEN,
                OpsAlert::SEVERITY_CRITICAL,
                'Source authentication failed',
                $result->message ?: 'Source connection test reported broken authentication.',
                ['connection_check' => true]
            );
        } else {
            $this->alertService->syncConnectionAlert(
                $connection->fresh(),
                OpsAlert::SOURCE_SOURCE_AUTH_BROKEN,
                null,
                'Source authentication recovered',
                'Source connection authentication recovered.',
            );
        }
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
        $connection->savePromotionMeta([
            'secret_state' => $connection->promotionSecretState(),
            'secret_rebind_required' => $connection->promotionSecretRebindRequired(),
        ]);

        $this->alertService->syncConnectionAlert(
            $connection->fresh(),
            OpsAlert::SOURCE_SYNC_FAILURE,
            null,
            'Source sync recovered',
            'Source sync completed successfully.'
        );

        if ($connection->last_connection_check_status !== SourceConnection::CHECK_STATUS_AUTH_FAILED) {
            $this->alertService->syncConnectionAlert(
                $connection->fresh(),
                OpsAlert::SOURCE_SOURCE_AUTH_BROKEN,
                null,
                'Source authentication recovered',
                'Source connection authentication recovered.'
            );
        }
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
        $connection->savePromotionMeta([
            'secret_state' => $connection->promotionSecretState(),
            'secret_rebind_required' => $connection->promotionSecretRebindRequired(),
        ]);

        $this->notifyBrokenAuthIfNeeded($connection->fresh(), $status, $previousStatus, 'Source sync reported broken authentication.');

        $this->alertService->syncConnectionAlert(
            $connection->fresh(),
            OpsAlert::SOURCE_SYNC_FAILURE,
            $status === SourceConnection::CHECK_STATUS_AUTH_FAILED ? OpsAlert::SEVERITY_CRITICAL : OpsAlert::SEVERITY_WARNING,
            'Source sync failed',
            $exception->getMessage(),
            ['exception' => $exception::class]
        );

        if ($status === SourceConnection::CHECK_STATUS_AUTH_FAILED) {
            $this->alertService->syncConnectionAlert(
                $connection->fresh(),
                OpsAlert::SOURCE_SOURCE_AUTH_BROKEN,
                OpsAlert::SEVERITY_CRITICAL,
                'Source authentication failed',
                $exception->getMessage(),
                ['exception' => $exception::class]
            );
        }
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
