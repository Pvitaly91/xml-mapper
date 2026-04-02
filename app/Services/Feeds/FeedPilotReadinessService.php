<?php

namespace App\Services\Feeds;

use App\Models\CategoryMapping;
use App\Models\FeedItem;
use App\Models\FeedProfile;
use App\Models\KastaAttribute;
use App\Models\KastaAttributeValue;
use App\Models\KastaCategory;
use App\Models\SourceImport;

class FeedPilotReadinessService
{
    public function __construct(
        private readonly FeedPublishGuardService $publishGuardService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function summarize(FeedProfile $feedProfile): array
    {
        $feedProfile->loadMissing(['sourceConnection.latestImport', 'latestGeneration', 'publishedGeneration']);

        $latestGeneration = $feedProfile->latestGeneration;
        $latestSummary = $latestGeneration?->meta['summary'] ?? [
            'total' => FeedItem::query()->where('feed_profile_id', $feedProfile->id)->count(),
            'ready' => FeedItem::query()->where('feed_profile_id', $feedProfile->id)->where('status', FeedItem::STATUS_READY)->count(),
            'published' => FeedItem::query()->where('feed_profile_id', $feedProfile->id)->where('status', FeedItem::STATUS_PUBLISHED)->count(),
            'excluded' => FeedItem::query()->where('feed_profile_id', $feedProfile->id)->where('status', FeedItem::STATUS_EXCLUDED)->count(),
            'invalid_total' => FeedItem::query()->where('feed_profile_id', $feedProfile->id)->whereIn('status', FeedItem::invalidStatuses())->count(),
            'invalid_source' => FeedItem::query()->where('feed_profile_id', $feedProfile->id)->where('status', FeedItem::STATUS_INVALID_SOURCE)->count(),
            'invalid_mapping' => FeedItem::query()->where('feed_profile_id', $feedProfile->id)->where('status', FeedItem::STATUS_INVALID_MAPPING)->count(),
            'invalid_conformance' => FeedItem::query()->where('feed_profile_id', $feedProfile->id)->where('status', FeedItem::STATUS_INVALID_CONFORMANCE)->count(),
        ];

        $latestImport = $feedProfile->sourceConnection?->latestImport;
        $sourceSynced = $latestImport instanceof SourceImport
            && $latestImport->status === SourceImport::STATUS_NORMALIZED;
        $mappingsComplete = ((int) ($latestSummary['invalid_mapping'] ?? 0)) === 0
            && CategoryMapping::query()->where('feed_profile_id', $feedProfile->id)->where('is_active', true)->exists();
        $dictionariesImported = KastaCategory::query()->count() > 0
            && KastaAttribute::query()->count() > 0
            && KastaAttributeValue::query()->count() > 0;
        $guard = $latestGeneration ? $this->publishGuardService->evaluate($feedProfile, $latestGeneration) : [
            'enabled' => $feedProfile->publishGuardEnabled(),
            'allowed' => false,
            'reasons' => ['Build a generation before publishing.'],
            'summary' => [],
        ];

        return [
            'source_synced' => [
                'ok' => $sourceSynced,
                'status' => $latestImport?->status ?? 'n/a',
                'finished_at' => $latestImport?->finished_at,
                'message' => $feedProfile->sourceConnection?->last_sync_message,
            ],
            'mappings_complete' => [
                'ok' => $mappingsComplete,
                'active_category_mappings' => CategoryMapping::query()->where('feed_profile_id', $feedProfile->id)->where('is_active', true)->count(),
                'invalid_mapping_items' => (int) ($latestSummary['invalid_mapping'] ?? 0),
            ],
            'dictionaries_imported' => [
                'ok' => $dictionariesImported,
                'categories' => KastaCategory::query()->count(),
                'attributes' => KastaAttribute::query()->count(),
                'attribute_values' => KastaAttributeValue::query()->count(),
            ],
            'latest_generation' => $latestGeneration,
            'generation_summary' => $latestSummary,
            'publish_guard' => $guard,
        ];
    }
}
