<?php

namespace App\Actions\Admin\FeedProfiles;

use App\Models\FeedProfile;
use App\Models\User;

class UpsertFeedProfileAction
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(User $user, array $payload, ?FeedProfile $feedProfile = null): FeedProfile
    {
        $attributes = [
            'shop_id' => $user->shop_id,
            'user_id' => $user->id,
            'source_connection_id' => $payload['source_connection_id'],
            'name' => $payload['name'],
            'code' => $payload['code'],
            'status' => $payload['status'],
            'currency' => $payload['currency'],
            'language' => $payload['language'],
            'include_unavailable' => (bool) ($payload['include_unavailable'] ?? false),
            'auto_sync' => (bool) ($payload['auto_sync'] ?? false),
            'auto_build' => (bool) ($payload['auto_build'] ?? false),
            'build_interval_minutes' => (int) $payload['build_interval_minutes'],
            'settings' => $this->decodeJson($payload['settings_json'] ?? null),
        ];

        $feedProfile ??= new FeedProfile();
        $feedProfile->fill($attributes);

        if ($feedProfile->status === FeedProfile::STATUS_ACTIVE && $feedProfile->next_build_at === null) {
            $feedProfile->next_build_at = now()->addMinutes($feedProfile->build_interval_minutes);
        }

        $feedProfile->save();

        return $feedProfile->refresh();
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
