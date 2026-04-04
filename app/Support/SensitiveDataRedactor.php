<?php

namespace App\Support;

class SensitiveDataRedactor
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function redactArray(array $data): array
    {
        $redacted = [];

        foreach ($data as $key => $value) {
            $redacted[$key] = match (true) {
                is_array($value) => $this->redactArray($value),
                $this->isSensitiveKey((string) $key) => '[redacted]',
                is_string($value) && filter_var($value, FILTER_VALIDATE_URL) => $this->redactUrl($value),
                default => $value,
            };
        }

        return $redacted;
    }

    public function redactUrl(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return $url;
        }

        $parts = parse_url($url);

        if (! is_array($parts)) {
            return $url;
        }

        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? 'unknown';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = $parts['path'] ?? '';
        $query = isset($parts['query']) ? '?[redacted]' : '';

        return $scheme.'://'.$host.$port.$path.$query;
    }

    public function targetLabel(string $channel, array $target): string
    {
        return match ($channel) {
            'email' => implode(', ', array_values(array_filter((array) ($target['emails'] ?? [])))) ?: 'configured mail target',
            'webhook' => $this->redactUrl((string) ($target['url'] ?? '')) ?: 'configured webhook',
            'log' => 'log:'.($target['channel'] ?? config('logging.default')),
            default => 'database:active-admins',
        };
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = mb_strtolower($key);

        foreach ((array) config('feed_mediator.observability.redact_keys', [
            'authorization',
            'token',
            'secret',
            'password',
            'api_key',
            'api_token',
            'webhook_url',
        ]) as $candidate) {
            if (str_contains($normalized, mb_strtolower((string) $candidate))) {
                return true;
            }
        }

        return false;
    }
}
