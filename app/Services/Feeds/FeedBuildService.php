<?php

namespace App\Services\Feeds;

use App\Contracts\Feeds\FeedBuildServiceInterface;
use App\Contracts\Mappings\AttributeMappingServiceInterface;
use App\Contracts\Mappings\CategoryMappingServiceInterface;
use App\Contracts\Validation\ValidationServiceInterface;
use App\Models\FeedGeneration;
use App\Models\FeedItem;
use App\Models\FeedProfile;
use App\Models\SourceProduct;
use App\Models\SourceVariant;
use App\Models\SyncLog;
use App\Support\Canonicalizer;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use XMLWriter;

class FeedBuildService implements FeedBuildServiceInterface
{
    public function __construct(
        private readonly CategoryMappingServiceInterface $categoryMappingService,
        private readonly AttributeMappingServiceInterface $attributeMappingService,
        private readonly ValidationServiceInterface $validationService,
    ) {
    }

    public function build(FeedProfile $feedProfile): FeedGeneration
    {
        $feedProfile->loadMissing(['shop', 'sourceConnection']);

        $generation = FeedGeneration::create([
            'shop_id' => $feedProfile->shop_id,
            'feed_profile_id' => $feedProfile->id,
            'status' => FeedGeneration::STATUS_BUILDING,
        ]);

        $validFeedItemIds = [];
        $usedCategories = [];
        $itemsTotal = 0;
        $validItems = 0;
        $invalidItems = 0;

        SourceVariant::query()
            ->with(['product.sourceCategory'])
            ->where('shop_id', $feedProfile->shop_id)
            ->where('source_connection_id', $feedProfile->source_connection_id)
            ->where('is_enabled', true)
            ->orderBy('id')
            ->chunk(250, function ($variants) use (
                $feedProfile,
                $generation,
                &$validFeedItemIds,
                &$usedCategories,
                &$itemsTotal,
                &$validItems,
                &$invalidItems
            ): void {
                foreach ($variants as $variant) {
                    $product = $variant->product;

                    if ($product === null || ! $product->is_active) {
                        continue;
                    }

                    if (! $feedProfile->include_unavailable && ! $variant->is_available) {
                        $this->upsertExcludedFeedItem($feedProfile, $generation, $product->id, $variant->id, 'Variant is unavailable.');

                        continue;
                    }

                    $itemsTotal++;

                    $feedItem = FeedItem::firstOrCreate(
                        [
                            'feed_profile_id' => $feedProfile->id,
                            'source_variant_id' => $variant->id,
                        ],
                        [
                            'shop_id' => $feedProfile->shop_id,
                            'source_product_id' => $product->id,
                            'status' => FeedItem::STATUS_PENDING,
                            'is_enabled' => true,
                            'is_manual_override' => false,
                        ]
                    );

                    $feedItem->fill([
                        'shop_id' => $feedProfile->shop_id,
                        'source_product_id' => $product->id,
                        'last_built_generation_id' => $generation->id,
                    ]);

                    $errors = $this->validationService->validate($feedProfile, $product, $variant, $feedItem);
                    $this->validationService->syncValidationState($feedProfile, $feedItem, $product, $variant, $errors);
                    $feedItem->last_validation_hash = Canonicalizer::fingerprint($errors);

                    if (! $feedItem->is_enabled) {
                        $feedItem->status = FeedItem::STATUS_EXCLUDED;
                        $feedItem->excluded_reason = $feedItem->excluded_reason ?: 'Feed item is manually disabled.';
                        $feedItem->save();

                        continue;
                    }

                    if ($errors !== []) {
                        $feedItem->status = FeedItem::STATUS_INVALID;
                        $feedItem->excluded_reason = null;
                        $feedItem->save();
                        $invalidItems++;

                        continue;
                    }

                    $mappedCategory = $this->categoryMappingService->getMappedCategory($feedProfile, $product->sourceCategory);

                    if ($mappedCategory === null) {
                        $feedItem->status = FeedItem::STATUS_INVALID;
                        $feedItem->save();
                        $invalidItems++;

                        continue;
                    }

                    $feedItem->status = FeedItem::STATUS_READY;
                    $feedItem->excluded_reason = null;
                    $feedItem->save();

                    $validFeedItemIds[] = $feedItem->id;
                    $usedCategories[$mappedCategory->external_id] = $mappedCategory->name;
                    $validItems++;
                }
            });

        $path = $this->buildXmlFile($feedProfile, $generation, $validFeedItemIds, $usedCategories);

        $generation->update([
            'status' => FeedGeneration::STATUS_BUILT,
            'items_total' => $itemsTotal,
            'valid_items_total' => $validItems,
            'invalid_items_total' => $invalidItems,
            'file_path' => $path,
            'checksum' => hash_file('sha256', Storage::disk(config('feed_mediator.storage_disk'))->path($path)) ?: null,
            'built_at' => now(),
        ]);

        $feedProfile->update([
            'last_built_at' => now(),
            'next_build_at' => now()->addMinutes($feedProfile->build_interval_minutes),
        ]);

        $this->log($feedProfile, $generation, 'info', 'feed.built', 'Feed XML generated.', [
            'file_path' => $path,
            'valid_items' => $validItems,
            'invalid_items' => $invalidItems,
        ]);

        return $generation->refresh();
    }

    /**
     * @param  list<int>  $validFeedItemIds
     * @param  array<string, string>  $usedCategories
     */
    private function buildXmlFile(FeedProfile $feedProfile, FeedGeneration $generation, array $validFeedItemIds, array $usedCategories): string
    {
        $relativePath = trim(config('feed_mediator.builds_directory'), '/').'/shop-'.$feedProfile->shop_id.'/feed-'.$feedProfile->id.'/generation-'.$generation->id.'.xml';
        $absolutePath = Storage::disk(config('feed_mediator.storage_disk'))->path($relativePath);

        if (! is_dir(dirname($absolutePath))) {
            mkdir(dirname($absolutePath), 0777, true);
        }

        $writer = new XMLWriter();

        if (! $writer->openUri($absolutePath)) {
            throw new RuntimeException(sprintf('Failed to open XMLWriter for [%s].', $absolutePath));
        }

        $writer->startDocument('1.0', 'UTF-8');
        $writer->setIndent(true);
        $writer->startElement('yml_catalog');
        $writer->writeAttribute('date', now()->format('Y-m-d H:i'));
        $writer->startElement('shop');
        $writer->writeElement('name', $feedProfile->name);
        $writer->writeElement('company', $feedProfile->shop->name);
        $writer->writeElement('url', rtrim(config('app.url'), '/'));
        $writer->startElement('currencies');
        $writer->startElement('currency');
        $writer->writeAttribute('id', $feedProfile->currency ?: 'UAH');
        $writer->writeAttribute('rate', '1');
        $writer->endElement();
        $writer->endElement();
        $writer->startElement('categories');

        foreach ($usedCategories as $categoryId => $categoryName) {
            $writer->startElement('category');
            $writer->writeAttribute('id', (string) $categoryId);
            $writer->text($categoryName);
            $writer->endElement();
        }

        $writer->endElement();
        $writer->startElement('offers');

        if ($validFeedItemIds !== []) {
            FeedItem::query()
                ->with(['sourceProduct.sourceCategory', 'sourceVariant'])
                ->whereIn('id', $validFeedItemIds)
                ->orderBy('id')
                ->chunk(250, function ($feedItems) use ($feedProfile, $writer): void {
                    foreach ($feedItems as $feedItem) {
                        $variant = $feedItem->sourceVariant;
                        $product = $feedItem->sourceProduct;

                        if ($variant === null || $product === null) {
                            continue;
                        }

                        $mappedCategory = $this->categoryMappingService->getMappedCategory($feedProfile, $product->sourceCategory);

                        if ($mappedCategory === null) {
                            continue;
                        }

                        $mappedAttributes = $this->attributeMappingService->resolveMappedAttributes($feedProfile, $product, $variant, $mappedCategory);
                        $this->writeOffer($writer, $variant, $product, $mappedCategory->external_id, $mappedAttributes);
                    }
                });
        }

        $writer->endElement();
        $writer->endElement();
        $writer->endElement();
        $writer->endDocument();
        $writer->flush();

        return $relativePath;
    }

    /**
     * @param  array<string, string>  $mappedAttributes
     */
    private function writeOffer(XMLWriter $writer, SourceVariant $variant, SourceProduct $product, string $categoryExternalId, array $mappedAttributes): void
    {
        $writer->startElement('offer');
        $writer->writeAttribute('id', $variant->stable_offer_id);
        $writer->writeAttribute('available', $variant->is_available ? 'true' : 'false');
        $writer->writeElement('name', $variant->title ?: $product->name);
        $writer->writeElement('price', number_format((float) $variant->price, 2, '.', ''));
        $writer->writeElement('currencyId', $variant->currency ?: 'UAH');
        $writer->writeElement('categoryId', $categoryExternalId);

        if (! blank($product->vendor)) {
            $writer->writeElement('vendor', $product->vendor);
        }

        if (! blank($product->article)) {
            $writer->writeElement('vendorCode', $product->article);
        }

        foreach (Canonicalizer::uniqueNonEmpty([
            ...($variant->images_json ?? []),
            ...($product->images_json ?? []),
            $product->primary_image_url,
        ]) as $image) {
            $writer->writeElement('picture', $image);
        }

        if (! blank($product->description)) {
            $writer->writeElement('description', $product->description);
        }

        foreach ($mappedAttributes as $attributeCode => $value) {
            $writer->startElement('param');
            $writer->writeAttribute('name', $attributeCode);
            $writer->text($value);
            $writer->endElement();
        }

        $writer->endElement();
    }

    private function upsertExcludedFeedItem(FeedProfile $feedProfile, FeedGeneration $generation, int $productId, int $variantId, string $reason): void
    {
        FeedItem::updateOrCreate(
            [
                'feed_profile_id' => $feedProfile->id,
                'source_variant_id' => $variantId,
            ],
            [
                'shop_id' => $feedProfile->shop_id,
                'source_product_id' => $productId,
                'last_built_generation_id' => $generation->id,
                'status' => FeedItem::STATUS_EXCLUDED,
                'excluded_reason' => $reason,
            ]
        );
    }

    private function log(FeedProfile $feedProfile, FeedGeneration $generation, string $level, string $event, string $message, array $context = []): void
    {
        SyncLog::create([
            'shop_id' => $feedProfile->shop_id,
            'source_connection_id' => $feedProfile->source_connection_id,
            'feed_profile_id' => $feedProfile->id,
            'feed_generation_id' => $generation->id,
            'level' => $level,
            'event' => $event,
            'message' => $message,
            'context' => $context,
            'occurred_at' => now(),
        ]);
    }
}
