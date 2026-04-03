<?php

namespace App\Http\Controllers\Admin;

use App\Models\FeedProfile;
use App\Services\Feeds\FeedHypercareReportService;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FeedHypercareReportController extends AdminController
{
    public function digest(Request $request, FeedProfile $feedProfile, FeedHypercareReportService $service): View|StreamedResponse
    {
        $this->ensureShopOwned($request, $feedProfile);
        $report = $service->dailyDigest($feedProfile, $request->query('date'));

        if ($request->boolean('download')) {
            return $this->download($report['content'], basename($report['path']));
        }

        return view('admin.feed-hypercare.report', [
            'feedProfile' => $feedProfile,
            'reportTitle' => 'Daily digest',
            'report' => $report,
        ]);
    }

    public function handoff(Request $request, FeedProfile $feedProfile, FeedHypercareReportService $service): View|StreamedResponse
    {
        $this->ensureShopOwned($request, $feedProfile);
        $report = $service->shiftHandoff($feedProfile);

        if ($request->boolean('download')) {
            return $this->download($report['content'], basename($report['path']));
        }

        return view('admin.feed-hypercare.report', [
            'feedProfile' => $feedProfile,
            'reportTitle' => 'Shift handoff',
            'report' => $report,
        ]);
    }

    private function download(string $content, string $filename): StreamedResponse
    {
        return response()->streamDownload(static function () use ($content): void {
            echo $content;
        }, $filename, ['Content-Type' => 'text/markdown; charset=UTF-8']);
    }
}
