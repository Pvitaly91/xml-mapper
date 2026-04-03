<?php

namespace App\Http\Controllers\Admin;

use App\Models\FeedGeneration;
use App\Models\FeedProfile;
use App\Services\Feeds\FeedAcceptanceService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FeedAcceptanceController extends AdminController
{
    public function show(Request $request, FeedProfile $feedProfile, FeedAcceptanceService $acceptanceService): View
    {
        $this->ensureShopOwned($request, $feedProfile);
        $generation = $request->integer('generation_id')
            ? FeedGeneration::query()->where('feed_profile_id', $feedProfile->id)->findOrFail($request->integer('generation_id'))
            : null;

        return view('admin.feed-acceptance.show', $acceptanceService->summarize($feedProfile, $generation));
    }
}
