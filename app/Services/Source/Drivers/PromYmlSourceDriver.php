<?php

namespace App\Services\Source\Drivers;

use App\Contracts\Source\PromYmlParserInterface;
use App\Data\Source\ParsedSourceFeedData;
use App\Data\Source\SourceConnectionCheckResult;
use App\Exceptions\Source\SourceConfigurationException;
use App\Exceptions\Source\SourceNetworkException;
use App\Exceptions\Source\SourceRemoteException;
use App\Models\SourceConnection;
use App\Models\SourceImport;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PromYmlSourceDriver extends AbstractSourceDriver
{
    public function __construct(
        private readonly PromYmlParserInterface $parser,
    ) {
    }

    public function driver(): string
    {
        return SourceConnection::DRIVER_PROM_YML;
    }

    public function testConnection(SourceConnection $connection): SourceConnectionCheckResult
    {
        if (blank($connection->source_url)) {
            throw new SourceConfigurationException('Source URL is not configured for Prom YML.');
        }

        if (Str::startsWith($connection->source_url, ['http://', 'https://'])) {
            try {
                $response = Http::connectTimeout(10)
                    ->timeout(30)
                    ->retry(2, 250, throw: false)
                    ->get($connection->source_url);
            } catch (ConnectionException $exception) {
                throw new SourceNetworkException('Prom YML endpoint is unreachable.', previous: $exception);
            }

            if (! $response->successful()) {
                throw new SourceRemoteException(sprintf('Prom YML endpoint returned HTTP %d.', $response->status()));
            }

            return new SourceConnectionCheckResult(
                status: SourceConnection::CHECK_STATUS_OK,
                message: 'Prom YML source is reachable.',
                meta: [
                    'http_status' => $response->status(),
                ],
            );
        }

        if (! is_file($connection->source_url) || ! is_readable($connection->source_url)) {
            throw new SourceNetworkException(sprintf('Prom YML file [%s] is missing or unreadable.', $connection->source_url));
        }

        return new SourceConnectionCheckResult(
            status: SourceConnection::CHECK_STATUS_OK,
            message: 'Prom YML source file is readable.',
            meta: [
                'path' => $connection->source_url,
            ],
        );
    }

    public function sync(SourceConnection $connection): SourceImport
    {
        if (blank($connection->source_url)) {
            throw new SourceConfigurationException('Source URL is not configured for Prom YML.');
        }

        $import = SourceImport::create([
            'shop_id' => $connection->shop_id,
            'source_connection_id' => $connection->id,
            'status' => SourceImport::STATUS_PENDING,
            'started_at' => now(),
            'source_url_snapshot' => $connection->source_url,
            'meta' => [
                'driver' => $this->driver(),
            ],
        ]);

        try {
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

            $this->log($connection, $import, 'info', 'source.synced', 'Prom YML feed downloaded and cached.', [
                'path' => $relativePath,
            ]);

            return $import->refresh();
        } catch (\Throwable $exception) {
            $import->update([
                'status' => SourceImport::STATUS_FAILED,
                'finished_at' => now(),
                'error_message' => $exception->getMessage(),
            ]);

            $this->log($connection, $import, 'error', 'source.sync_failed', $exception->getMessage(), [
                'exception' => $exception::class,
            ]);

            throw $exception;
        }
    }

    public function loadFeedData(SourceConnection $connection, SourceImport $import): ParsedSourceFeedData
    {
        $path = $import->temp_path;

        if (blank($path)) {
            throw new SourceRemoteException('Prom YML import does not have a cached file path.');
        }

        return $this->parser->parseFile(Storage::disk(config('feed_mediator.storage_disk'))->path($path));
    }

    private function fetchToPath(string $sourceUrl, string $absolutePath): void
    {
        if (Str::startsWith($sourceUrl, ['http://', 'https://'])) {
            try {
                $response = Http::connectTimeout(10)
                    ->timeout(300)
                    ->retry(2, 1000, throw: false)
                    ->withOptions(['sink' => $absolutePath])
                    ->get($sourceUrl);
            } catch (ConnectionException $exception) {
                throw new SourceNetworkException('Failed to download Prom YML feed.', previous: $exception);
            }

            if (! $response->successful()) {
                throw new SourceRemoteException(sprintf('Failed to download Prom YML feed. HTTP %d.', $response->status()));
            }

            return;
        }

        $content = @file_get_contents($sourceUrl);

        if ($content === false) {
            throw new SourceNetworkException(sprintf('Unable to read Prom YML feed from [%s].', $sourceUrl));
        }

        file_put_contents($absolutePath, $content);
    }
}
