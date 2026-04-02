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
        $attributes = [
            'shop_id' => $user->shop_id,
            'name' => $payload['name'],
            'code' => $payload['code'],
            'driver' => $payload['driver'],
            'status' => $payload['status'],
            'source_url' => $payload['source_url'],
            'credentials' => $this->decodeJson($payload['credentials_json'] ?? null),
            'options' => $this->decodeJson($payload['options_json'] ?? null),
            'sync_interval_minutes' => (int) $payload['sync_interval_minutes'],
        ];

        $connection ??= new SourceConnection();
        $connection->fill($attributes);

        if ($connection->status === SourceConnection::STATUS_ACTIVE && $connection->next_sync_at === null) {
            $connection->next_sync_at = now()->addMinutes($connection->sync_interval_minutes);
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
}
