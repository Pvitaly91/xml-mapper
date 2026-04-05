<?php

namespace App\Services\Feeds;

use App\Models\FeedGeneration;
use App\Models\FeedItem;
use App\Models\FeedProfile;
use App\Models\SourceProduct;
use App\Models\SourceVariant;
use App\Models\ValidationError;

class FeedReconciliationService
{
    /**
     * @return array<string, mixed>
     */
    public function summarize(FeedProfile $feedProfile, ?FeedGeneration $generation = null, array $filters = []): array
    {
        $feedProfile->loadMissing(['publishedGeneration', 'latestGeneration', 'sourceConnection']);
        $generation ??= $feedProfile->latestGeneration;

        $sourceProductsTotal = SourceProduct::query()
            ->where('source_connection_id', $feedProfile->source_connection_id)
            ->count();
        $sourceVariantsTotal = SourceVariant::query()
            ->where('source_connection_id', $feedProfile->source_connection_id)
            ->count();
        $normalizedTotal = SourceVariant::query()
            ->where('source_connection_id', $feedProfile->source_connection_id)
            ->where('is_enabled', true)
            ->whereHas('product', fn ($query) => $query->where('is_active', true))
            ->count();

        $feedItemsBase = FeedItem::query()
            ->where('feed_profile_id', $feedProfile->id)
            ->when($generation !== null, fn ($query) => $query->where('last_built_generation_id', $generation->id));

        $validationBreakdown = ValidationError::query()
            ->where('feed_profile_id', $feedProfile->id)
            ->where('is_active', true)
            ->when($generation !== null, function ($query) use ($generation): void {
                $query->whereHas('feedItem', fn ($builder) => $builder->where('last_built_generation_id', $generation->id));
            })
            ->selectRaw('code, count(*) as aggregate')
            ->groupBy('code')
            ->orderByDesc('aggregate')
            ->get()
            ->map(fn ($row) => [
                'code' => $row->code,
                'count' => (int) $row->aggregate,
            ])
            ->values()
            ->all();

        $publishedGeneration = $feedProfile->publishedGeneration;
        $publishedOffers = (int) ($publishedGeneration?->meta['summary']['ready'] ?? $publishedGeneration?->valid_items_total ?? 0);
        $blockerFilter = trim((string) ($filters['blocker'] ?? ''));
        $readyTotal = (clone $feedItemsBase)->where('status', FeedItem::STATUS_READY)->count();
        $invalidTotal = (clone $feedItemsBase)->whereIn('status', FeedItem::invalidStatuses())->count();
        $excludedTotal = (clone $feedItemsBase)->where('status', FeedItem::STATUS_EXCLUDED)->count();
        $breakdownQuery = FeedItem::query()
            ->with(['sourceProduct.sourceCategory', 'sourceVariant', 'activeValidationErrors'])
            ->where('feed_profile_id', $feedProfile->id)
            ->when($generation !== null, fn ($query) => $query->where('last_built_generation_id', $generation->id))
            ->when($blockerFilter !== '', function ($query) use ($blockerFilter): void {
                $query->whereHas('activeValidationErrors', fn ($innerQuery) => $innerQuery->where('code', $blockerFilter));
            })
            ->orderByDesc('id')
            ->limit((int) config('feed_mediator.performance.reconciliation_breakdown_limit', 250))
            ->get();
        $allFeedItems = FeedItem::query()
            ->with(['sourceProduct.sourceCategory', 'sourceVariant', 'activeValidationErrors'])
            ->where('feed_profile_id', $feedProfile->id)
            ->when($generation !== null, fn ($query) => $query->where('last_built_generation_id', $generation->id))
            ->get();
        [$blockerBuckets, $categoryBlockers, $estimatedGain] = $this->functionalBlockers($allFeedItems);

        return [
            'feed_profile' => $feedProfile,
            'generation' => $generation,
            'summary' => [
                'source_products_total' => $sourceProductsTotal,
                'source_variants_total' => $sourceVariantsTotal,
                'total_source_items' => $sourceVariantsTotal,
                'normalized_total' => $normalizedTotal,
                'mapped_total' => (clone $feedItemsBase)->whereNotIn('status', [FeedItem::STATUS_PENDING, FeedItem::STATUS_INVALID_MAPPING])->count(),
                'export_ready_total' => $readyTotal,
                'ready_total' => $readyTotal,
                'excluded_total' => $excludedTotal,
                'blocked_total' => $invalidTotal + $excludedTotal,
                'invalid_total' => $invalidTotal,
                'published_total' => $publishedOffers,
                'deltas' => [
                    'source_variants_vs_published' => $sourceVariantsTotal - $publishedOffers,
                    'ready_vs_published' => $readyTotal - $publishedOffers,
                ],
            ],
            'invalid_breakdown' => [
                'invalid_source' => (clone $feedItemsBase)->where('status', FeedItem::STATUS_INVALID_SOURCE)->count(),
                'invalid_mapping' => (clone $feedItemsBase)->where('status', FeedItem::STATUS_INVALID_MAPPING)->count(),
                'invalid_conformance' => (clone $feedItemsBase)->where('status', FeedItem::STATUS_INVALID_CONFORMANCE)->count(),
                'by_error_code' => $validationBreakdown,
            ],
            'top_blockers' => array_slice($validationBreakdown, 0, 8),
            'functional_blockers' => $blockerBuckets,
            'blockers_by_category' => $categoryBlockers,
            'estimated_gain' => $estimatedGain,
            'direct_actions' => [
                [
                    'label' => 'Open unresolved mapping workbench',
                    'url' => route('admin.feed-profiles.workbench.index', $feedProfile),
                    'method' => 'GET',
                ],
                [
                    'label' => 'Apply suggestions',
                    'url' => route('admin.feed-profiles.mapping-coverage.show', $feedProfile),
                    'method' => 'GET',
                ],
                [
                    'label' => 'Apply content/enrichment rules',
                    'url' => route('admin.feed-profiles.content-enrichment.index', $feedProfile),
                    'method' => 'GET',
                ],
                [
                    'label' => 'Edit item exception/override',
                    'url' => route('admin.feed-profiles.feed-items.index', $feedProfile),
                    'method' => 'GET',
                ],
                [
                    'label' => 'Rebuild candidate XML',
                    'url' => route('admin.feed-profiles.build', $feedProfile),
                    'method' => 'POST',
                ],
                [
                    'label' => 'Open final XML validation report',
                    'url' => $generation ? route('admin.feed-profiles.generations.show', [$feedProfile, $generation]) : null,
                    'method' => 'GET',
                ],
            ],
            'breakdown' => $this->breakdownRows($breakdownQuery),
        ];
    }

    public function jsonReport(FeedProfile $feedProfile, ?FeedGeneration $generation = null): string
    {
        return json_encode(
            $this->summarize($feedProfile, $generation),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }

    public function csvReport(FeedProfile $feedProfile, ?FeedGeneration $generation = null): string
    {
        $report = $this->summarize($feedProfile, $generation);
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, ['blocker', 'count']);

        foreach ($report['top_blockers'] as $row) {
            fputcsv($handle, [$row['code'], $row['count']]);
        }

        rewind($handle);

        return (string) stream_get_contents($handle);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function breakdownRows($feedItems): array
    {
        return $feedItems
            ->map(function (FeedItem $item): array {
                $blockers = $item->activeValidationErrors
                    ->map(fn (ValidationError $error) => [
                        'code' => $error->code,
                        'message' => $error->message,
                    ])
                    ->values()
                    ->all();

                return [
                    'feed_item_id' => $item->id,
                    'status' => $item->status,
                    'source_variant_id' => $item->source_variant_id,
                    'stable_offer_id' => $item->sourceVariant?->stable_offer_id,
                    'source_category' => $item->sourceProduct?->sourceCategory?->full_path ?: $item->sourceProduct?->sourceCategory?->name,
                    'blockers' => $blockers,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array{0:array<int,array<string,mixed>>,1:array<int,array<string,mixed>>,2:array<int,array<string,mixed>>}
     */
    private function functionalBlockers($feedItems): array
    {
        $bucketSummary = [];
        $categorySummary = [];
        $itemBuckets = [];

        foreach ($feedItems as $item) {
            $category = $item->sourceProduct?->sourceCategory?->full_path ?: $item->sourceProduct?->sourceCategory?->name ?: 'Uncategorized';

            foreach ($item->activeValidationErrors as $error) {
                $bucket = $this->blockerBucket($error->code);

                if (! array_key_exists($bucket['key'], $bucketSummary)) {
                    $bucketSummary[$bucket['key']] = [
                        'key' => $bucket['key'],
                        'label' => $bucket['label'],
                        'count' => 0,
                        'item_ids' => [],
                    ];
                }

                $bucketSummary[$bucket['key']]['count']++;
                $bucketSummary[$bucket['key']]['item_ids'][$item->id] = true;
                $itemBuckets[$item->id][$bucket['key']] = true;

                $summaryKey = $category.'|'.$bucket['key'];

                if (! array_key_exists($summaryKey, $categorySummary)) {
                    $categorySummary[$summaryKey] = [
                        'source_category' => $category,
                        'blocker_key' => $bucket['key'],
                        'blocker_label' => $bucket['label'],
                        'count' => 0,
                    ];
                }

                $categorySummary[$summaryKey]['count']++;
            }
        }

        $estimatedGain = collect($bucketSummary)
            ->map(function (array $row) use ($itemBuckets): array {
                $soleBlockerItems = collect(array_keys($row['item_ids']))
                    ->filter(fn ($itemId) => count($itemBuckets[$itemId] ?? []) === 1)
                    ->count();

                return [
                    'key' => $row['key'],
                    'label' => $row['label'],
                    'affected_items' => count($row['item_ids']),
                    'estimated_ready_gain' => $soleBlockerItems,
                ];
            })
            ->sortByDesc('estimated_ready_gain')
            ->values()
            ->all();

        $bucketRows = collect($bucketSummary)
            ->map(fn (array $row) => [
                'key' => $row['key'],
                'label' => $row['label'],
                'count' => $row['count'],
                'affected_items' => count($row['item_ids']),
            ])
            ->sortByDesc('count')
            ->values()
            ->all();

        return [
            $bucketRows,
            collect($categorySummary)->sortByDesc('count')->values()->all(),
            $estimatedGain,
        ];
    }

    /**
     * @return array{key:string,label:string}
     */
    private function blockerBucket(string $code): array
    {
        return match ($code) {
            ValidationError::CODE_MISSING_CATEGORY_MAPPING => ['key' => 'missing_category_mapping', 'label' => 'Missing category mapping'],
            ValidationError::CODE_MISSING_REQUIRED_ATTRIBUTE_MAPPING => ['key' => 'missing_attribute_mapping', 'label' => 'Missing attribute mapping'],
            ValidationError::CODE_MISSING_VALUE_MAPPING => ['key' => 'missing_value_mapping', 'label' => 'Missing value mapping'],
            ValidationError::CODE_MISSING_VENDOR,
            ValidationError::CODE_MISSING_ARTICLE,
            ValidationError::CODE_MISSING_REQUIRED_ATTRIBUTE_VALUE,
            ValidationError::CODE_MISSING_PRICE => ['key' => 'missing_required_export_field', 'label' => 'Missing required export field'],
            ValidationError::CODE_INVALID_TITLE,
            ValidationError::CODE_INVALID_DESCRIPTION => ['key' => 'invalid_content', 'label' => 'Invalid content/title/description'],
            ValidationError::CODE_MISSING_PHOTO,
            ValidationError::CODE_INSUFFICIENT_IMAGES,
            ValidationError::CODE_INVALID_IMAGE_URL => ['key' => 'insufficient_images', 'label' => 'Insufficient images'],
            ValidationError::CODE_INVALID_COLOR,
            ValidationError::CODE_INVALID_SIZE,
            ValidationError::CODE_INVALID_SIZE_GRID => ['key' => 'size_color_unresolved', 'label' => 'Size/color unresolved'],
            ValidationError::CODE_VARIANT_GROUPING_ISSUE,
            ValidationError::CODE_DUPLICATED_OR_UNSTABLE_OFFER_ID => ['key' => 'duplicate_variant_grouping_issue', 'label' => 'Duplicate/variant grouping issue'],
            default => ['key' => 'contract_validation_issue', 'label' => 'Contract validation issue'],
        };
    }
}
