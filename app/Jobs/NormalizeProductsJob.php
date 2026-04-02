<?php

namespace App\Jobs;

use App\Contracts\Source\ProductNormalizerInterface;
use App\Contracts\Source\PromYmlParserInterface;
use App\Models\FeedProfile;
use App\Models\SourceImport;
use App\Models\SyncLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Throwable;

class NormalizeProductsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public int $tries = 3;

    public int $maxExceptions = 2;

    public function __construct(
        public readonly int $sourceImportId,
    ) {
        $this->onQueue((string) config('feed_mediator.queues.normalization'));
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(PromYmlParserInterface $parser, ProductNormalizerInterface $normalizer): void
    {
        $import = SourceImport::with('sourceConnection')->findOrFail($this->sourceImportId);
        $connection = $import->sourceConnection;
        $disk = Storage::disk(config('feed_mediator.storage_disk'));

        try {
            $feedData = $parser->parseFile($disk->path($import->temp_path));
            $normalizer->normalize($connection, $import, $feedData);

            FeedProfile::query()
                ->where('source_connection_id', $connection->id)
                ->where('status', FeedProfile::STATUS_ACTIVE)
                ->where('auto_build', true)
                ->pluck('id')
                ->each(fn (int $feedProfileId) => BuildFeedJob::dispatch($feedProfileId, true, $import->id));
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
    }
}
