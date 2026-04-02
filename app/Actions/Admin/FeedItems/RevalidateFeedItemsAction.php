<?php

namespace App\Actions\Admin\FeedItems;

use App\Contracts\Mappings\CategoryMappingServiceInterface;
use App\Contracts\Validation\ValidationServiceInterface;
use App\Models\FeedItem;
use App\Models\FeedProfile;
use App\Support\Canonicalizer;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

class RevalidateFeedItemsAction
{
    public function __construct(
        private readonly ValidationServiceInterface $validationService,
        private readonly CategoryMappingServiceInterface $categoryMappingService,
    ) {
    }

    /**
     * @param  iterable<int, FeedItem>  $feedItems
     */
    public function handle(FeedProfile $feedProfile, iterable $feedItems): int
    {
        $count = 0;

        $items = $feedItems instanceof EloquentCollection
            ? $feedItems
            : new EloquentCollection(is_array($feedItems) ? $feedItems : iterator_to_array($feedItems, false));

        $items->loadMissing(['sourceProduct.sourceCategory', 'sourceVariant', 'validationErrors']);

        $items->each(function (FeedItem $feedItem) use ($feedProfile, &$count): void {
            $product = $feedItem->sourceProduct;
            $variant = $feedItem->sourceVariant;

            if ($product === null || $variant === null) {
                return;
            }

            $errors = $this->validationService->validate($feedProfile, $product, $variant, $feedItem);
            $this->validationService->syncValidationState($feedProfile, $feedItem, $product, $variant, $errors);

            $feedItem->last_validation_hash = Canonicalizer::fingerprint($errors);

            if (! $variant->is_enabled || ! $feedItem->is_enabled) {
                $feedItem->status = FeedItem::STATUS_EXCLUDED;
                $feedItem->excluded_reason = $feedItem->excluded_reason ?: 'Feed item is manually excluded.';
            } elseif ($errors !== []) {
                $feedItem->status = FeedItem::STATUS_INVALID;
                $feedItem->excluded_reason = null;
            } elseif ($this->categoryMappingService->getMappedCategory($feedProfile, $product->sourceCategory) === null) {
                $feedItem->status = FeedItem::STATUS_INVALID;
                $feedItem->excluded_reason = null;
            } else {
                $feedItem->status = FeedItem::STATUS_READY;
                $feedItem->excluded_reason = null;
            }

            $feedItem->save();
            $count++;
        });

        return $count;
    }
}
