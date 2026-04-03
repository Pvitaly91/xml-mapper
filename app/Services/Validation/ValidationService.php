<?php

namespace App\Services\Validation;

use App\Contracts\Validation\ValidationServiceInterface;
use App\Models\FeedItem;
use App\Models\FeedProfile;
use App\Models\SourceProduct;
use App\Models\SourceVariant;
use App\Models\ValidationError;
use App\Services\Feeds\FeedProfileOverrideService;
use App\Support\Canonicalizer;

class ValidationService implements ValidationServiceInterface
{
    public function __construct(
        private readonly FeedProfileOverrideService $overrideService,
    ) {
    }

    public function validate(
        FeedProfile $feedProfile,
        SourceProduct $product,
        SourceVariant $variant,
        ?FeedItem $feedItem = null
    ): array {
        $errors = [];
        $minimumPriceThreshold = $this->overrideService->minimumPriceThreshold($feedProfile);
        $minimumPictures = $this->overrideService->effectiveMinimumPictures($feedProfile);

        if ($variant->price === null || (float) $variant->price <= 0) {
            $errors[] = $this->error(ValidationError::CODE_MISSING_PRICE, 'Variant has no valid price.');
        } elseif ($minimumPriceThreshold !== null && (float) $variant->price < $minimumPriceThreshold) {
            $errors[] = $this->error(
                ValidationError::CODE_PRICE_BELOW_THRESHOLD,
                sprintf('Variant price is below the merchant minimum threshold %.2f.', $minimumPriceThreshold),
                ['minimum_price_threshold' => $minimumPriceThreshold]
            );
        }

        $images = Canonicalizer::uniqueNonEmpty([
            ...($variant->images_json ?? []),
            ...($product->images_json ?? []),
            $product->primary_image_url,
        ]);

        if (count($images) < $minimumPictures) {
            $errors[] = $this->error(
                ValidationError::CODE_MISSING_PHOTO,
                sprintf('Variant has fewer than %d image(s).', $minimumPictures),
                [
                    'minimum_pictures' => $minimumPictures,
                    'current_pictures' => count($images),
                ]
            );
        }

        if (blank($product->vendor) && blank($product->brand)) {
            $errors[] = $this->error(ValidationError::CODE_MISSING_VENDOR, 'Vendor/brand is missing.');
        }

        if (blank($product->article)) {
            $errors[] = $this->error(ValidationError::CODE_MISSING_ARTICLE, 'Article is required for variant grouping.');
        }

        return $errors;
    }

    public function syncValidationState(
        FeedProfile $feedProfile,
        FeedItem $feedItem,
        SourceProduct $product,
        SourceVariant $variant,
        array $errors
    ): void {
        ValidationError::query()
            ->where('feed_profile_id', $feedProfile->id)
            ->where('feed_item_id', $feedItem->id)
            ->where('is_active', true)
            ->update([
                'is_active' => false,
                'resolved_at' => now(),
            ]);

        foreach ($errors as $error) {
            ValidationError::create([
                'shop_id' => $feedProfile->shop_id,
                'feed_profile_id' => $feedProfile->id,
                'source_product_id' => $product->id,
                'source_variant_id' => $variant->id,
                'feed_item_id' => $feedItem->id,
                'code' => $error['code'],
                'severity' => 'error',
                'message' => $error['message'],
                'payload' => $error['payload'],
                'is_active' => true,
                'detected_at' => now(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{code:string,message:string,payload:array<string,mixed>}
     */
    private function error(string $code, string $message, array $payload = []): array
    {
        return [
            'code' => $code,
            'message' => $message,
            'payload' => $payload,
        ];
    }
}
