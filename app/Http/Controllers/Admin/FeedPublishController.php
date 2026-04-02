<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\FeedProfiles\PublishFeedProfileAction;
use App\Models\FeedGeneration;
use App\Models\FeedProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

class FeedPublishController extends AdminController
{
    public function store(Request $request, FeedProfile $feedProfile, PublishFeedProfileAction $action): RedirectResponse
    {
        $this->ensureShopOwned($request, $feedProfile);

        try {
            $generation = $request->integer('generation_id')
                ? FeedGeneration::query()->where('feed_profile_id', $feedProfile->id)->findOrFail($request->integer('generation_id'))
                : null;

            $published = $action->handle($feedProfile, $generation);
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Feed published from generation #'.$published->id.'.');
    }
}
