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

        return [
            'feed_profile' => $feedProfile,
            'generation' => $generation,
            'summary' => [
                'source_products_total' => $sourceProductsTotal,
                'source_variants_total' => $sourceVariantsTotal,
                'normalized_total' => $normalizedTotal,
                'mapped_total' => (clone $feedItemsBase)->whereNotIn('status', [FeedItem::STATUS_PENDING, FeedItem::STATUS_INVALID_MAPPING])->count(),
                'ready_total' => $readyTotal,
                'excluded_total' => (clone $feedItemsBase)->where('status', FeedItem::STATUS_EXCLUDED)->count(),
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
}
