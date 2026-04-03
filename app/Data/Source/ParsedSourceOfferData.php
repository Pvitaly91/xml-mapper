<?php

namespace App\Data\Source;

readonly class ParsedSourceOfferData
{
    /**
     * @param  list<string>  $images
     * @param  array<string, string>  $params
     * @param  array<string, mixed>  $rawPayload
     */
    public function __construct(
        public ?string $externalOfferId,
        public ?string $externalGroupId,
        public ?string $externalSku,
        public string $title,
        public ?string $categoryExternalId,
        public ?string $vendor,
        public ?string $article,
        public ?string $description,
        public ?float $price,
        public ?float $oldPrice,
        public string $currency,
        public ?int $quantity,
        public bool $available,
        public array $images = [],
        public array $params = [],
        public array $rawPayload = [],
    ) {}
}
