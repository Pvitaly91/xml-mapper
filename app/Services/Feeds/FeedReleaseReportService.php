<?php

namespace App\Services\Feeds;

use App\Models\FeedGeneration;
use App\Models\FeedGenerationSmokeCheck;
use App\Models\FeedItem;
use App\Models\FeedProfile;
use App\Models\ValidationError;

class FeedReleaseReportService
{
    public function __construct(
        private readonly FeedItemDiagnosticsService $diagnosticsService,
        private readonly FeedGenerationDiffService $diffService,
        private readonly FeedReleaseReadinessService $readinessService,
        private readonly KastaExportXmlService $xmlService,
    ) {}

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
        $handle = fopen('php://temp', 'r+');
        $this->writeInvalidItemsCsv($handle, $feedProfile, $generation);
        rewind($handle);

        return (string) stream_get_contents($handle);
    }

    /**
     * @param  resource  $handle
     */
    public function writeInvalidItemsCsv($handle, FeedProfile $feedProfile, ?FeedGeneration $generation = null): void
    {
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

        FeedItem::query()
            ->with(['sourceProduct.sourceCategory', 'sourceVariant', 'activeValidationErrors'])
            ->where('feed_profile_id', $feedProfile->id)
            ->whereIn('status', FeedItem::invalidStatuses())
            ->when($generation !== null, fn ($query) => $query->where('last_built_generation_id', $generation->id))
            ->orderBy('id')
            ->chunkById((int) config('feed_mediator.performance.report_chunk_size', 500), function ($items) use ($handle, $feedProfile): void {
                foreach ($items as $feedItem) {
                    $row = $this->invalidItemRow($feedProfile, $feedItem);

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
            });
    }

    /**
     * @return array<string, mixed>
     */
    private function invalidItemRow(FeedProfile $feedProfile, FeedItem $feedItem): array
    {
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
    public function functionalXmlReport(FeedProfile $feedProfile, FeedGeneration $generation): array
    {
        $readiness = $this->readinessService->evaluate($feedProfile, $generation);
        $xmlGenerated = ! blank($generation->file_path);
        $offerSnapshots = [];
        $issues = [];

        if ($xmlGenerated) {
            try {
                $offerSnapshots = $this->xmlService->parseOfferSnapshots($generation->file_path);
            } catch (\Throwable $exception) {
                $xmlGenerated = false;
                $issues[] = [
                    'scope' => 'generation',
                    'level' => 'error',
                    'code' => 'xml_parse_failed',
                    'message' => $exception->getMessage(),
                    'feed_item_id' => null,
                    'stable_offer_id' => null,
                ];
            }
        }

        $included = [];
        $excluded = [];
        $blockerSummary = [];

        FeedItem::query()
            ->with(['sourceProduct.sourceCategory', 'sourceProduct.variants', 'sourceVariant', 'activeValidationErrors'])
            ->where('feed_profile_id', $feedProfile->id)
            ->where('last_built_generation_id', $generation->id)
            ->orderBy('id')
            ->get()
            ->each(function (FeedItem $feedItem) use (
                $feedProfile,
                $offerSnapshots,
                &$included,
                &$excluded,
                &$issues,
                &$blockerSummary
            ): void {
                $product = $feedItem->sourceProduct;
                $variant = $feedItem->sourceVariant;

                if ($product === null || $variant === null) {
                    return;
                }

                $analysis = $this->diagnosticsService->analyze($feedProfile, $product, $variant, $feedItem);
                $offerId = $analysis['normalized_export_snapshot']['offer_id'] ?? $variant->stable_offer_id;
                $errorRows = collect($analysis['all_errors'] ?? [])
                    ->map(fn (array $error) => [
                        'code' => $error['code'],
                        'message' => $error['message'],
                    ])
                    ->values()
                    ->all();
                $warningRows = collect($analysis['warnings'] ?? [])
                    ->map(fn (array $warning) => [
                        'code' => $warning['code'] ?? 'warning',
                        'message' => $warning['message'] ?? 'Warning',
                    ])
                    ->values()
                    ->all();
                $includedInXml = $feedItem->status === FeedItem::STATUS_READY && isset($offerSnapshots[$offerId]);
                $row = [
                    'feed_item_id' => $feedItem->id,
                    'source_product_id' => $feedItem->source_product_id,
                    'source_variant_id' => $feedItem->source_variant_id,
                    'stable_offer_id' => $variant->stable_offer_id,
                    'xml_offer_id' => $offerId,
                    'source_category' => $product->sourceCategory?->full_path ?: $product->sourceCategory?->name,
                    'mapped_category' => data_get($analysis, 'mapped_category.full_path')
                        ?: data_get($analysis, 'mapped_category.name'),
                    'status' => $feedItem->status,
                    'included_in_xml' => $includedInXml,
                    'excluded_reason' => $feedItem->excluded_reason,
                    'errors' => $errorRows,
                    'warnings' => $warningRows,
                    'trace' => [
                        'xml_snapshot' => $offerSnapshots[$offerId] ?? null,
                        'family_key' => data_get($analysis, 'family_context.family_key'),
                        'contract_profile' => data_get($analysis, 'contract.profile_key'),
                    ],
                ];

                if ($includedInXml) {
                    $included[] = $row;
                } else {
                    $excluded[] = $row;
                }

                foreach ($warningRows as $warning) {
                    $issues[] = [
                        'scope' => 'item',
                        'level' => 'warning',
                        'code' => $warning['code'],
                        'message' => $warning['message'],
                        'feed_item_id' => $feedItem->id,
                        'stable_offer_id' => $variant->stable_offer_id,
                    ];
                }

                foreach ($errorRows as $error) {
                    $issues[] = [
                        'scope' => 'item',
                        'level' => 'error',
                        'code' => $error['code'],
                        'message' => $error['message'],
                        'feed_item_id' => $feedItem->id,
                        'stable_offer_id' => $variant->stable_offer_id,
                    ];

                    $bucket = $this->blockerBucket($error['code']);
                    $summaryKey = ($product->sourceCategory?->full_path ?: $product->sourceCategory?->name ?: 'Uncategorized').'|'.$bucket['key'];

                    if (! array_key_exists($summaryKey, $blockerSummary)) {
                        $blockerSummary[$summaryKey] = [
                            'source_category' => $product->sourceCategory?->full_path ?: $product->sourceCategory?->name ?: 'Uncategorized',
                            'blocker_key' => $bucket['key'],
                            'blocker_label' => $bucket['label'],
                            'count' => 0,
                        ];
                    }

                    $blockerSummary[$summaryKey]['count']++;
                }

                if (! $includedInXml && $feedItem->excluded_reason) {
                    $issues[] = [
                        'scope' => 'item',
                        'level' => 'error',
                        'code' => 'item_excluded',
                        'message' => $feedItem->excluded_reason,
                        'feed_item_id' => $feedItem->id,
                        'stable_offer_id' => $variant->stable_offer_id,
                    ];
                }
            });

        $warningsCount = collect($issues)->where('level', 'warning')->count();
        $errorsCount = collect($issues)->where('level', 'error')->count();
        $approvedForPublish = in_array($generation->release_status, [FeedGeneration::RELEASE_STATUS_APPROVED, FeedGeneration::RELEASE_STATUS_PUBLISHED], true);
        $hasFunctionalBlockers = ! $xmlGenerated || $excluded !== [] || $errorsCount > 0;
        $functionalStatus = match (true) {
            ! $xmlGenerated => 'build_failed',
            $hasFunctionalBlockers => 'needs_fixes',
            ! $approvedForPublish => 'awaiting_approval',
            default => 'publish_ready',
        };

        return [
            'feed_profile_id' => $feedProfile->id,
            'generation_id' => $generation->id,
            'generated_at' => now()->toIso8601String(),
            'summary' => [
                'xml_generated_successfully' => $xmlGenerated,
                'artifact_path' => $generation->file_path,
                'included_items_count' => count($included),
                'excluded_items_count' => count($excluded),
                'issues_count' => count($issues),
                'warnings_count' => $warningsCount,
                'errors_count' => $errorsCount,
                'release_status' => $generation->release_status,
                'functional_status' => $functionalStatus,
                'readiness_status' => $readiness['status'],
                'release_readiness_status' => $readiness['status'],
                'publish_ready' => $functionalStatus === 'publish_ready',
            ],
            'included_items' => array_values($included),
            'excluded_items' => array_values($excluded),
            'issues' => array_values($issues),
            'blocker_summary' => array_values($blockerSummary),
        ];
    }

    public function functionalXmlCsv(FeedProfile $feedProfile, FeedGeneration $generation, string $type): string
    {
        $report = $this->functionalXmlReport($feedProfile, $generation);
        $handle = fopen('php://temp', 'r+');

        match ($type) {
            'included' => $this->writeIncludedCsv($handle, $report['included_items']),
            'excluded' => $this->writeExcludedCsv($handle, $report['excluded_items']),
            'issues' => $this->writeIssuesCsv($handle, $report['issues']),
            default => $this->writeBlockerSummaryCsv($handle, $report['blocker_summary']),
        };

        rewind($handle);

        return (string) stream_get_contents($handle);
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

    /**
     * @param  resource  $handle
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function writeIncludedCsv($handle, array $rows): void
    {
        fputcsv($handle, ['feed_item_id', 'stable_offer_id', 'xml_offer_id', 'source_category', 'mapped_category', 'status']);

        foreach ($rows as $row) {
            fputcsv($handle, [
                $row['feed_item_id'],
                $row['stable_offer_id'],
                $row['xml_offer_id'],
                $row['source_category'],
                $row['mapped_category'],
                $row['status'],
            ]);
        }
    }

    /**
     * @param  resource  $handle
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function writeExcludedCsv($handle, array $rows): void
    {
        fputcsv($handle, ['feed_item_id', 'stable_offer_id', 'source_category', 'mapped_category', 'status', 'excluded_reason', 'errors']);

        foreach ($rows as $row) {
            fputcsv($handle, [
                $row['feed_item_id'],
                $row['stable_offer_id'],
                $row['source_category'],
                $row['mapped_category'],
                $row['status'],
                $row['excluded_reason'],
                collect($row['errors'])->map(fn ($error) => '['.$error['code'].'] '.$error['message'])->implode(' | '),
            ]);
        }
    }

    /**
     * @param  resource  $handle
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function writeIssuesCsv($handle, array $rows): void
    {
        fputcsv($handle, ['scope', 'level', 'code', 'message', 'feed_item_id', 'stable_offer_id']);

        foreach ($rows as $row) {
            fputcsv($handle, [
                $row['scope'],
                $row['level'],
                $row['code'],
                $row['message'],
                $row['feed_item_id'],
                $row['stable_offer_id'],
            ]);
        }
    }

    /**
     * @param  resource  $handle
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function writeBlockerSummaryCsv($handle, array $rows): void
    {
        fputcsv($handle, ['source_category', 'blocker_key', 'blocker_label', 'count']);

        foreach ($rows as $row) {
            fputcsv($handle, [
                $row['source_category'],
                $row['blocker_key'],
                $row['blocker_label'],
                $row['count'],
            ]);
        }
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
