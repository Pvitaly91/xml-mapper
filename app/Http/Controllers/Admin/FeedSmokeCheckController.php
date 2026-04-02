<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\FeedReleases\FeedSmokeCheckRequest;
use App\Models\FeedGeneration;
use App\Models\FeedProfile;
use App\Models\FeedGenerationSmokeCheck;
use App\Services\Feeds\FeedSmokeCheckService;
use Illuminate\Http\RedirectResponse;
use Throwable;

class FeedSmokeCheckController extends AdminController
{
    public function store(
        FeedSmokeCheckRequest $request,
        FeedProfile $feedProfile,
        FeedGeneration $feedGeneration,
        FeedSmokeCheckService $smokeCheckService
    ): RedirectResponse {
        $this->ensureShopOwned($request, $feedProfile);
        abort_unless($feedGeneration->feed_profile_id === $feedProfile->id, 404);

        try {
            $smokeCheck = $smokeCheckService->run(
                $feedProfile,
                $feedGeneration,
                FeedGenerationSmokeCheck::TRIGGER_MANUAL,
                $request->user(),
                $request->validated('reason')
            );
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Smoke check finished with status '.$smokeCheck->status.'.');
    }
}
