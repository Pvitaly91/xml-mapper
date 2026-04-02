<?php

namespace App\Actions\Admin\Mappings;

use App\Models\AttributeMapping;
use App\Models\KastaAttributeValue;
use App\Models\SourceAttributeValue;
use App\Models\ValueMapping;
use App\Support\Canonicalizer;

class PreviewValueMappingSuggestionsAction
{
    /**
     * @return array<int, array{source_attribute_value:SourceAttributeValue,kasta_attribute_value:KastaAttributeValue,normalized_source_value:string}>
     */
    public function handle(AttributeMapping $attributeMapping): array
    {
        $attributeMapping->loadMissing(['sourceAttribute.values', 'kastaAttribute.values']);

        if ($attributeMapping->sourceAttribute === null || $attributeMapping->kastaAttribute === null) {
            return [];
        }

        $targetIndex = [];

        foreach ($attributeMapping->kastaAttribute->values as $targetValue) {
            $key = Canonicalizer::normalizeText(mb_strtolower($targetValue->normalized_value ?: $targetValue->value));

            if ($key !== null) {
                $targetIndex[$key] ??= $targetValue;
            }
        }

        $existing = ValueMapping::query()
            ->where('attribute_mapping_id', $attributeMapping->id)
            ->where('is_active', true)
            ->get();

        $existingValueIds = $existing->pluck('source_attribute_value_id')->filter()->all();
        $existingNormalized = $existing->map(
            fn (ValueMapping $valueMapping) => Canonicalizer::normalizeText(mb_strtolower($valueMapping->normalized_source_value ?: $valueMapping->source_raw_value))
        )->filter()->values()->all();
        $suggestions = [];

        foreach ($attributeMapping->sourceAttribute->values as $sourceValue) {
            $normalized = Canonicalizer::normalizeText(mb_strtolower($sourceValue->normalized_value ?: $sourceValue->raw_value));

            if ($normalized === null || in_array($sourceValue->id, $existingValueIds, true) || in_array($normalized, $existingNormalized, true)) {
                continue;
            }

            $targetValue = $targetIndex[$normalized] ?? null;

            if ($targetValue === null) {
                continue;
            }

            $suggestions[] = [
                'source_attribute_value' => $sourceValue,
                'kasta_attribute_value' => $targetValue,
                'normalized_source_value' => $normalized,
            ];
        }

        return $suggestions;
    }
}
