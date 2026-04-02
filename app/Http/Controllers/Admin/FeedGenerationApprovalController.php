<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\FeedReleases\GenerationReasonRequest;
use App\Models\FeedGeneration;
use App\Models\FeedProfile;
use App\Services\Feeds\FeedReleaseService;
use Illuminate\Http\RedirectResponse;
use Throwable;

class FeedGenerationApprovalController extends AdminController
{
    public function store(
        GenerationReasonRequest $request,
        FeedProfile $feedProfile,
        FeedGeneration $feedGeneration,
        FeedReleaseService $releaseService
    ): RedirectResponse {
        $this->ensureShopOwned($request, $feedProfile);
        abort_unless($feedGeneration->feed_profile_id === $feedProfile->id, 404);

        try {
            $releaseService->approve($feedGeneration, $request->user(), $request->validated('reason'));
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Generation approved for release.');
    }
}
