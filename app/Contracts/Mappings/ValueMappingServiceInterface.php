<?php

namespace App\Contracts\Mappings;

use App\Models\AttributeMapping;

interface ValueMappingServiceInterface
{
    /**
     * @return array{
     *     source_value:?string,
     *     mapped_value:?string,
     *     resolution:string,
     *     used_value_mapping:bool,
     *     used_default:bool,
     *     used_custom_value:bool
     * }
     */
    public function resolveValue(AttributeMapping $attributeMapping, mixed $sourceValue): array;

    public function mapValue(AttributeMapping $attributeMapping, mixed $sourceValue): ?string;
}
