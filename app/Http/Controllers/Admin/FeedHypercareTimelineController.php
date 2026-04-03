<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\Ops\LiveTimelineFilterRequest;
use App\Models\FeedProfile;
use App\Services\Feeds\FeedLiveTimelineService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FeedHypercareTimelineController extends AdminController
{
    public function show(
        LiveTimelineFilterRequest $request,
        FeedProfile $feedProfile,
        FeedLiveTimelineService $service
    ): View {
        $this->ensureShopOwned($request, $feedProfile);

        return view('admin.feed-hypercare.timeline', [
            'feedProfile' => $feedProfile,
            'timeline' => $service->events($feedProfile, $request->validated()),
            'filters' => $request->validated(),
        ]);
    }

    public function download(
        LiveTimelineFilterRequest $request,
        FeedProfile $feedProfile,
        FeedLiveTimelineService $service
    ): StreamedResponse {
        $this->ensureShopOwned($request, $feedProfile);
        $csv = $service->csv($feedProfile, $request->validated());

        return response()->streamDownload(
            static function () use ($csv): void {
                echo $csv;
            },
            'feed-profile-'.Str::slug($feedProfile->code ?: (string) $feedProfile->id).'-timeline.csv',
            ['Content-Type' => 'text/csv']
        );
    }
}
