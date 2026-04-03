<?php

namespace App\Services\Shops;

use App\Models\FeedGeneration;
use App\Models\FeedItem;
use App\Models\Shop;
use App\Models\ValidationError;
use App\Services\Feeds\FeedPilotReadinessService;
use App\Services\Feeds\FeedReleaseReadinessService;
use App\Services\Pilot\PilotReadinessScoreService;

class ShopControlPanelService
{
    public function __construct(
        private readonly FeedPilotReadinessService $pilotReadinessService,
        private readonly FeedReleaseReadinessService $releaseReadinessService,
        private readonly ShopOnboardingService $onboardingService,
        private readonly PilotReadinessScoreService $pilotReadinessScoreService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function summarize(Shop $shop): array
    {
        $adminUser = $shop->users()->where('role', 'admin')->oldest('id')->first();
        $sourceConnection = $shop->sourceConnections()
            ->with('latestImport')
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
            ->latest('id')
            ->first();
        $feedProfile = $shop->feedProfiles()
            ->with(['latestGeneration', 'publishedGeneration', 'sourceConnection.latestImport'])
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
            ->latest('id')
            ->first();
        $latestGeneration = $feedProfile?->latestGeneration;
        $publishedGeneration = $feedProfile?->publishedGeneration;
        $pilotReadiness = $feedProfile ? $this->pilotReadinessService->summarize($feedProfile) : null;
        $releaseReadiness = ($feedProfile && $latestGeneration)
            ? $this->releaseReadinessService->evaluate($feedProfile, $latestGeneration)
            : null;
        $latestPilotRun = $feedProfile?->pilotRuns()->latest('id')->first();

        return [
            'source_connection' => $sourceConnection,
            'feed_profile' => $feedProfile,
            'latest_pilot_run' => $latestPilotRun,
            'pilot_score' => $feedProfile ? $this->pilotReadinessScoreService->score($feedProfile, $latestPilotRun) : null,
            'latest_candidate_generation' => $this->latestGenerationByStatus($shop, FeedGeneration::RELEASE_STATUS_CANDIDATE),
            'latest_approved_generation' => $this->latestGenerationByStatus($shop, FeedGeneration::RELEASE_STATUS_APPROVED),
            'latest_published_generation' => $publishedGeneration,
            'latest_generation' => $latestGeneration,
            'latest_smoke_check_status' => $publishedGeneration?->last_smoke_check_status,
            'pilot_readiness' => $pilotReadiness,
            'release_readiness' => $releaseReadiness,
            'unresolved_counts' => [
                'missing_category_mapping' => $this->validationCount($feedProfile?->id, [ValidationError::CODE_MISSING_CATEGORY_MAPPING]),
                'missing_attribute_mapping' => $this->validationCount($feedProfile?->id, [ValidationError::CODE_MISSING_REQUIRED_ATTRIBUTE_MAPPING]),
                'missing_value_mapping' => $this->validationCount($feedProfile?->id, [ValidationError::CODE_MISSING_VALUE_MAPPING]),
                'missing_required_source_value' => $this->validationCount($feedProfile?->id, [ValidationError::CODE_MISSING_REQUIRED_ATTRIBUTE_VALUE]),
                'invalid_color_size' => $this->validationCount($feedProfile?->id, [ValidationError::CODE_INVALID_COLOR, ValidationError::CODE_INVALID_SIZE]),
            ],
            'feed_item_counts' => [
                'ready' => $this->feedItemCount($feedProfile?->id, [FeedItem::STATUS_READY, FeedItem::STATUS_PUBLISHED]),
                'invalid' => $this->feedItemCount($feedProfile?->id, FeedItem::invalidStatuses()),
                'excluded' => $this->feedItemCount($feedProfile?->id, [FeedItem::STATUS_EXCLUDED]),
            ],
            'publish_allowed' => (bool) ($latestGeneration?->meta['publish_guard']['allowed'] ?? false),
            'onboarding' => $adminUser ? $this->onboardingService->summarize($adminUser) : null,
        ];
    }

    private function latestGenerationByStatus(Shop $shop, string $releaseStatus): ?FeedGeneration
    {
        return FeedGeneration::query()
            ->where('shop_id', $shop->id)
            ->where('release_status', $releaseStatus)
            ->latest('id')
            ->first();
    }

    /**
     * @param  list<string>  $codes
     */
    private function validationCount(?int $feedProfileId, array $codes): int
    {
        if ($feedProfileId === null) {
            return 0;
        }

        return ValidationError::query()
            ->where('feed_profile_id', $feedProfileId)
            ->where('is_active', true)
            ->whereIn('code', $codes)
            ->count();
    }

    /**
     * @param  list<string>  $statuses
     */
    private function feedItemCount(?int $feedProfileId, array $statuses): int
    {
        if ($feedProfileId === null) {
            return 0;
        }

        return FeedItem::query()
            ->where('feed_profile_id', $feedProfileId)
            ->whereIn('status', $statuses)
            ->count();
    }
}
