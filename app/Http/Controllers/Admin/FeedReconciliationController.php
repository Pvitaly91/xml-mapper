<?php

namespace App\Http\Controllers\Admin;

use App\Models\FeedGeneration;
use App\Models\FeedProfile;
use App\Services\Feeds\FeedReconciliationService;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FeedReconciliationController extends AdminController
{
    public function show(Request $request, FeedProfile $feedProfile, FeedReconciliationService $service): View
    {
        $this->ensureShopOwned($request, $feedProfile);
        $generation = $request->integer('generation_id')
            ? FeedGeneration::query()->where('feed_profile_id', $feedProfile->id)->findOrFail($request->integer('generation_id'))
            : $feedProfile->latestGeneration;

        return view('admin.feed-reconciliation.show', [
            'feedProfile' => $feedProfile,
            'generation' => $generation,
            'report' => $service->summarize($feedProfile, $generation, $request->only('blocker')),
            'filters' => $request->only('blocker'),
        ]);
    }

    public function download(Request $request, FeedProfile $feedProfile, FeedReconciliationService $service): StreamedResponse
    {
        $this->ensureShopOwned($request, $feedProfile);
        $generation = $request->integer('generation_id')
            ? FeedGeneration::query()->where('feed_profile_id', $feedProfile->id)->findOrFail($request->integer('generation_id'))
            : $feedProfile->latestGeneration;
        $format = $request->string('format')->toString() ?: 'json';

        if ($format === 'csv') {
            $csv = $service->csvReport($feedProfile, $generation);

            return response()->streamDownload(
                static fn () => print ($csv),
                'feed-profile-'.$feedProfile->id.'-reconciliation.csv',
                ['Content-Type' => 'text/csv; charset=UTF-8']
            );
        }

        $payload = $service->jsonReport($feedProfile, $generation);

        return response()->streamDownload(
            static fn () => print ($payload),
            'feed-profile-'.$feedProfile->id.'-reconciliation.json',
            ['Content-Type' => 'application/json; charset=UTF-8']
        );
    }
}
