<?php

namespace App\Services\Mappings;

use App\Contracts\Mappings\ValueMappingServiceInterface;
use App\Models\AttributeMapping;
use App\Support\Canonicalizer;

class ValueMappingService implements ValueMappingServiceInterface
{
    public function resolveValue(AttributeMapping $attributeMapping, mixed $sourceValue): array
    {
        $normalizedSourceValue = Canonicalizer::normalizeText(is_scalar($sourceValue) ? (string) $sourceValue : null);

        if ($normalizedSourceValue === null) {
            return [
                'source_value' => null,
                'mapped_value' => $attributeMapping->default_value,
                'resolution' => $attributeMapping->default_value !== null ? 'default' : 'missing_source_value',
                'used_value_mapping' => false,
                'used_default' => $attributeMapping->default_value !== null,
                'used_custom_value' => false,
            ];
        }

        $normalizedLookupValue = Canonicalizer::normalizeText(mb_strtolower($normalizedSourceValue));
        $valueMapping = $attributeMapping->relationLoaded('valueMappings')
            ? $attributeMapping->valueMappings->first(function ($candidate) use ($normalizedSourceValue, $normalizedLookupValue) {
                return $candidate->is_active
                    && (
                        $candidate->source_raw_value === $normalizedSourceValue
                        || $candidate->normalized_source_value === $normalizedLookupValue
                    );
            })
            : $attributeMapping->valueMappings()
                ->with('kastaAttributeValue')
                ->where('is_active', true)
                ->where(function ($query) use ($normalizedSourceValue, $normalizedLookupValue): void {
                    $query->where('source_raw_value', $normalizedSourceValue)
                        ->orWhere('normalized_source_value', $normalizedLookupValue);
                })
                ->first();

        if ($valueMapping !== null) {
            return [
                'source_value' => $normalizedSourceValue,
                'mapped_value' => $valueMapping->kastaAttributeValue?->value
                    ?? $valueMapping->target_value
                    ?? $attributeMapping->default_value,
                'resolution' => 'mapped',
                'used_value_mapping' => true,
                'used_default' => false,
                'used_custom_value' => false,
            ];
        }

        if ($attributeMapping->kastaAttribute && ! $attributeMapping->kastaAttribute->allows_custom_value) {
            return [
                'source_value' => $normalizedSourceValue,
                'mapped_value' => $attributeMapping->default_value,
                'resolution' => $attributeMapping->default_value !== null ? 'default' : 'missing_value_mapping',
                'used_value_mapping' => false,
                'used_default' => $attributeMapping->default_value !== null,
                'used_custom_value' => false,
            ];
        }

        return [
            'source_value' => $normalizedSourceValue,
            'mapped_value' => $normalizedSourceValue,
            'resolution' => 'custom',
            'used_value_mapping' => false,
            'used_default' => false,
            'used_custom_value' => true,
        ];
    }

    public function mapValue(AttributeMapping $attributeMapping, mixed $sourceValue): ?string
    {
        return $this->resolveValue($attributeMapping, $sourceValue)['mapped_value'];
    }
}
