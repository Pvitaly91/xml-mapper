<?php

namespace App\Services\Mappings\Automation;

use App\Models\FeedItem;
use App\Models\FeedItemMappingException;
use App\Models\FeedProfile;
use App\Models\User;
use App\Services\Governance\GovernanceAuditService;
use App\Support\Canonicalizer;
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

    /**
     * @param  Collection<int, FeedItemMappingException>  $exceptions
     * @return array{
     *     fields:array<string,string>,
     *     images:list<string>,
     *     rows:list<array<string,mixed>>,
     *     has_manual_override:bool
     * }
     */
    public function extractContentOverrides(Collection $exceptions): array
    {
        $fields = [];
        $images = [];
        $rows = [];
        $hasManualOverride = false;

        foreach ($exceptions as $exception) {
            if (! $exception->is_active || ! in_array($exception->exception_type, FeedItemMappingException::contentTypes(), true)) {
                continue;
            }

            $origin = Canonicalizer::normalizeText((string) data_get($exception->meta, 'origin', 'manual')) ?: 'manual';
            $hasManualOverride = $hasManualOverride || $origin === 'manual';

            if ($exception->exception_type === FeedItemMappingException::TYPE_CONTENT_IMAGES) {
                $images = collect((array) data_get($exception->meta, 'images', []))
                    ->map(fn ($value) => Canonicalizer::normalizeText(is_scalar($value) ? (string) $value : null))
                    ->filter()
                    ->values()
                    ->all();

                $rows[] = [
                    'type' => 'content_images',
                    'target_key' => 'images',
                    'target_value' => implode(', ', $images),
                    'target_label' => 'Images',
                    'reason' => $exception->reason,
                    'origin' => $origin,
                ];

                continue;
            }

            if ($exception->target_key !== null && $exception->target_value !== null) {
                $fields[$exception->target_key] = $exception->target_value;
            }

            $rows[] = [
                'type' => 'content_field',
                'target_key' => $exception->target_key,
                'target_value' => $exception->target_value,
                'target_label' => ucfirst(str_replace('_', ' ', (string) $exception->target_key)),
                'reason' => $exception->reason,
                'origin' => $origin,
            ];
        }

        return [
            'fields' => $fields,
            'images' => $images,
            'rows' => $rows,
            'has_manual_override' => $hasManualOverride,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function syncContentOverrides(
        FeedProfile $feedProfile,
        FeedItem $feedItem,
        array $payload,
        string $reason,
        ?User $actor = null,
        string $origin = 'manual',
        array $meta = []
    ): void {
        $fields = collect($payload)
            ->only(['title', 'description', 'vendor', 'article', 'color', 'size', 'size_grid_code'])
            ->map(fn ($value) => Canonicalizer::normalizeText(is_scalar($value) ? (string) $value : null));
        $images = collect((array) ($payload['images'] ?? []))
            ->map(fn ($value) => Canonicalizer::normalizeText(is_scalar($value) ? (string) $value : null))
            ->filter()
            ->values()
            ->all();

        foreach ($fields as $field => $value) {
            if ($value === null) {
                FeedItemMappingException::query()
                    ->where('feed_item_id', $feedItem->id)
                    ->where('exception_type', FeedItemMappingException::TYPE_CONTENT_FIELD)
                    ->where('target_key', $field)
                    ->where('is_active', true)
                    ->update(['is_active' => false]);

                continue;
            }

            FeedItemMappingException::query()->updateOrCreate(
                [
                    'feed_item_id' => $feedItem->id,
                    'exception_type' => FeedItemMappingException::TYPE_CONTENT_FIELD,
                    'target_key' => $field,
                ],
                [
                    'shop_id' => $feedProfile->shop_id,
                    'feed_profile_id' => $feedProfile->id,
                    'source_product_id' => $feedItem->source_product_id,
                    'source_variant_id' => $feedItem->source_variant_id,
                    'created_by_user_id' => $actor?->id,
                    'target_value' => $value,
                    'target_label' => ucfirst(str_replace('_', ' ', $field)),
                    'reason' => $reason,
                    'is_active' => true,
                    'meta' => array_merge($meta, ['origin' => $origin]),
                ]
            );
        }

        if ($images === []) {
            FeedItemMappingException::query()
                ->where('feed_item_id', $feedItem->id)
                ->where('exception_type', FeedItemMappingException::TYPE_CONTENT_IMAGES)
                ->where('target_key', 'images')
                ->where('is_active', true)
                ->update(['is_active' => false]);
        } else {
            FeedItemMappingException::query()->updateOrCreate(
                [
                    'feed_item_id' => $feedItem->id,
                    'exception_type' => FeedItemMappingException::TYPE_CONTENT_IMAGES,
                    'target_key' => 'images',
                ],
                [
                    'shop_id' => $feedProfile->shop_id,
                    'feed_profile_id' => $feedProfile->id,
                    'source_product_id' => $feedItem->source_product_id,
                    'source_variant_id' => $feedItem->source_variant_id,
                    'created_by_user_id' => $actor?->id,
                    'target_value' => count($images).' image(s)',
                    'target_label' => 'Images',
                    'reason' => $reason,
                    'is_active' => true,
                    'meta' => array_merge($meta, [
                        'origin' => $origin,
                        'images' => $images,
                    ]),
                ]
            );
        }

        $this->auditService->record(
            'mapping',
            'feed_item_content_override_saved',
            'Feed item content override saved.',
            $actor,
            $feedProfile->shop,
            $feedItem,
            severity: 'warning',
            context: [
                'reason' => $reason,
                'origin' => $origin,
                'payload' => array_merge($fields->filter()->all(), ['images' => $images]),
            ],
            targetLabel: $feedItem->sourceVariant?->stable_offer_id
        );
    }
}
