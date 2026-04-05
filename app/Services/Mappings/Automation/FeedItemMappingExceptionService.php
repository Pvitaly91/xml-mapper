<?php

namespace App\Services\Mappings\Automation;

use App\Models\FeedItem;
use App\Models\FeedItemMappingException;
use App\Models\FeedProfile;
use App\Models\User;
use App\Services\Governance\GovernanceAuditService;
use Illuminate\Support\Collection;

class FeedItemMappingExceptionService
{
    public function __construct(
        private readonly GovernanceAuditService $auditService,
    ) {}

    /**
     * @return Collection<int, FeedItemMappingException>
     */
    public function activeForFeedItem(FeedItem $feedItem): Collection
    {
        return $feedItem->relationLoaded('mappingExceptions')
            ? $feedItem->mappingExceptions->where('is_active', true)->values()
            : $feedItem->mappingExceptions()->where('is_active', true)->get();
    }

    public function upsertCategoryException(
        FeedProfile $feedProfile,
        FeedItem $feedItem,
        int $kastaCategoryId,
        string $targetLabel,
        string $reason,
        ?User $actor = null
    ): FeedItemMappingException {
        $exception = FeedItemMappingException::query()->updateOrCreate(
            [
                'feed_item_id' => $feedItem->id,
                'exception_type' => FeedItemMappingException::TYPE_CATEGORY,
                'target_key' => 'category_id',
            ],
            [
                'shop_id' => $feedProfile->shop_id,
                'feed_profile_id' => $feedProfile->id,
                'source_product_id' => $feedItem->source_product_id,
                'source_variant_id' => $feedItem->source_variant_id,
                'created_by_user_id' => $actor?->id,
                'target_value' => (string) $kastaCategoryId,
                'target_label' => $targetLabel,
                'reason' => $reason,
                'is_active' => true,
            ]
        );

        $this->auditService->record(
            'mapping',
            'feed_item_mapping_exception_saved',
            'Feed item category exception saved.',
            $actor,
            $feedProfile->shop,
            $feedItem,
            severity: 'warning',
            context: [
                'exception_type' => $exception->exception_type,
                'target_value' => $exception->target_value,
                'target_label' => $exception->target_label,
                'reason' => $reason,
            ],
            targetLabel: $feedItem->sourceVariant?->stable_offer_id
        );

        return $exception;
    }

    public function upsertAttributeException(
        FeedProfile $feedProfile,
        FeedItem $feedItem,
        string $attributeCode,
        string $targetValue,
        string $reason,
        ?User $actor = null
    ): FeedItemMappingException {
        $exception = FeedItemMappingException::query()->updateOrCreate(
            [
                'feed_item_id' => $feedItem->id,
                'exception_type' => FeedItemMappingException::TYPE_ATTRIBUTE_VALUE,
                'target_key' => $attributeCode,
            ],
            [
                'shop_id' => $feedProfile->shop_id,
                'feed_profile_id' => $feedProfile->id,
                'source_product_id' => $feedItem->source_product_id,
                'source_variant_id' => $feedItem->source_variant_id,
                'created_by_user_id' => $actor?->id,
                'target_value' => $targetValue,
                'target_label' => $attributeCode,
                'reason' => $reason,
                'is_active' => true,
            ]
        );

        $this->auditService->record(
            'mapping',
            'feed_item_mapping_exception_saved',
            'Feed item attribute exception saved.',
            $actor,
            $feedProfile->shop,
            $feedItem,
            severity: 'warning',
            context: [
                'exception_type' => $exception->exception_type,
                'target_key' => $attributeCode,
                'target_value' => $targetValue,
                'reason' => $reason,
            ],
            targetLabel: $feedItem->sourceVariant?->stable_offer_id
        );

        return $exception;
    }
}
