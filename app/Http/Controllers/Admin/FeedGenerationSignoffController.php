<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\FeedReleases\FeedSignoffRequest;
use App\Models\FeedGeneration;
use App\Models\FeedProfile;
use App\Services\Feeds\FeedSignoffService;
use Illuminate\Http\RedirectResponse;
use Throwable;

class FeedGenerationSignoffController extends AdminController
{
    public function store(
        FeedSignoffRequest $request,
        FeedProfile $feedProfile,
        FeedGeneration $feedGeneration,
        FeedSignoffService $signoffService
    ): RedirectResponse {
        $this->ensureShopOwned($request, $feedProfile);
        abort_unless($feedGeneration->feed_profile_id === $feedProfile->id, 404);

        try {
            $signoff = $signoffService->record(
                $feedGeneration,
                (string) $request->validated('status'),
                $request->user(),
                $request->validated('reviewer_name'),
                $request->validated('note'),
                $request->validated('reason')
            );
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Sign-off recorded with status '.$signoff->status.'.');
    }
}
