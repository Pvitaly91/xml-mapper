<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\FeedReleases\FeedPreviewLinkRequest;
use App\Http\Requests\Admin\FeedReleases\GenerationReasonRequest;
use App\Models\FeedGeneration;
use App\Models\FeedGenerationPreviewLink;
use App\Models\FeedGenerationSmokeCheck;
use App\Models\FeedProfile;
use App\Services\Feeds\FeedPreviewLinkService;
use App\Services\Feeds\FeedSmokeCheckService;
use Illuminate\Http\RedirectResponse;
use Throwable;

class FeedGenerationPreviewLinkController extends AdminController
{
    public function store(
        FeedPreviewLinkRequest $request,
        FeedProfile $feedProfile,
        FeedGeneration $feedGeneration,
        FeedPreviewLinkService $previewLinkService
    ): RedirectResponse {
        $this->ensureShopOwned($request, $feedProfile);
        abort_unless($feedGeneration->feed_profile_id === $feedProfile->id, 404);

        try {
            $previewLink = $previewLinkService->create(
                $feedGeneration,
                (int) ($request->validated('ttl_minutes') ?? 1440),
                $request->user(),
                $request->validated('reason')
            );
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Preview link created: '.$previewLinkService->urlFor($previewLink));
    }

    public function revoke(
        GenerationReasonRequest $request,
        FeedProfile $feedProfile,
        FeedGeneration $feedGeneration,
        FeedGenerationPreviewLink $feedGenerationPreviewLink,
        FeedPreviewLinkService $previewLinkService
    ): RedirectResponse {
        $this->ensureShopOwned($request, $feedProfile);
        abort_unless($feedGeneration->feed_profile_id === $feedProfile->id, 404);
        abort_unless($feedGenerationPreviewLink->feed_generation_id === $feedGeneration->id, 404);

        try {
            $previewLinkService->revoke($feedGenerationPreviewLink, $request->user(), $request->validated('reason'));
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Preview link revoked.');
    }

    public function smokeCheck(
        GenerationReasonRequest $request,
        FeedProfile $feedProfile,
        FeedGeneration $feedGeneration,
        FeedGenerationPreviewLink $feedGenerationPreviewLink,
        FeedSmokeCheckService $smokeCheckService
    ): RedirectResponse {
        $this->ensureShopOwned($request, $feedProfile);
        abort_unless($feedGeneration->feed_profile_id === $feedProfile->id, 404);
        abort_unless($feedGenerationPreviewLink->feed_generation_id === $feedGeneration->id, 404);

        try {
            $smokeCheck = $smokeCheckService->runPreview(
                $feedGenerationPreviewLink,
                FeedGenerationSmokeCheck::TRIGGER_MANUAL,
                $request->user(),
                $request->validated('reason')
            );
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Preview smoke check finished with status '.$smokeCheck->status.'.');
    }
}
