<?php

namespace App\Services\Source;

use App\Contracts\Source\SourceImportServiceInterface;
use App\Models\SourceConnection;
use App\Models\SourceImport;
use App\Models\SyncLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class SourceImportService implements SourceImportServiceInterface
{
    public function sync(SourceConnection $connection): SourceImport
    {
        if ($connection->driver !== SourceConnection::DRIVER_PROM_YML) {
            throw new RuntimeException(sprintf('Unsupported source driver [%s].', $connection->driver));
        }

        if (blank($connection->source_url)) {
            throw new RuntimeException('Source URL is not configured.');
        }

        $import = SourceImport::create([
            'shop_id' => $connection->shop_id,
            'source_connection_id' => $connection->id,
            'status' => SourceImport::STATUS_PENDING,
            'started_at' => now(),
            'source_url_snapshot' => $connection->source_url,
        ]);

        $disk = Storage::disk(config('feed_mediator.storage_disk'));
        $relativePath = trim(config('feed_mediator.imports_directory'), '/').'/shop-'.$connection->shop_id.'/source-'.$connection->id.'/import-'.$import->id.'.xml';
        $absolutePath = $disk->path($relativePath);

        if (! is_dir(dirname($absolutePath))) {
            mkdir(dirname($absolutePath), 0777, true);
        }

        $this->fetchToPath($connection->source_url, $absolutePath);

        $import->update([
            'status' => SourceImport::STATUS_FETCHED,
            'fetched_at' => now(),
            'temp_path' => $relativePath,
            'source_checksum' => hash_file('sha256', $absolutePath) ?: null,
            'source_size_bytes' => filesize($absolutePath) ?: 0,
        ]);

        $connection->update([
            'last_synced_at' => now(),
            'next_sync_at' => now()->addMinutes($connection->sync_interval_minutes),
        ]);

        $this->log($connection, $import, 'info', 'source.synced', 'Source XML downloaded and cached.', [
            'path' => $relativePath,
        ]);

        return $import->refresh();
    }

    private function fetchToPath(string $sourceUrl, string $absolutePath): void
    {
        if (Str::startsWith($sourceUrl, ['http://', 'https://'])) {
            $response = Http::timeout(300)
                ->retry(2, 1000)
                ->withOptions(['sink' => $absolutePath])
                ->get($sourceUrl);

            if (! $response->successful()) {
                throw new RuntimeException(sprintf('Failed to download source feed. HTTP %s', $response->status()));
            }

            return;
        }

        $content = @file_get_contents($sourceUrl);

        if ($content === false) {
            throw new RuntimeException(sprintf('Unable to read source feed from [%s].', $sourceUrl));
        }

        file_put_contents($absolutePath, $content);
    }

    private function log(SourceConnection $connection, SourceImport $import, string $level, string $event, string $message, array $context = []): void
    {
        SyncLog::create([
            'shop_id' => $connection->shop_id,
            'source_connection_id' => $connection->id,
            'source_import_id' => $import->id,
            'level' => $level,
            'event' => $event,
            'message' => $message,
            'context' => $context,
            'occurred_at' => now(),
        ]);
    }
}
