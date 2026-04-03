<?php

namespace App\Actions\Admin\Workbench;

use App\Actions\Admin\FeedItems\ManageFeedItemsAction;
use App\Actions\Admin\Shops\BootstrapShopForPilotAction;
use App\Models\FeedProfile;
use App\Models\User;
use RuntimeException;

class ExecuteWorkbenchBulkAction
{
    public function __construct(
        private readonly ManageFeedItemsAction $manageFeedItemsAction,
        private readonly BootstrapShopForPilotAction $bootstrapShopForPilotAction,
    ) {}

    /**
     * @param  list<int>  $feedItemIds
     * @return array<string, mixed>
     */
    public function preview(FeedProfile $feedProfile, string $operation, array $feedItemIds, ?string $reason = null): array
    {
        return [
            'operation' => $operation,
            'label' => $this->label($operation),
            'requires_reason' => $this->requiresReason($operation),
            'feed_item_ids' => $feedItemIds,
            'items_count' => count($feedItemIds),
            'reason' => $reason,
            'risk_level' => in_array($operation, ['exclude_items', 'rebuild_candidate'], true) ? 'warning' : 'info',
        ];
    }

    /**
     * @param  list<int>  $feedItemIds
     * @return array<string, mixed>
     */
    public function handle(FeedProfile $feedProfile, string $operation, array $feedItemIds, ?string $reason, User $user): array
    {
        return match ($operation) {
            'exclude_items' => [
                'message' => sprintf(
                    'Excluded %d item(s).',
                    $this->manageFeedItemsAction->handle($feedProfile, $feedItemIds, 'exclude', $reason)
                ),
            ],
            'revalidate_items' => [
                'message' => sprintf(
                    'Revalidated %d item(s).',
                    $this->manageFeedItemsAction->handle($feedProfile, $feedItemIds, 'revalidate', $reason)
                ),
            ],
            'rebuild_candidate' => [
                'message' => sprintf(
                    'Rebuilt release candidate generation #%d.',
                    $this->bootstrapShopForPilotAction->buildReleaseCandidate($user, $feedProfile)->id
                ),
            ],
            default => throw new RuntimeException(sprintf('Unsupported workbench bulk operation [%s].', $operation)),
        };
    }

    private function label(string $operation): string
    {
        return match ($operation) {
            'exclude_items' => 'Exclude selected items',
            'revalidate_items' => 'Revalidate selected items',
            'rebuild_candidate' => 'Rebuild current candidate',
            default => 'Bulk action',
        };
    }

    private function requiresReason(string $operation): bool
    {
        return in_array($operation, ['exclude_items', 'rebuild_candidate'], true);
    }
}
