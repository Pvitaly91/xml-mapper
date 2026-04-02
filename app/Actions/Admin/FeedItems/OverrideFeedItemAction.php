<?php

namespace App\Actions\Admin\FeedItems;

use App\Models\FeedItem;
use App\Models\FeedProfile;

class OverrideFeedItemAction
{
    public function __construct(
        private readonly RevalidateFeedItemsAction $revalidateAction,
    ) {
    }

    /**
     * @param  array{is_enabled:bool,excluded_reason:?string}  $payload
     */
    public function handle(FeedProfile $feedProfile, FeedItem $feedItem, array $payload): FeedItem
    {
        $feedItem->update([
            'is_enabled' => $payload['is_enabled'],
            'is_manual_override' => true,
            'excluded_reason' => $payload['is_enabled'] ? null : ($payload['excluded_reason'] ?: 'Feed item manually excluded.'),
            'status' => $payload['is_enabled'] ? $feedItem->status : FeedItem::STATUS_EXCLUDED,
        ]);

        $this->revalidateAction->handle($feedProfile, [$feedItem->refresh()]);

        return $feedItem->refresh();
    }
}
