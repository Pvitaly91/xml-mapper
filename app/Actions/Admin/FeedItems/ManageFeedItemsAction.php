<?php

namespace App\Actions\Admin\FeedItems;

use App\Models\FeedItem;
use App\Models\FeedProfile;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ManageFeedItemsAction
{
    public function __construct(
        private readonly RevalidateFeedItemsAction $revalidateAction,
    ) {
    }

    /**
     * @param  list<int>  $feedItemIds
     */
    public function handle(FeedProfile $feedProfile, array $feedItemIds, string $operation, ?string $reason = null): int
    {
        $items = FeedItem::query()
            ->with('sourceVariant')
            ->where('feed_profile_id', $feedProfile->id)
            ->whereIn('id', $feedItemIds)
            ->get();

        if ($items->isEmpty()) {
            return 0;
        }

        return DB::transaction(function () use ($feedProfile, $items, $operation, $reason): int {
            switch ($operation) {
                case 'enable':
                    foreach ($items as $item) {
                        $item->sourceVariant?->update(['is_enabled' => true]);
                        $item->update([
                            'is_enabled' => true,
                            'is_manual_override' => true,
                            'excluded_reason' => null,
                        ]);
                    }

                    return $this->revalidateAction->handle($feedProfile, $items);

                case 'disable':
                    foreach ($items as $item) {
                        $item->sourceVariant?->update(['is_enabled' => false]);
                        $item->update([
                            'is_enabled' => false,
                            'is_manual_override' => true,
                            'status' => FeedItem::STATUS_EXCLUDED,
                            'excluded_reason' => $reason ?: 'Source variant manually disabled.',
                        ]);
                    }

                    return $items->count();

                case 'include':
                    foreach ($items as $item) {
                        $item->update([
                            'is_enabled' => true,
                            'is_manual_override' => true,
                            'excluded_reason' => null,
                        ]);
                    }

                    return $this->revalidateAction->handle($feedProfile, $items);

                case 'exclude':
                    foreach ($items as $item) {
                        $item->update([
                            'is_enabled' => false,
                            'is_manual_override' => true,
                            'status' => FeedItem::STATUS_EXCLUDED,
                            'excluded_reason' => $reason ?: 'Feed item manually excluded.',
                        ]);
                    }

                    return $items->count();

                case 'revalidate':
                    return $this->revalidateAction->handle($feedProfile, $items);

                default:
                    throw new RuntimeException(sprintf('Unsupported feed item operation [%s].', $operation));
            }
        });
    }
}
