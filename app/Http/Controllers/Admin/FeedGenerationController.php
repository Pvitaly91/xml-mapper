<?php

namespace App\Http\Controllers\Admin;

use App\Models\FeedGeneration;
use App\Models\FeedProfile;
use App\Services\Feeds\FeedPreviewLinkService;
use App\Services\Feeds\FeedReleaseReadinessService;
use App\Services\Feeds\FeedReleaseReportService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FeedGenerationController extends AdminController
{
    public function show(
        Request $request,
        FeedProfile $feedProfile,
        FeedGeneration $feedGeneration,
        FeedPreviewLinkService $previewLinkService,
        FeedReleaseReadinessService $readinessService,
        FeedReleaseReportService $reportService,
    ): View {
        $this->ensureShopOwned($request, $feedProfile);
        abort_unless($feedGeneration->feed_profile_id === $feedProfile->id, 404);

        $feedProfile->load(['publishedGeneration', 'sourceConnection.latestImport']);
        $feedGeneration->load(['approvedBy', 'smokeChecks.user', 'previewLinks', 'signoffs.user']);

        return view('admin.feed-generations.show', [
            'feedProfile' => $feedProfile,
            'generation' => $feedGeneration,
            'diffReport' => $reportService->generationDiffReport($feedProfile, $feedGeneration),
            'readiness' => $readinessService->evaluate($feedProfile, $feedGeneration),
            'functionalReport' => $reportService->functionalXmlReport($feedProfile, $feedGeneration),
            'latestSmokeCheck' => $feedGeneration->smokeChecks()->latest('checked_at')->first(),
            'releaseEvents' => $feedGeneration->releaseEvents()
                ->with('user')
                ->latest('occurred_at')
                ->limit(20)
                ->get(),
            'publicFeedUrl' => $feedProfile->published_path ? route('feeds.public', $feedProfile->public_token) : null,
            'previewUrls' => $feedGeneration->previewLinks->mapWithKeys(fn ($previewLink) => [$previewLink->id => $previewLink->isActive() ? $previewLinkService->urlFor($previewLink) : null]),
        ]);
    }
}
