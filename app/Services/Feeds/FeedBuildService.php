<?php

namespace App\Services\Feeds;

use App\Contracts\Feeds\FeedBuildServiceInterface;
use App\Models\FeedGeneration;
use App\Models\FeedItem;
use App\Models\FeedProfile;
use App\Models\SourceVariant;
use App\Models\SyncLog;
use App\Services\Ops\ProcessLockService;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;
use XMLWriter;

class FeedBuildService implements FeedBuildServiceInterface
{
    public function __construct(
        private readonly FeedItemDiagnosticsService $diagnosticsService,
        private readonly KastaExportXmlService $xmlService,
        private readonly FeedGenerationDiffService $diffService,
        private readonly FeedPublishGuardService $publishGuardService,
        private readonly PilotNotificationService $notificationService,
        private readonly ProcessLockService $lockService,
    ) {}

    public function build(FeedProfile $feedProfile, ?int $sourceImportId = null): FeedGeneration
    {
        return $this->lockService->runExclusive(
            $this->lockService->feedBuildKey($feedProfile->id),
            (int) config('feed_mediator.locks.feed_build_ttl_seconds'),
            'Feed build is already in progress for this profile.',
            function () use ($feedProfile, $sourceImportId): FeedGeneration {
                $feedProfile = $feedProfile->fresh(['shop', 'sourceConnection', 'publishedGeneration']) ?? $feedProfile->loadMissing(['shop', 'sourceConnection', 'publishedGeneration']);

                if ($sourceImportId !== null) {
                    $existingGeneration = $feedProfile->generations()
                        ->where('source_import_id', $sourceImportId)
                        ->whereIn('status', [FeedGeneration::STATUS_BUILT, FeedGeneration::STATUS_PUBLISHED])
                        ->latest('id')
                        ->first();

                    if ($existingGeneration instanceof FeedGeneration) {
                        return $existingGeneration;
                    }
                }

                $generation = FeedGeneration::create([
                    'shop_id' => $feedProfile->shop_id,
                    'feed_profile_id' => $feedProfile->id,
                    'source_import_id' => $sourceImportId,
                    'status' => FeedGeneration::STATUS_BUILDING,
                    'release_status' => FeedGeneration::RELEASE_STATUS_BUILT,
                ]);

                try {
                    $usedCategories = [];
                    $itemsTotal = 0;
                    $validItems = 0;
                    $invalidItems = 0;
                    $excludedItems = 0;
                    $startedAt = microtime(true);
                    $statusCounters = [
                        FeedItem::STATUS_INVALID_SOURCE => 0,
                        FeedItem::STATUS_INVALID_MAPPING => 0,
                        FeedItem::STATUS_INVALID_CONFORMANCE => 0,
                    ];

                    SourceVariant::query()
                        ->with(['product.sourceCategory'])
                        ->where('shop_id', $feedProfile->shop_id)
                        ->where('source_connection_id', $feedProfile->source_connection_id)
                        ->where('is_enabled', true)
                        ->orderBy('id')
                        ->chunk((int) config('feed_mediator.performance.build_variant_chunk_size', 250), function ($variants) use (
                            $feedProfile,
                            $generation,
                            &$usedCategories,
                            &$itemsTotal,
                            &$validItems,
                            &$invalidItems,
                            &$excludedItems,
                            &$statusCounters
                        ): void {
                            foreach ($variants as $variant) {
                                $product = $variant->product;

                                if ($product === null || ! $product->is_active) {
                                    continue;
                                }

                                $itemsTotal++;

                                $feedItem = FeedItem::firstOrCreate(
                                    [
                                        'feed_profile_id' => $feedProfile->id,
                                        'source_variant_id' => $variant->id,
                                    ],
                                    [
                                        'shop_id' => $feedProfile->shop_id,
                                        'source_product_id' => $product->id,
                                        'status' => FeedItem::STATUS_PENDING,
                                        'is_enabled' => true,
                                        'is_manual_override' => false,
                                    ]
                                );

                                $feedItem->fill([
                                    'shop_id' => $feedProfile->shop_id,
                                    'source_product_id' => $product->id,
                                    'last_built_generation_id' => $generation->id,
                                ]);

                                $analysis = $this->diagnosticsService->analyze($feedProfile, $product, $variant, $feedItem);
                                $feedItem = $this->diagnosticsService->syncState(
                                    $feedProfile,
                                    $feedItem,
                                    $product,
                                    $variant,
                                    $analysis,
                                    $generation->id
                                );

                                if ($feedItem->status === FeedItem::STATUS_EXCLUDED) {
                                    $excludedItems++;

                                    continue;
                                }

                                if (in_array($feedItem->status, FeedItem::invalidStatuses(), true)) {
                                    $invalidItems++;
                                    $statusCounters[$feedItem->status]++;

                                    continue;
                                }

                                $usedCategories[$analysis['normalized_export_snapshot']['category_id']] = $analysis['normalized_export_snapshot']['category_name'];
                                $validItems++;
                            }
                        });

                    $path = $this->buildXmlFile($feedProfile, $generation, $usedCategories);
                    $builtAt = now();
                    $checksum = hash_file('sha256', Storage::disk(config('feed_mediator.storage_disk'))->path($path)) ?: null;
                    $buildDurationMs = (int) round((microtime(true) - $startedAt) * 1000);
                    $peakMemoryMb = round(memory_get_peak_usage(true) / 1024 / 1024, 2);

                    $generation->forceFill([
                        'items_total' => $itemsTotal,
                        'valid_items_total' => $validItems,
                        'invalid_items_total' => $invalidItems,
                        'file_path' => $path,
                        'checksum' => $checksum,
                    ]);

                    $summary = [
                        'total' => $itemsTotal,
                        'ready' => $validItems,
                        'published' => 0,
                        'excluded' => $excludedItems,
                        'invalid_total' => $invalidItems,
                        'invalid_source' => $statusCounters[FeedItem::STATUS_INVALID_SOURCE],
                        'invalid_mapping' => $statusCounters[FeedItem::STATUS_INVALID_MAPPING],
                        'invalid_conformance' => $statusCounters[FeedItem::STATUS_INVALID_CONFORMANCE],
                    ];

                    $generation->meta = ['summary' => $summary];
                    $diff = $this->diffService->summarize($feedProfile->publishedGeneration, $generation);
                    $guard = $this->publishGuardService->evaluate($feedProfile, $generation);

                    $generation->update([
                        'status' => FeedGeneration::STATUS_BUILT,
                        'release_status' => FeedGeneration::RELEASE_STATUS_BUILT,
                        'items_total' => $itemsTotal,
                        'valid_items_total' => $validItems,
                        'invalid_items_total' => $invalidItems,
                        'file_path' => $path,
                        'checksum' => $checksum,
                        'built_at' => $builtAt,
                        'error_message' => null,
                        'meta' => [
                            'summary' => $summary,
                            'diff' => $diff,
                            'publish_guard' => $guard,
                            'metrics' => [
                                'build_duration_ms' => $buildDurationMs,
                                'peak_memory_mb' => $peakMemoryMb,
                            ],
                        ],
                    ]);

                    $feedProfile->update([
                        'last_built_at' => $builtAt,
                        'next_build_at' => $builtAt->copy()->addMinutes($feedProfile->build_interval_minutes),
                    ]);

                    $this->log($feedProfile, $generation, 'info', 'feed.built', 'Feed XML generated.', [
                        'file_path' => $path,
                        'valid_items' => $validItems,
                        'invalid_items' => $invalidItems,
                        'excluded_items' => $excludedItems,
                    ]);

                    if (
                        (($summary['invalid_conformance'] ?? 0) > 0)
                        || ($feedProfile->publishGuardEnabled() && ! $guard['allowed'])
                    ) {
                        $this->notificationService->notifyFeedProfileAdmins(
                            $feedProfile,
                            'feed.release_attention_required',
                            'Feed generation needs operator attention',
                            'The latest built generation has critical conformance issues or failed publish guardrails.',
                            [
                                'generation_id' => $generation->id,
                                'summary' => $summary,
                                'publish_guard' => $guard,
                            ],
                            'warning',
                            $generation
                        );
                    }

                    return $generation->refresh();
                } catch (Throwable $exception) {
                    $generation->update([
                        'status' => FeedGeneration::STATUS_FAILED,
                        'release_status' => FeedGeneration::RELEASE_STATUS_BUILT,
                        'error_message' => $exception->getMessage(),
                    ]);

                    $this->log($feedProfile, $generation, 'error', 'feed.build_failed', $exception->getMessage(), [
                        'exception' => $exception::class,
                    ]);

                    throw $exception;
                }
            }
        );
    }

    /**
     * @param  array<string, string>  $usedCategories
     */
    private function buildXmlFile(FeedProfile $feedProfile, FeedGeneration $generation, array $usedCategories): string
    {
        $relativePath = trim(config('feed_mediator.builds_directory'), '/').'/shop-'.$feedProfile->shop_id.'/feed-'.$feedProfile->id.'/generation-'.$generation->id.'.xml';
        $absolutePath = Storage::disk(config('feed_mediator.storage_disk'))->path($relativePath);

        if (! is_dir(dirname($absolutePath))) {
            mkdir(dirname($absolutePath), 0777, true);
        }

        $writer = new XMLWriter;

        if (! $writer->openUri($absolutePath)) {
            throw new RuntimeException(sprintf('Failed to open XMLWriter for [%s].', $absolutePath));
        }

        $writer->startDocument('1.0', 'UTF-8');
        $writer->setIndent(true);
        $writer->startElement('yml_catalog');
        $writer->writeAttribute('date', now()->format('Y-m-d H:i'));
        $writer->startElement('shop');
        $writer->writeElement('name', $feedProfile->name);
        $writer->writeElement('company', $feedProfile->shop->name);
        $writer->writeElement('url', rtrim(config('app.url'), '/'));
        $writer->startElement('currencies');
        $writer->startElement('currency');
        $writer->writeAttribute('id', $feedProfile->currency ?: 'UAH');
        $writer->writeAttribute('rate', '1');
        $writer->endElement();
        $writer->endElement();
        $writer->startElement('categories');

        foreach ($usedCategories as $categoryId => $categoryName) {
            if (blank($categoryId)) {
                continue;
            }

            $writer->startElement('category');
            $writer->writeAttribute('id', (string) $categoryId);
            $writer->text((string) $categoryName);
            $writer->endElement();
        }

        $writer->endElement();
        $writer->startElement('offers');

        FeedItem::query()
            ->with(['sourceProduct.sourceCategory', 'sourceVariant'])
            ->where('feed_profile_id', $feedProfile->id)
            ->where('last_built_generation_id', $generation->id)
            ->where('status', FeedItem::STATUS_READY)
            ->orderBy('id')
            ->chunk((int) config('feed_mediator.performance.xml_write_chunk_size', 250), function ($feedItems) use ($feedProfile, $writer): void {
                foreach ($feedItems as $feedItem) {
                    $variant = $feedItem->sourceVariant;
                    $product = $feedItem->sourceProduct;

                    if ($variant === null || $product === null) {
                        continue;
                    }

                    $analysis = $this->diagnosticsService->analyze($feedProfile, $product, $variant, $feedItem);

                    if ($analysis['status'] !== FeedItem::STATUS_READY) {
                        continue;
                    }

                    $snapshot = $analysis['normalized_export_snapshot'];

                    if (blank($snapshot['category_id'] ?? null)) {
                        continue;
                    }

                    $this->xmlService->writeOffer($writer, $snapshot);
                }
            });

        $writer->endElement();
        $writer->endElement();
        $writer->endElement();
        $writer->endDocument();
        $writer->flush();

        return $relativePath;
    }

    private function log(FeedProfile $feedProfile, FeedGeneration $generation, string $level, string $event, string $message, array $context = []): void
    {
        SyncLog::create([
            'shop_id' => $feedProfile->shop_id,
            'source_connection_id' => $feedProfile->source_connection_id,
            'feed_profile_id' => $feedProfile->id,
            'feed_generation_id' => $generation->id,
            'level' => $level,
            'event' => $event,
            'message' => $message,
            'context' => $context,
            'occurred_at' => now(),
        ]);
    }
}
