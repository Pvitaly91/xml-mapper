<?php

namespace App\Actions\Admin\Mappings;

use App\Models\AttributeMapping;
use App\Models\FeedProfile;
use App\Models\KastaAttribute;

class UpsertAttributeMappingAction
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(FeedProfile $feedProfile, array $payload, ?AttributeMapping $mapping = null): AttributeMapping
    {
        $kastaAttribute = KastaAttribute::query()->findOrFail($payload['kasta_attribute_id']);

        $mapping ??= new AttributeMapping;
        $mapping->fill([
            'shop_id' => $feedProfile->shop_id,
            'source_connection_id' => $feedProfile->source_connection_id,
            'feed_profile_id' => $feedProfile->id,
            'source_category_id' => $payload['source_category_id'] ?: null,
            'source_attribute_id' => $payload['source_attribute_id'],
            'kasta_category_id' => $payload['kasta_category_id'] ?: $kastaAttribute->kasta_category_id,
            'kasta_attribute_id' => $kastaAttribute->id,
            'mapping_strategy' => AttributeMapping::STRATEGY_MANUAL,
            'is_required' => (bool) ($payload['is_required'] ?? $kastaAttribute->is_required),
            'default_value' => $payload['default_value'] ?: null,
            'use_variant_value' => (bool) ($payload['use_variant_value'] ?? false),
        ]);
        $mapping->save();

        return $mapping->refresh()->load(['sourceAttribute', 'kastaAttribute']);
    }
}
