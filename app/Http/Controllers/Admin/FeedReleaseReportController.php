<?php

namespace App\Http\Controllers\Admin;

use App\Models\FeedGeneration;
use App\Models\FeedProfile;
use App\Services\Feeds\FeedReleaseReportService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FeedReleaseReportController extends AdminController
{
    public function invalidItems(Request $request, FeedProfile $feedProfile, FeedReleaseReportService $reportService): StreamedResponse
    {
        $this->ensureShopOwned($request, $feedProfile);

        $generation = $request->integer('generation_id')
            ? FeedGeneration::query()->where('feed_profile_id', $feedProfile->id)->findOrFail($request->integer('generation_id'))
            : $feedProfile->latestGeneration;
        $format = $request->string('format')->toString() ?: 'csv';

        if ($format === 'json') {
            $payload = json_encode($reportService->invalidItemsReport($feedProfile, $generation), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

            return response()->streamDownload(
                static fn () => print($payload),
                'feed-profile-'.$feedProfile->id.'-invalid-items.json',
                ['Content-Type' => 'application/json; charset=UTF-8']
            );
        }

        $csv = $reportService->invalidItemsCsv($feedProfile, $generation);

        return response()->streamDownload(
            static fn () => print($csv),
            'feed-profile-'.$feedProfile->id.'-invalid-items.csv',
            ['Content-Type' => 'text/csv; charset=UTF-8']
        );
    }

    public function diff(
        Request $request,
        FeedProfile $feedProfile,
        FeedGeneration $feedGeneration,
        FeedReleaseReportService $reportService
    ): StreamedResponse {
        $this->ensureShopOwned($request, $feedProfile);
        abort_unless($feedGeneration->feed_profile_id === $feedProfile->id, 404);

        $payload = json_encode($reportService->generationDiffReport($feedProfile, $feedGeneration), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return response()->streamDownload(
            static fn () => print($payload),
            'generation-'.$feedGeneration->id.'-diff.json',
            ['Content-Type' => 'application/json; charset=UTF-8']
        );
    }

    public function readiness(
        Request $request,
        FeedProfile $feedProfile,
        FeedGeneration $feedGeneration,
        FeedReleaseReportService $reportService
    ): StreamedResponse {
        $this->ensureShopOwned($request, $feedProfile);
        abort_unless($feedGeneration->feed_profile_id === $feedProfile->id, 404);

        $payload = json_encode($reportService->readinessReport($feedProfile, $feedGeneration), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return response()->streamDownload(
            static fn () => print($payload),
            'generation-'.$feedGeneration->id.'-readiness.json',
            ['Content-Type' => 'application/json; charset=UTF-8']
        );
    }
}
