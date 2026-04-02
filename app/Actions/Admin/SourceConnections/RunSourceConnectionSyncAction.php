<?php

namespace App\Actions\Admin\SourceConnections;

use App\Contracts\Source\ProductNormalizerInterface;
use App\Contracts\Source\PromYmlParserInterface;
use App\Contracts\Source\SourceImportServiceInterface;
use App\Models\SourceConnection;
use App\Models\SourceImport;
use App\Models\SyncLog;
use Illuminate\Support\Facades\Storage;
use Throwable;

class RunSourceConnectionSyncAction
{
    public function __construct(
        private readonly SourceImportServiceInterface $sourceImportService,
        private readonly PromYmlParserInterface $parser,
        private readonly ProductNormalizerInterface $normalizer,
    ) {
    }

    public function handle(SourceConnection $connection): SourceImport
    {
        $import = $this->sourceImportService->sync($connection);
        $disk = Storage::disk(config('feed_mediator.storage_disk'));

        try {
            $feedData = $this->parser->parseFile($disk->path($import->temp_path));
            $this->normalizer->normalize($connection, $import, $feedData);
        } catch (Throwable $exception) {
            $import->update([
                'status' => SourceImport::STATUS_FAILED,
                'finished_at' => now(),
                'error_message' => $exception->getMessage(),
            ]);

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

        return $import->refresh();
    }
}
