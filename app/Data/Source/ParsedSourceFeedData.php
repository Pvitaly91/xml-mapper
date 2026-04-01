<?php

namespace App\Data\Source;

readonly class ParsedSourceFeedData
{
    /**
     * @param  list<ParsedSourceCategoryData>  $categories
     * @param  list<ParsedSourceOfferData>  $offers
     */
    public function __construct(
        public array $categories,
        public array $offers,
        public ?string $shopName = null,
    ) {
    }
}
