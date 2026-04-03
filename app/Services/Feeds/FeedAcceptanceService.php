<?php

namespace App\Services\Feeds;

use App\Models\FeedGeneration;
use App\Models\FeedProfile;
use App\Models\ValidationError;
use App\Services\Shops\ShopOnboardingService;

class FeedAcceptanceService
{
    public function __construct(
        private readonly ShopOnboardingService $onboardingService,
        private readonly FeedPilotReadinessService $pilotReadinessService,
        private readonly FeedReleaseReadinessService $releaseReadinessService,
        private readonly FeedSignoffService $signoffService,
        private readonly FeedPublishWindowService $publishWindowService,
        private readonly FeedReleaseNotesService $notesService,
        private readonly FeedPreviewLinkService $previewLinkService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function summarize(FeedProfile $feedProfile, ?FeedGeneration $generation = null): array
    {
        $feedProfile->loadMissing(['shop.users', 'sourceConnection.latestImport', 'publishedGeneration', 'latestGeneration']);
        $generation ??= $feedProfile->generations()
            ->whereIn('release_status', [
                FeedGeneration::RELEASE_STATUS_CANDIDATE,
                FeedGeneration::RELEASE_STATUS_APPROVED,
                FeedGeneration::RELEASE_STATUS_PUBLISHED,
                FeedGeneration::RELEASE_STATUS_BUILT,
            ])
            ->latest('id')
            ->first();
        $generation?->loadMissing(['smokeChecks.user', 'approvedBy', 'previewLinks', 'signoffs.user', 'releaseEvents.user']);
        $admin = $feedProfile->shop?->users()->where('role', 'admin')->oldest('id')->first();
        $pilotReadiness = $this->pilotReadinessService->summarize($feedProfile);
        $releaseReadiness = $generation ? $this->releaseReadinessService->evaluate($feedProfile, $generation) : null;
        $signoff = $generation ? $this->signoffService->evaluate($feedProfile, $generation) : null;
        $window = $this->publishWindowService->evaluate($feedProfile);
        $latestPublishedSmokeCheck = $feedProfile->publishedGeneration?->smokeChecks()->latest('checked_at')->first();
        $previewLinks = $generation
            ? $generation->previewLinks()
                ->latest('id')
                ->get()
                ->map(function ($previewLink): array {
                    return [
                        'model' => $previewLink,
                        'url' => $previewLink->isActive() ? $this->previewLinkService->urlFor($previewLink) : null,
                    ];
                })
            : collect();

        return [
            'feed_profile' => $feedProfile,
            'generation' => $generation,
            'onboarding' => $admin ? $this->onboardingService->summarize($admin) : null,
            'pilot_readiness' => $pilotReadiness,
            'release_readiness' => $releaseReadiness,
            'signoff' => $signoff,
            'publish_window' => $window,
            'preview_links' => $previewLinks,
            'latest_published_smoke_check' => $latestPublishedSmokeCheck,
            'notes' => $generation ? $this->notesService->notes($generation) : collect(),
            'unresolved_mappings_count' => ValidationError::query()
                ->where('feed_profile_id', $feedProfile->id)
                ->where('is_active', true)
                ->count(),
        ];
    }
}
