<?php

namespace App\Services\Source;

use App\Contracts\Source\ProductNormalizerInterface;
use App\Contracts\Source\SourceImportServiceInterface;
use App\Contracts\Source\SourceSyncWorkflowServiceInterface;
use App\Jobs\BuildFeedJob;
use App\Models\FeedProfile;
use App\Models\SourceConnection;
use App\Models\SourceImport;
use App\Models\SyncLog;
use Throwable;

class SourceSyncWorkflowService implements SourceSyncWorkflowServiceInterface
{
    public function __construct(
        private readonly SourceImportServiceInterface $sourceImportService,
        private readonly SourceDriverRegistry $drivers,
        private readonly ProductNormalizerInterface $normalizer,
        private readonly SourceConnectionStateService $stateService,
    ) {
    }

    public function prepare(SourceConnection $connection): SourceImport
    {
        return $this->sourceImportService->sync($connection);
    }

    public function normalize(SourceImport $import, bool $dispatchBuilds = true): SourceImport
    {
        $import->loadMissing('sourceConnection');
        $connection = $import->sourceConnection;
        $driver = $this->drivers->forConnection($connection);

        try {
            $feedData = $driver->loadFeedData($connection, $import);
            $summary = $this->normalizer->normalize($connection, $import, $feedData);

            $this->stateService->recordSyncSuccess($connection, $import, $summary);

            if ($dispatchBuilds) {
                FeedProfile::query()
                    ->where('source_connection_id', $connection->id)
                    ->where('status', FeedProfile::STATUS_ACTIVE)
                    ->where('auto_build', true)
                    ->pluck('id')
                    ->each(fn (int $feedProfileId) => BuildFeedJob::dispatch($feedProfileId, true, $import->id));
            }

            return $import->refresh();
        } catch (Throwable $exception) {
            $import->update([
                'status' => SourceImport::STATUS_FAILED,
                'finished_at' => now(),
                'error_message' => $exception->getMessage(),
            ]);

            $this->stateService->recordSyncFailure($connection, $exception);

            SyncLog::create([
                'shop_id' => $connection->shop_id,
                'source_connection_id' => $connection->id,
                'source_import_id' => $import->id,
                'level' => 'error',
                'event' => 'source.normalize_failed',
                'message' => $exception->getMessage(),
                'context' => ['exception' => $exception::class],
                'occurred_at' => now(),
            ]);

            throw $exception;
        }
    }

    public function run(SourceConnection $connection, bool $dispatchBuilds = true): SourceImport
    {
        return $this->normalize($this->prepare($connection), $dispatchBuilds);
    }
}
