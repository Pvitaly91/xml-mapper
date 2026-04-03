<?php

namespace App\Services\Feeds;

use App\Models\FeedGeneration;
use App\Models\FeedGenerationSmokeCheck;
use App\Models\FeedItem;
use App\Models\FeedProfile;

class FeedReleaseReportService
{
    public function __construct(
        private readonly FeedItemDiagnosticsService $diagnosticsService,
        private readonly FeedGenerationDiffService $diffService,
        private readonly FeedReleaseReadinessService $readinessService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function invalidItemsReport(FeedProfile $feedProfile, ?FeedGeneration $generation = null): array
    {
        $generation ??= $feedProfile->latestGeneration;

        $items = FeedItem::query()
            ->with(['sourceProduct.sourceCategory', 'sourceVariant', 'activeValidationErrors'])
            ->where('feed_profile_id', $feedProfile->id)
            ->whereIn('status', FeedItem::invalidStatuses())
            ->when($generation !== null, fn ($query) => $query->where('last_built_generation_id', $generation->id))
            ->orderBy('id')
            ->get();

        $rows = $items->map(function (FeedItem $feedItem) use ($feedProfile): array {
            $product = $feedItem->sourceProduct;
            $variant = $feedItem->sourceVariant;
            $analysis = ($product !== null && $variant !== null)
                ? $this->diagnosticsService->analyze($feedProfile, $product, $variant, $feedItem)
                : null;

            $blockingReasons = $feedItem->activeValidationErrors
                ->map(fn ($error) => '['.$error->code.'] '.$error->message)
                ->values()
                ->all();

            if ($blockingReasons === [] && $analysis !== null) {
                $blockingReasons = collect($analysis['all_errors'] ?? [])
                    ->map(fn (array $error) => '['.$error['code'].'] '.$error['message'])
                    ->values()
                    ->all();
            }

            return [
                'item_id' => $feedItem->id,
                'source_product_id' => $feedItem->source_product_id,
                'source_variant_id' => $feedItem->source_variant_id,
                'stable_offer_id' => $variant?->stable_offer_id,
                'external_offer_id' => $variant?->external_offer_id,
                'source_category' => $product?->sourceCategory?->full_path ?: $product?->sourceCategory?->name,
                'mapped_category' => $analysis['mapped_category']['full_path']
                    ?? $analysis['mapped_category']['name']
                    ?? null,
                'status' => $feedItem->status,
                'blocking_reasons' => $blockingReasons,
            ];
        })->values()->all();

        return [
            'feed_profile_id' => $feedProfile->id,
            'generation_id' => $generation?->id,
            'generated_at' => now()->toIso8601String(),
            'totals' => [
                'invalid_items' => count($rows),
            ],
            'rows' => $rows,
        ];
    }

    public function invalidItemsCsv(FeedProfile $feedProfile, ?FeedGeneration $generation = null): string
    {
        $report = $this->invalidItemsReport($feedProfile, $generation);
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, [
            'item_id',
            'source_product_id',
            'source_variant_id',
            'stable_offer_id',
            'external_offer_id',
            'source_category',
            'mapped_category',
            'status',
            'blocking_reasons',
        ]);

        foreach ($report['rows'] as $row) {
            fputcsv($handle, [
                $row['item_id'],
                $row['source_product_id'],
                $row['source_variant_id'],
                $row['stable_offer_id'],
                $row['external_offer_id'],
                $row['source_category'],
                $row['mapped_category'],
                $row['status'],
                implode(' | ', $row['blocking_reasons']),
            ]);
        }

        rewind($handle);

        return (string) stream_get_contents($handle);
    }

    /**
     * @return array<string, mixed>
     */
    public function generationDiffReport(FeedProfile $feedProfile, FeedGeneration $generation): array
    {
        return $generation->meta['diff'] ?? $this->diffService->summarize(
            $feedProfile->publishedGeneration && $feedProfile->publishedGeneration->id !== $generation->id
                ? $feedProfile->publishedGeneration
                : null,
            $generation
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function readinessReport(FeedProfile $feedProfile, FeedGeneration $generation): array
    {
        return array_merge(
            [
                'feed_profile_id' => $feedProfile->id,
                'generation_id' => $generation->id,
                'generated_at' => now()->toIso8601String(),
            ],
            $this->readinessService->evaluate($feedProfile, $generation)
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function smokeCheckReport(FeedGeneration $generation): array
    {
        $latestSmokeCheck = $generation->smokeChecks()->latest('checked_at')->first();

        return [
            'generation_id' => $generation->id,
            'generated_at' => now()->toIso8601String(),
            'status' => $latestSmokeCheck?->status ?? FeedGenerationSmokeCheck::STATUS_WARNING,
            'latest' => $latestSmokeCheck?->toArray(),
        ];
    }
}
