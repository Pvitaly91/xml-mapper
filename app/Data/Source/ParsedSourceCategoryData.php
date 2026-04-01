<?php

namespace App\Data\Source;

readonly class ParsedSourceCategoryData
{
    public function __construct(
        public string $externalId,
        public ?string $parentExternalId,
        public string $name,
        public ?string $rzId = null,
        public array $rawPayload = [],
    ) {
    }
}
