<?php

namespace App\Actions\Admin\FeedItems;

use App\Models\FeedItem;
use App\Models\FeedProfile;
use App\Models\User;
use App\Services\Feeds\FeedItemDiagnosticsService;
use App\Services\Mappings\Automation\FeedItemMappingExceptionService;

class ApplyFeedItemEnrichmentAction
{
    public function __construct(
        private readonly FeedItemDiagnosticsService $diagnosticsService,
        private readonly FeedItemMappingExceptionService $exceptionService,
    ) {}

    /**
     * @param  list<int>  $feedItemIds
     * @return array{applied:int,skipped:int,manual_skipped:int}
     */
    public function handle(FeedProfile $feedProfile, array $feedItemIds, string $reason, ?User $actor = null): array
    {
        $summary = [
            'applied' => 0,
            'skipped' => 0,
            'manual_skipped' => 0,
        ];

        FeedItem::query()
            ->with(['sourceProduct.sourceCategory', 'sourceProduct.variants', 'sourceVariant', 'mappingExceptions'])
            ->where('feed_profile_id', $feedProfile->id)
            ->whereIn('id', $feedItemIds)
            ->orderBy('id')
            ->get()
            ->each(function (FeedItem $feedItem) use ($feedProfile, $actor, $reason, &$summary): void {
                $product = $feedItem->sourceProduct;
                $variant = $feedItem->sourceVariant;

                if ($product === null || $variant === null) {
                    $summary['skipped']++;

                    return;
                }

                $contentOverrides = $this->exceptionService->extractContentOverrides($feedItem->mappingExceptions);

                if ($contentOverrides['has_manual_override']) {
                    $summary['manual_skipped']++;

                    return;
                }

                $analysis = $this->diagnosticsService->analyze($feedProfile, $product, $variant, $feedItem);
                $payload = (array) data_get($analysis, 'enrichment.apply_payload', []);

                if ($payload === []) {
                    $summary['skipped']++;

                    return;
                }

                $this->exceptionService->syncContentOverrides(
                    $feedProfile,
                    $feedItem,
                    $payload,
                    $reason,
                    $actor,
                    'enrichment_apply',
                    [
                        'rules' => data_get($analysis, 'enrichment.rules', []),
                    ]
                );

                $summary['applied']++;
            });

        return $summary;
    }
}
