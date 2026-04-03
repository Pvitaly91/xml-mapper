<?php

namespace App\Actions\Admin\FeedItems;

use App\Models\FeedItem;
use App\Models\FeedProfile;
use App\Services\Feeds\FeedItemDiagnosticsService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

class RevalidateFeedItemsAction
{
    public function __construct(
        private readonly FeedItemDiagnosticsService $diagnosticsService,
    ) {}

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

            $analysis = $this->diagnosticsService->analyze($feedProfile, $product, $variant, $feedItem);
            $this->diagnosticsService->syncState($feedProfile, $feedItem, $product, $variant, $analysis);
            $count++;
        });

        return $count;
    }
}
