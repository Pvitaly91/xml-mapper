<?php

namespace App\Services\Source;

use App\Exceptions\Source\SourceAuthException;
use App\Exceptions\Source\SourceInvalidPayloadException;
use App\Exceptions\Source\SourceNetworkException;
use App\Exceptions\Source\SourceRateLimitException;
use App\Exceptions\Source\SourceRemoteException;
use App\Models\SourceConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

class PromApiHttpTransport
{
    /**
     * @param  array<string, scalar|null>  $query
     * @return array<string, mixed>
     */
    public function get(SourceConnection $connection, string $path, array $query = []): array
    {
        $maxAttempts = max(1, (int) config('feed_mediator.prom_api.retry_times', 3));

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $startedAt = microtime(true);

            try {
                $response = Http::acceptJson()
                    ->connectTimeout((int) config('feed_mediator.prom_api.connect_timeout_seconds', 10))
                    ->timeout((int) config('feed_mediator.prom_api.timeout_seconds', 30))
                    ->withToken((string) $connection->api_token)
                    ->withHeaders([
                        'X-LANGUAGE' => (string) ($connection->options['locale'] ?? config('feed_mediator.prom_api.locale', 'uk')),
                    ])
                    ->get($this->url($connection, $path), array_filter($query, static fn ($value): bool => $value !== null));
            } catch (ConnectionException $exception) {
                $this->log('warning', $connection, $path, $query, $attempt, null, $startedAt, [
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);

                if ($attempt < $maxAttempts) {
                    $this->sleep($attempt);

                    continue;
                }

                throw new SourceNetworkException('Prom API request failed due to a network error.', previous: $exception);
            }

            $this->log(
                $response->successful() ? 'info' : 'warning',
                $connection,
                $path,
                $query,
                $attempt,
                $response,
                $startedAt,
            );

            if (in_array($response->status(), [401, 403], true)) {
                throw new SourceAuthException($this->remoteMessage($response) ?: sprintf('Prom API authorization failed with HTTP %d.', $response->status()));
            }

            if ($response->status() === 429) {
                if ($attempt < $maxAttempts) {
                    $this->sleep($attempt, $response);

                    continue;
                }

                throw new SourceRateLimitException($this->remoteMessage($response) ?: 'Prom API rate limit reached.');
            }

            if ($response->serverError()) {
                if ($attempt < $maxAttempts) {
                    $this->sleep($attempt, $response);

                    continue;
                }

                throw new SourceRemoteException($this->remoteMessage($response) ?: sprintf('Prom API server error HTTP %d.', $response->status()));
            }

            if (! $response->successful()) {
                throw new SourceRemoteException($this->remoteMessage($response) ?: sprintf('Prom API request failed with HTTP %d.', $response->status()));
            }

            $payload = $response->json();

            if (! is_array($payload)) {
                throw new SourceInvalidPayloadException('Prom API returned malformed JSON.');
            }

            return $payload;
        }

        throw new SourceRemoteException('Prom API request failed after exhausting retries.');
    }

    private function url(SourceConnection $connection, string $path): string
    {
        return $connection->apiEndpointBase().'/'.ltrim($path, '/');
    }

    /**
     * @param  array<string, scalar|null>  $query
     * @param  array<string, mixed>  $extra
     */
    private function log(
        string $level,
        SourceConnection $connection,
        string $path,
        array $query,
        int $attempt,
        ?Response $response,
        float $startedAt,
        array $extra = [],
    ): void {
        Log::log($level, 'prom_api.request', array_merge([
            'connection_id' => $connection->id,
            'driver' => $connection->driver,
            'base_url' => $connection->resolvedApiBaseUrl(),
            'api_version' => $connection->resolvedApiVersion(),
            'path' => '/'.ltrim($path, '/'),
            'query' => array_filter($query, static fn ($value): bool => $value !== null),
            'attempt' => $attempt,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'http_status' => $response?->status(),
            'request_id' => $response?->header('X-Request-Id'),
            'retry_after' => $response?->header('Retry-After'),
        ], $extra));
    }

    private function sleep(int $attempt, ?Response $response = null): void
    {
        $retryAfter = $response?->header('Retry-After');

        if (is_numeric($retryAfter)) {
            Sleep::for((int) $retryAfter)->seconds();

            return;
        }

        $base = max(1, (int) config('feed_mediator.prom_api.retry_backoff_ms', 250));
        $milliseconds = $base * (2 ** max(0, $attempt - 1));

        Sleep::for($milliseconds)->milliseconds();
    }

    private function remoteMessage(Response $response): ?string
    {
        $payload = $response->json();

        if (is_array($payload)) {
            foreach (['error', 'message', 'detail'] as $key) {
                $value = $payload[$key] ?? null;

                if (is_string($value) && trim($value) !== '') {
                    return trim($value);
                }
            }
        }

        return null;
    }
}
