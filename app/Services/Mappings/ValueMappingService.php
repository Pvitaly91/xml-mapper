<?php

namespace App\Services\Mappings;

use App\Contracts\Mappings\ValueMappingServiceInterface;
use App\Models\AttributeMapping;
use App\Support\Canonicalizer;

class ValueMappingService implements ValueMappingServiceInterface
{
    public function mapValue(AttributeMapping $attributeMapping, mixed $sourceValue): ?string
    {
        $normalizedSourceValue = Canonicalizer::normalizeText(is_scalar($sourceValue) ? (string) $sourceValue : null);

        if ($normalizedSourceValue === null) {
            return $attributeMapping->default_value;
        }

        $valueMapping = $attributeMapping->valueMappings()
            ->with('kastaAttributeValue')
            ->where('is_active', true)
            ->where(function ($query) use ($normalizedSourceValue): void {
                $query->where('source_raw_value', $normalizedSourceValue)
                    ->orWhere('normalized_source_value', Canonicalizer::normalizeText(mb_strtolower($normalizedSourceValue)));
            })
            ->first();

        if ($valueMapping !== null) {
            return $valueMapping->kastaAttributeValue?->value
                ?? $valueMapping->target_value
                ?? $attributeMapping->default_value;
        }

        if ($attributeMapping->kastaAttribute && ! $attributeMapping->kastaAttribute->allows_custom_value) {
            return $attributeMapping->default_value;
        }

        return $normalizedSourceValue;
    }
}
