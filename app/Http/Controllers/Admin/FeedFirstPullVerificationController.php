<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\FeedReleases\GenerationReasonRequest;
use App\Models\FeedGeneration;
use App\Models\FeedProfile;
use App\Services\Feeds\FeedFirstPullVerificationService;
use Illuminate\Http\RedirectResponse;
use Throwable;

class FeedFirstPullVerificationController extends AdminController
{
    public function store(
        GenerationReasonRequest $request,
        FeedProfile $feedProfile,
        FeedGeneration $feedGeneration,
        FeedFirstPullVerificationService $service
    ): RedirectResponse {
        $this->ensureShopOwned($request, $feedProfile);
        abort_unless($feedGeneration->feed_profile_id === $feedProfile->id, 404);

        try {
            $verification = $service->run(
                $feedProfile,
                $feedGeneration,
                'manual',
                $request->user(),
                $request->validated('reason')
            );
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'First-pull verification finished with status '.$verification->status.'.');
    }
}
