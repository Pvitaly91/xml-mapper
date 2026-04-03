<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\FeedReleases\FeedCutoverRequest;
use App\Models\FeedGeneration;
use App\Models\FeedProfile;
use App\Services\Feeds\FeedCutoverService;
use Illuminate\Http\RedirectResponse;
use Throwable;

class FeedCutoverController extends AdminController
{
    public function store(
        FeedCutoverRequest $request,
        FeedProfile $feedProfile,
        FeedCutoverService $cutoverService
    ): RedirectResponse {
        $this->ensureShopOwned($request, $feedProfile);

        try {
            $generation = $request->integer('generation_id')
                ? FeedGeneration::query()->where('feed_profile_id', $feedProfile->id)->findOrFail($request->integer('generation_id'))
                : $feedProfile->latestGeneration;

            $cutover = $cutoverService->begin(
                $feedProfile,
                $generation,
                $request->user(),
                $request->validated('note'),
                $request->validated('planned_window_starts_at'),
                $request->validated('planned_window_ends_at'),
            );
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Cutover is now tracked in state '.$cutover->status.'.');
    }
}
