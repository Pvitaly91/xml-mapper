<?php

namespace App\Actions\Admin\FeedProfiles;

use App\Models\FeedGenerationSignoff;
use App\Models\FeedProfile;
use App\Models\Shop;
use App\Models\User;

class UpsertFeedProfileAction
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(User $user, array $payload, ?FeedProfile $feedProfile = null, ?Shop $shop = null): FeedProfile
    {
        $user = $user->fresh() ?? $user;
        $shopId = $shop?->id ?: $user->shop_id;
        $settings = array_merge($this->decodeJson($payload['settings_json'] ?? null) ?? [], [
            'publish_guard_enabled' => (bool) ($payload['publish_guard_enabled'] ?? false),
            'minimum_ready_items' => (int) ($payload['minimum_ready_items'] ?? 0),
            'maximum_invalid_ratio' => array_key_exists('maximum_invalid_ratio', $payload) && $payload['maximum_invalid_ratio'] !== null && $payload['maximum_invalid_ratio'] !== ''
                ? (float) $payload['maximum_invalid_ratio']
                : 1,
            'block_publish_on_critical_conformance' => (bool) ($payload['block_publish_on_critical_conformance'] ?? false),
            'minimum_pictures' => max(1, (int) ($payload['minimum_pictures'] ?? 1)),
            'minimum_price_threshold' => array_key_exists('minimum_price_threshold', $payload) && $payload['minimum_price_threshold'] !== null && $payload['minimum_price_threshold'] !== ''
                ? (float) $payload['minimum_price_threshold']
                : null,
            'override_minimum_pictures' => array_key_exists('override_minimum_pictures', $payload) && $payload['override_minimum_pictures'] !== null && $payload['override_minimum_pictures'] !== ''
                ? max(1, (int) $payload['override_minimum_pictures'])
                : null,
            'signoff_required' => (bool) ($payload['signoff_required'] ?? false),
            'required_signoff_status' => $payload['required_signoff_status'] ?? FeedGenerationSignoff::STATUS_INTERNAL_APPROVED,
            'publish_window_enabled' => (bool) ($payload['publish_window_enabled'] ?? false),
            'publish_window_days' => array_values($payload['publish_window_days'] ?? ['mon', 'tue', 'wed', 'thu', 'fri']),
            'publish_window_start' => (string) ($payload['publish_window_start'] ?? '09:00'),
            'publish_window_end' => (string) ($payload['publish_window_end'] ?? '18:00'),
            'publish_window_timezone' => $payload['publish_window_timezone'] ?? ($user->shop?->timezone ?: config('app.timezone')),
            'freeze_mode' => (bool) ($payload['freeze_mode'] ?? false),
            'excluded_source_category_ids' => $this->parseLines($payload['excluded_source_category_ids_text'] ?? null, true),
            'excluded_vendors' => $this->parseLines($payload['excluded_vendors_text'] ?? null),
            'disabled_export_category_ids' => $this->parseLines($payload['disabled_export_category_ids_text'] ?? null),
            'forced_attribute_overrides' => $this->decodeJson($payload['forced_attribute_overrides_json'] ?? null) ?? [],
            'forced_value_overrides' => $this->decodeJson($payload['forced_value_overrides_json'] ?? null) ?? [],
        ]);

        $attributes = [
            'shop_id' => $shopId,
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
            'settings' => $settings,
        ];

        $feedProfile ??= new FeedProfile;
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

    /**
     * @return list<int|string>
     */
    private function parseLines(mixed $value, bool $castToInt = false): array
    {
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        return collect(preg_split('/\r\n|\n|\r|,/', $value) ?: [])
            ->map(fn ($item) => trim((string) $item))
            ->filter(fn ($item) => $item !== '')
            ->map(fn ($item) => $castToInt ? (int) $item : $item)
            ->values()
            ->all();
    }
}
