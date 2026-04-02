<?php

namespace App\Services\Feeds;

use App\Contracts\Validation\ValidationServiceInterface;
use App\Models\FeedItem;
use App\Models\FeedProfile;
use App\Models\SourceProduct;
use App\Models\SourceVariant;
use App\Support\Canonicalizer;

class FeedItemDiagnosticsService
{
    public function __construct(
        private readonly ValidationServiceInterface $validationService,
        private readonly KastaExportConformanceService $conformanceService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function analyze(
        FeedProfile $feedProfile,
        SourceProduct $product,
        SourceVariant $variant,
        ?FeedItem $feedItem = null
    ): array {
        $sourceErrors = $this->validationService->validate($feedProfile, $product, $variant, $feedItem);
        $conformance = $this->conformanceService->analyze($feedProfile, $product, $variant, $sourceErrors, $feedItem);

        $sourceErrors = $conformance['source_errors'];
        $mappingErrors = $conformance['mapping_errors'];
        $conformanceErrors = $conformance['conformance_errors'];
        $status = FeedItem::STATUS_READY;
        $excludedReason = null;

        if (! $variant->is_enabled || ($feedItem !== null && ! $feedItem->is_enabled)) {
            $status = FeedItem::STATUS_EXCLUDED;
            $excludedReason = $feedItem?->excluded_reason ?: 'Feed item is manually excluded.';
        } elseif (! $feedProfile->include_unavailable && ! $variant->is_available) {
            $status = FeedItem::STATUS_EXCLUDED;
            $excludedReason = 'Variant is unavailable.';
        } elseif ($sourceErrors !== []) {
            $status = FeedItem::STATUS_INVALID_SOURCE;
        } elseif ($mappingErrors !== []) {
            $status = FeedItem::STATUS_INVALID_MAPPING;
        } elseif ($conformanceErrors !== []) {
            $status = FeedItem::STATUS_INVALID_CONFORMANCE;
        }

        $allErrors = array_merge($sourceErrors, $mappingErrors, $conformanceErrors);

        return array_merge($conformance, [
            'status' => $status,
            'excluded_reason' => $excludedReason,
            'all_errors' => $allErrors,
            'last_validation_hash' => Canonicalizer::fingerprint($allErrors),
        ]);
    }

    /**
     * @param  array<string, mixed>  $analysis
     */
    public function syncState(
        FeedProfile $feedProfile,
        FeedItem $feedItem,
        SourceProduct $product,
        SourceVariant $variant,
        array $analysis,
        ?int $generationId = null
    ): FeedItem {
        $this->validationService->syncValidationState(
            $feedProfile,
            $feedItem,
            $product,
            $variant,
            $analysis['all_errors']
        );

        $feedItem->fill([
            'shop_id' => $feedProfile->shop_id,
            'source_product_id' => $product->id,
            'last_built_generation_id' => $generationId ?? $feedItem->last_built_generation_id,
            'status' => $analysis['status'],
            'excluded_reason' => $analysis['excluded_reason'],
            'last_validation_hash' => $analysis['last_validation_hash'],
        ]);

        $feedItem->save();

        return $feedItem->refresh();
    }
}
