<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\FeedReleases\FeedFreezeRequest;
use App\Models\FeedProfile;
use App\Services\Feeds\FeedPublishWindowService;
use Illuminate\Http\RedirectResponse;
use Throwable;

class FeedFreezeController extends AdminController
{
    public function store(
        FeedFreezeRequest $request,
        FeedProfile $feedProfile,
        FeedPublishWindowService $publishWindowService
    ): RedirectResponse {
        $this->ensureShopOwned($request, $feedProfile);

        try {
            $publishWindowService->setFreezeMode(
                $feedProfile,
                $request->boolean('freeze'),
                (string) $request->validated('reason'),
                $request->user()
            );
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', $request->boolean('freeze') ? 'Freeze mode enabled.' : 'Freeze mode disabled.');
    }
}
