<?php

namespace App\Http\Controllers\Admin;

use App\Models\FeedGeneration;
use App\Models\FeedProfile;
use App\Services\Feeds\FeedQaBundleService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class FeedQaBundleController extends AdminController
{
    public function show(
        Request $request,
        FeedProfile $feedProfile,
        FeedGeneration $feedGeneration,
        FeedQaBundleService $bundleService
    ): BinaryFileResponse {
        $this->ensureShopOwned($request, $feedProfile);
        abort_unless($feedGeneration->feed_profile_id === $feedProfile->id, 404);

        $bundle = $bundleService->generate($feedGeneration, $request->user(), $request->string('reason')->toString() ?: null);

        return response()->download($bundle['absolute_path'], $bundle['filename']);
    }
}
