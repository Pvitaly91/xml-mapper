<?php

namespace App\Services\Source;

use App\Contracts\Source\ProductNormalizerInterface;
use App\Data\Source\ParsedSourceFeedData;
use App\Data\Source\ParsedSourceOfferData;
use App\Models\SourceAttribute;
use App\Models\SourceAttributeValue;
use App\Models\SourceCategory;
use App\Models\SourceConnection;
use App\Models\SourceImport;
use App\Models\SourceProduct;
use App\Models\SourceVariant;
use App\Models\SyncLog;
use App\Support\Canonicalizer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProductNormalizer implements ProductNormalizerInterface
{
    public function normalize(SourceConnection $connection, SourceImport $import, ParsedSourceFeedData $feedData): array
    {
        return DB::transaction(function () use ($connection, $import, $feedData): array {
            $categories = $this->syncCategories($connection, $feedData);
            $groupedOffers = collect($feedData->offers)->groupBy(fn (ParsedSourceOfferData $offer) => $this->makeGroupKey($offer));

            $seenProductIds = [];
            $seenVariantIds = [];

            foreach ($groupedOffers as $groupKey => $offers) {
                $offers = collect($offers);
                $firstOffer = $offers->first();

                if (! $firstOffer instanceof ParsedSourceOfferData) {
                    continue;
                }

                $product = $this->upsertProduct($connection, $import, (string) $groupKey, $offers, $categories);
                $seenProductIds[] = $product->id;

                foreach ($offers as $offer) {
                    $variant = $this->upsertVariant($connection, $import, $product, $offer);
                    $seenVariantIds[] = $variant->id;

                    $this->syncAttributeDictionary($connection, $offer);
                }
            }

            $this->deactivateMissingRecords($connection, $import, $seenProductIds, $seenVariantIds);

            $import->update([
                'status' => SourceImport::STATUS_NORMALIZED,
                'finished_at' => now(),
                'categories_total' => count($feedData->categories),
                'offers_total' => count($feedData->offers),
            ]);

            $this->log($connection, $import, 'info', 'source.normalized', 'Source data normalized into catalog tables.', [
                'categories' => count($feedData->categories),
                'products' => count($seenProductIds),
                'variants' => count($seenVariantIds),
            ]);

            return [
                'categories' => count($feedData->categories),
                'products' => count($seenProductIds),
                'variants' => count($seenVariantIds),
            ];
        });
    }

    /**
     * @return Collection<string, SourceCategory>
     */
    private function syncCategories(SourceConnection $connection, ParsedSourceFeedData $feedData): Collection
    {
        foreach ($feedData->categories as $categoryData) {
            SourceCategory::updateOrCreate(
                [
                    'shop_id' => $connection->shop_id,
                    'source_connection_id' => $connection->id,
                    'external_id' => $categoryData->externalId,
                ],
                [
                    'parent_external_id' => $categoryData->parentExternalId,
                    'name' => $categoryData->name,
                    'rz_id' => $categoryData->rzId,
                    'is_active' => true,
                    'raw_payload' => $categoryData->rawPayload,
                ]
            );
        }

        $categories = SourceCategory::query()
            ->where('shop_id', $connection->shop_id)
            ->where('source_connection_id', $connection->id)
            ->get()
            ->keyBy('external_id');

        foreach ($categories as $category) {
            $parent = $category->parent_external_id ? $categories->get($category->parent_external_id) : null;

            $category->forceFill([
                'parent_id' => $parent?->id,
                'full_path' => $this->buildCategoryPath($category, $categories),
            ])->save();
        }

        return $categories;
    }

    private function buildCategoryPath(SourceCategory $category, Collection $categories): string
    {
        $segments = [$category->name];
        $parentExternalId = $category->parent_external_id;

        while ($parentExternalId !== null) {
            /** @var SourceCategory|null $parent */
            $parent = $categories->get($parentExternalId);

            if ($parent === null) {
                break;
            }

            array_unshift($segments, $parent->name);
            $parentExternalId = $parent->parent_external_id;
        }

        return implode(' > ', Canonicalizer::uniqueNonEmpty($segments));
    }

    /**
     * @param  Collection<int, ParsedSourceOfferData>  $offers
     * @param  Collection<string, SourceCategory>  $categories
     */
    private function upsertProduct(
        SourceConnection $connection,
        SourceImport $import,
        string $groupKey,
        Collection $offers,
        Collection $categories
    ): SourceProduct {
        /** @var ParsedSourceOfferData $firstOffer */
        $firstOffer = $offers->first();

        $vendor = Canonicalizer::normalizeText($firstOffer->vendor);
        $article = Canonicalizer::normalizeText($firstOffer->article)
            ?? Canonicalizer::firstMatchingValue($firstOffer->params, config('feed_mediator.normalization.article_keys'));

        $images = Canonicalizer::uniqueNonEmpty($offers->flatMap(fn (ParsedSourceOfferData $offer) => $offer->images)->all());
        $category = $firstOffer->categoryExternalId ? $categories->get($firstOffer->categoryExternalId) : null;

        return SourceProduct::updateOrCreate(
            [
                'shop_id' => $connection->shop_id,
                'source_connection_id' => $connection->id,
                'group_key' => $groupKey,
            ],
            [
                'source_import_id' => $import->id,
                'source_category_id' => $category?->id,
                'external_group_id' => $firstOffer->externalGroupId ?? $article,
                'name' => $firstOffer->title,
                'vendor' => $vendor,
                'article' => $article,
                'brand' => $vendor,
                'description' => $firstOffer->description,
                'primary_image_url' => $images[0] ?? null,
                'images_json' => $images,
                'attributes_snapshot' => $firstOffer->params,
                'raw_payload' => $firstOffer->rawPayload,
                'is_active' => true,
            ]
        );
    }

    private function upsertVariant(SourceConnection $connection, SourceImport $import, SourceProduct $product, ParsedSourceOfferData $offer): SourceVariant
    {
        $color = Canonicalizer::firstMatchingValue($offer->params, config('feed_mediator.normalization.color_keys'));
        $size = Canonicalizer::firstMatchingValue($offer->params, config('feed_mediator.normalization.size_keys'));

        $offerIdentityKey = $offer->externalOfferId
            ?? Canonicalizer::fingerprint([
                'group' => $product->group_key,
                'title' => $offer->title,
                'color' => $color,
                'size' => $size,
            ]);

        $exportKeyHash = Canonicalizer::fingerprint([
            'vendor' => $product->vendor,
            'article' => $product->article,
            'color' => $color,
            'size' => $size,
        ]);

        $lookup = [
            'shop_id' => $connection->shop_id,
            'source_connection_id' => $connection->id,
        ];

        if ($offer->externalOfferId !== null) {
            $lookup['external_offer_id'] = $offer->externalOfferId;
        } else {
            $lookup['source_product_id'] = $product->id;
            $lookup['offer_identity_key'] = $offerIdentityKey;
        }

        $existing = SourceVariant::query()->where($lookup)->first();
        $preserveAxisValues = $connection->driver === SourceConnection::DRIVER_PROM_API;

        return SourceVariant::updateOrCreate(
            $lookup,
            [
                'source_import_id' => $import->id,
                'source_product_id' => $product->id,
                'external_sku' => $offer->externalSku ?? $offer->externalOfferId,
                'stable_offer_id' => $existing?->stable_offer_id
                    ?? 'ofr_'.substr(sha1($connection->shop_id.'|'.$connection->id.'|'.$offerIdentityKey), 0, 24),
                'offer_identity_key' => $offerIdentityKey,
                'export_key_hash' => $exportKeyHash,
                'title' => $offer->title,
                'price' => $offer->price,
                'old_price' => $offer->oldPrice,
                'currency' => $offer->currency,
                'quantity' => $offer->quantity,
                'is_available' => $offer->available,
                'color' => $color ?? ($preserveAxisValues ? $existing?->color : null),
                'size' => $size ?? ($preserveAxisValues ? $existing?->size : null),
                'images_json' => $offer->images,
                'attributes_snapshot' => $offer->params,
                'raw_payload' => $offer->rawPayload,
                'last_seen_at' => now(),
                'is_enabled' => true,
            ]
        );
    }

    private function syncAttributeDictionary(SourceConnection $connection, ParsedSourceOfferData $offer): void
    {
        $axisKeys = collect([
            ...config('feed_mediator.normalization.color_keys'),
            ...config('feed_mediator.normalization.size_keys'),
        ])->map(fn (string $key) => Canonicalizer::normalizeKey($key))->unique()->all();

        foreach ($offer->params as $attributeName => $rawValue) {
            $code = Canonicalizer::normalizeKey($attributeName);

            $attribute = SourceAttribute::firstOrCreate(
                [
                    'shop_id' => $connection->shop_id,
                    'source_connection_id' => $connection->id,
                    'code' => $code,
                ],
                [
                    'name' => $attributeName,
                    'data_type' => 'string',
                    'usage_scope' => 'variant',
                    'is_variant_axis' => in_array($code, $axisKeys, true),
                ]
            );

            SourceAttributeValue::updateOrCreate(
                [
                    'source_attribute_id' => $attribute->id,
                    'value_hash' => Canonicalizer::fingerprint((string) $rawValue),
                ],
                [
                    'shop_id' => $connection->shop_id,
                    'source_connection_id' => $connection->id,
                    'raw_value' => (string) $rawValue,
                    'normalized_value' => Canonicalizer::normalizeText((string) $rawValue),
                ]
            );
        }
    }

    /**
     * @param  list<int>  $seenProductIds
     * @param  list<int>  $seenVariantIds
     */
    private function deactivateMissingRecords(SourceConnection $connection, SourceImport $import, array $seenProductIds, array $seenVariantIds): void
    {
        $variantQuery = SourceVariant::query()
            ->where('shop_id', $connection->shop_id)
            ->where('source_connection_id', $connection->id);

        if ($seenVariantIds !== []) {
            $variantQuery->whereNotIn('id', $seenVariantIds);
        }

        $variantQuery->update([
            'is_enabled' => false,
            'is_available' => false,
            'source_import_id' => $import->id,
        ]);

        $productQuery = SourceProduct::query()
            ->where('shop_id', $connection->shop_id)
            ->where('source_connection_id', $connection->id);

        if ($seenProductIds !== []) {
            $productQuery->whereNotIn('id', $seenProductIds);
        }

        $productQuery->update([
            'is_active' => false,
            'source_import_id' => $import->id,
        ]);
    }

    private function makeGroupKey(ParsedSourceOfferData $offer): string
    {
        if ($offer->externalGroupId !== null) {
            return Canonicalizer::fingerprint([
                'external_group_id' => $offer->externalGroupId,
            ]);
        }

        $vendor = Canonicalizer::normalizeText($offer->vendor);
        $article = Canonicalizer::normalizeText($offer->article)
            ?? Canonicalizer::firstMatchingValue($offer->params, config('feed_mediator.normalization.article_keys'));

        if ($vendor !== null && $article !== null) {
            return Canonicalizer::fingerprint([
                'vendor' => mb_strtolower($vendor),
                'article' => mb_strtolower($article),
            ]);
        }

        return Canonicalizer::fingerprint([
            'fallback' => $offer->externalOfferId ?? $offer->title,
        ]);
    }

    private function log(SourceConnection $connection, SourceImport $import, string $level, string $event, string $message, array $context = []): void
    {
        SyncLog::create([
            'shop_id' => $connection->shop_id,
            'source_connection_id' => $connection->id,
            'source_import_id' => $import->id,
            'level' => $level,
            'event' => $event,
            'message' => $message,
            'context' => $context,
            'occurred_at' => now(),
        ]);
    }
}
