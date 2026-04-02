<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\FeedProfiles\BuildFeedProfileAction;
use App\Models\FeedProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

class FeedBuildController extends AdminController
{
    public function store(Request $request, FeedProfile $feedProfile, BuildFeedProfileAction $action): RedirectResponse
    {
        $this->ensureShopOwned($request, $feedProfile);

        try {
            $generation = $action->handle($feedProfile);
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Feed built. Generation #'.$generation->id.' is ready.');
    }
}
