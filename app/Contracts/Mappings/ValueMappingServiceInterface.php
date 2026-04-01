<?php

namespace App\Contracts\Mappings;

use App\Models\AttributeMapping;

interface ValueMappingServiceInterface
{
    public function mapValue(AttributeMapping $attributeMapping, mixed $sourceValue): ?string;
}
