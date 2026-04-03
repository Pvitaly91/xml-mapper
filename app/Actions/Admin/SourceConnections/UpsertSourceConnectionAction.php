<?php

namespace App\Actions\Admin\SourceConnections;

use App\Models\SourceConnection;
use App\Models\User;

class UpsertSourceConnectionAction
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(User $user, array $payload, ?SourceConnection $connection = null): SourceConnection
    {
        $user = $user->fresh() ?? $user;
        $connection ??= new SourceConnection;
        $existingPromotionMeta = $connection->promotionMeta();
        $credentials = $this->resolvedCredentials($payload, $connection);
        $options = $this->resolvedOptions($payload, $connection);

        $attributes = [
            'shop_id' => $user->shop_id,
            'name' => $payload['name'],
            'code' => $payload['code'],
            'driver' => $payload['driver'],
            'status' => $payload['status'],
            'source_url' => $payload['driver'] === SourceConnection::DRIVER_PROM_YML ? ($payload['source_url'] ?? null) : null,
            'credentials' => $credentials,
            'api_base_url' => $payload['driver'] === SourceConnection::DRIVER_PROM_API
                ? ($payload['api_base_url'] ?? SourceConnection::defaultPromApiBaseUrl())
                : null,
            'api_version' => $payload['driver'] === SourceConnection::DRIVER_PROM_API
                ? ($payload['api_version'] ?? SourceConnection::defaultPromApiVersion())
                : null,
            'options' => $options,
            'sync_interval_minutes' => (int) $payload['sync_interval_minutes'],
        ];

        $connection->fill($attributes);
        $secretTouched = false;

        if (($payload['driver'] ?? null) === SourceConnection::DRIVER_PROM_API) {
            if (filled($payload['api_token'] ?? null)) {
                $connection->api_token = $payload['api_token'];
                $secretTouched = true;
            }
        } else {
            $connection->api_token = null;
            $connection->api_base_url = null;
            $connection->api_version = null;
            $secretTouched = filled($payload['credentials_json'] ?? null);
        }

        if ($connection->status === SourceConnection::STATUS_ACTIVE && $connection->next_sync_at === null) {
            $connection->next_sync_at = now()->addMinutes($connection->sync_interval_minutes);
        }

        if ($existingPromotionMeta !== []) {
            $connection->withPromotionMeta($existingPromotionMeta);
        }

        if ($secretTouched && $existingPromotionMeta !== []) {
            $connection->withPromotionMeta([
                'secret_state' => 'not_validated',
                'secret_rebind_required' => true,
                'last_reentered_at' => now()->toIso8601String(),
            ]);
        }

        $connection->save();

        return $connection->refresh();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJson(mixed $value): ?array
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function resolvedCredentials(array $payload, SourceConnection $connection): ?array
    {
        if (($payload['driver'] ?? null) === SourceConnection::DRIVER_PROM_API) {
            return null;
        }

        if (! array_key_exists('credentials_json', $payload) || trim((string) ($payload['credentials_json'] ?? '')) === '') {
            return $connection->exists ? $connection->credentials : null;
        }

        return $this->decodeJson($payload['credentials_json']);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function resolvedOptions(array $payload, SourceConnection $connection): ?array
    {
        $options = $this->decodeJson($payload['options_json'] ?? null);
        $promotionMeta = $connection->promotionMeta();

        if ($promotionMeta === []) {
            return $options;
        }

        return array_merge((array) $options, ['promotion_meta' => $promotionMeta]);
    }
}
