<?php

namespace App\Http\Controllers\Admin;

use App\Models\FeedGeneration;
use App\Models\FeedProfile;
use App\Services\Feeds\FeedReleaseReadinessService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FeedReleaseCenterController extends AdminController
{
    public function show(Request $request, FeedProfile $feedProfile, FeedReleaseReadinessService $readinessService): View
    {
        $this->ensureShopOwned($request, $feedProfile);

        $feedProfile->load(['sourceConnection.latestImport', 'publishedGeneration', 'latestGeneration']);
        $generations = $feedProfile->generations()
            ->with(['approvedBy'])
            ->latest('id')
            ->paginate(15)
            ->withQueryString();
        $latestGeneration = $feedProfile->latestGeneration;

        return view('admin.feed-releases.show', [
            'feedProfile' => $feedProfile,
            'generations' => $generations,
            'latestGeneration' => $latestGeneration,
            'latestReadiness' => $latestGeneration instanceof FeedGeneration
                ? $readinessService->evaluate($feedProfile, $latestGeneration)
                : null,
            'recentReleaseEvents' => $feedProfile->releaseEvents()
                ->with(['user', 'feedGeneration'])
                ->latest('occurred_at')
                ->limit(12)
                ->get(),
            'publicFeedUrl' => $feedProfile->published_path ? route('feeds.public', $feedProfile->public_token) : null,
        ]);
    }
}
