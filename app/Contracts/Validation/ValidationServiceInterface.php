<?php

namespace App\Contracts\Validation;

use App\Models\FeedItem;
use App\Models\FeedProfile;
use App\Models\SourceProduct;
use App\Models\SourceVariant;

interface ValidationServiceInterface
{
    /**
     * @return list<array{code:string,message:string,payload:array<string,mixed>}>
     */
    public function validate(
        FeedProfile $feedProfile,
        SourceProduct $product,
        SourceVariant $variant,
        ?FeedItem $feedItem = null
    ): array;

    /**
     * @param  list<array{code:string,message:string,payload:array<string,mixed>}>  $errors
     */
    public function syncValidationState(
        FeedProfile $feedProfile,
        FeedItem $feedItem,
        SourceProduct $product,
        SourceVariant $variant,
        array $errors
    ): void;
}
