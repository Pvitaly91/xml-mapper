<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\FeedReleases\FeedRollbackRequest;
use App\Models\FeedGeneration;
use App\Models\FeedProfile;
use App\Services\Feeds\FeedReleaseService;
use Illuminate\Http\RedirectResponse;
use Throwable;

class FeedRollbackController extends AdminController
{
    public function store(
        FeedRollbackRequest $request,
        FeedProfile $feedProfile,
        FeedReleaseService $releaseService
    ): RedirectResponse {
        $this->ensureShopOwned($request, $feedProfile);

        try {
            $targetGeneration = $request->integer('to_generation_id')
                ? FeedGeneration::query()
                    ->where('feed_profile_id', $feedProfile->id)
                    ->findOrFail($request->integer('to_generation_id'))
                : null;

            $rolledBack = $releaseService->rollback(
                $feedProfile,
                $targetGeneration,
                (string) $request->validated('reason'),
                $request->user()
            );
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Feed rolled back to generation #'.$rolledBack->id.'.');
    }
}
