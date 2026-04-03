<?php

namespace App\Http\Controllers\Admin;

use App\Models\FeedGeneration;
use App\Models\FeedProfile;
use App\Services\Feeds\FeedLaunchPackService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class FeedLaunchPackController extends AdminController
{
    public function show(Request $request, FeedProfile $feedProfile, FeedLaunchPackService $service): BinaryFileResponse
    {
        $this->ensureShopOwned($request, $feedProfile);
        $generation = $request->integer('generation_id')
            ? FeedGeneration::query()->where('feed_profile_id', $feedProfile->id)->findOrFail($request->integer('generation_id'))
            : null;
        $pack = $service->generate($feedProfile, $generation, $request->user());

        return response()->download($pack['absolute_path'], $pack['filename']);
    }
}
