<?php

namespace App\Actions\Admin\Mappings;

use App\Models\AttributeMapping;
use App\Models\KastaAttributeValue;
use App\Models\ValueMapping;
use App\Support\Canonicalizer;

class UpsertValueMappingAction
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(AttributeMapping $attributeMapping, array $payload, ?ValueMapping $mapping = null): ValueMapping
    {
        $kastaAttributeValue = ! empty($payload['kasta_attribute_value_id'])
            ? KastaAttributeValue::query()->findOrFail($payload['kasta_attribute_value_id'])
            : null;

        $mapping ??= new ValueMapping;
        $mapping->fill([
            'shop_id' => $attributeMapping->shop_id,
            'feed_profile_id' => $attributeMapping->feed_profile_id,
            'attribute_mapping_id' => $attributeMapping->id,
            'source_attribute_value_id' => $payload['source_attribute_value_id'] ?: null,
            'kasta_attribute_value_id' => $kastaAttributeValue?->id,
            'source_raw_value' => $payload['source_raw_value'],
            'normalized_source_value' => Canonicalizer::normalizeText(mb_strtolower($payload['source_raw_value'])),
            'target_value' => $kastaAttributeValue?->value ?: ($payload['target_value'] ?: null),
            'mapping_strategy' => ValueMapping::STRATEGY_MANUAL,
            'is_active' => (bool) ($payload['is_active'] ?? true),
        ]);
        $mapping->save();

        return $mapping->refresh()->load(['sourceAttributeValue', 'kastaAttributeValue']);
    }
}
