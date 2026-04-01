<?php

namespace App\Services\Validation;

use App\Contracts\Mappings\AttributeMappingServiceInterface;
use App\Contracts\Mappings\CategoryMappingServiceInterface;
use App\Contracts\Validation\ValidationServiceInterface;
use App\Models\FeedItem;
use App\Models\FeedProfile;
use App\Models\SourceProduct;
use App\Models\SourceVariant;
use App\Models\ValidationError;
use App\Support\Canonicalizer;

class ValidationService implements ValidationServiceInterface
{
    public function __construct(
        private readonly CategoryMappingServiceInterface $categoryMappingService,
        private readonly AttributeMappingServiceInterface $attributeMappingService,
    ) {
    }

    public function validate(
        FeedProfile $feedProfile,
        SourceProduct $product,
        SourceVariant $variant,
        ?FeedItem $feedItem = null
    ): array {
        $errors = [];

        if ($variant->price === null || (float) $variant->price <= 0) {
            $errors[] = $this->error(ValidationError::CODE_MISSING_PRICE, 'Variant has no valid price.');
        }

        $images = Canonicalizer::uniqueNonEmpty([
            ...($variant->images_json ?? []),
            ...($product->images_json ?? []),
            $product->primary_image_url,
        ]);

        if ($images === []) {
            $errors[] = $this->error(ValidationError::CODE_MISSING_PHOTO, 'Variant has no images.');
        }

        if (blank($product->vendor) && blank($product->brand)) {
            $errors[] = $this->error(ValidationError::CODE_MISSING_VENDOR, 'Vendor/brand is missing.');
        }

        if (blank($product->article)) {
            $errors[] = $this->error(ValidationError::CODE_MISSING_ARTICLE, 'Article is required for variant grouping.');
        }

        $categoryMapping = $this->categoryMappingService->resolve($feedProfile, $product->sourceCategory);

        if ($categoryMapping === null || $categoryMapping->kastaCategory === null) {
            $errors[] = $this->error(ValidationError::CODE_MISSING_CATEGORY_MAPPING, 'Category mapping is missing.');
        } else {
            $missingRequiredMappings = $this->attributeMappingService->missingRequiredMappings(
                $feedProfile,
                $product,
                $categoryMapping->kastaCategory
            );

            if ($missingRequiredMappings !== []) {
                $errors[] = $this->error(
                    ValidationError::CODE_MISSING_REQUIRED_ATTRIBUTE_MAPPING,
                    'Required Kasta attribute mappings are missing.',
                    ['missing_attributes' => $missingRequiredMappings]
                );
            }
        }

        $duplicateStableOfferId = SourceVariant::query()
            ->where('shop_id', $variant->shop_id)
            ->where('stable_offer_id', $variant->stable_offer_id)
            ->whereKeyNot($variant->id)
            ->exists();

        if ($duplicateStableOfferId || ($variant->published_export_key_hash !== null && $variant->published_export_key_hash !== $variant->export_key_hash)) {
            $errors[] = $this->error(
                ValidationError::CODE_DUPLICATED_OR_UNSTABLE_OFFER_ID,
                'Offer ID is duplicated or export keys changed after publication.'
            );
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
