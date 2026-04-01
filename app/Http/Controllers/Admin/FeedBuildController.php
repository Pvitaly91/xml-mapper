<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\BuildFeedJob;
use App\Models\FeedProfile;
use Illuminate\Http\JsonResponse;

class FeedBuildController extends Controller
{
    public function store(int $id): JsonResponse
    {
        $feedProfile = FeedProfile::findOrFail($id);

        BuildFeedJob::dispatch($feedProfile->id, true);

        return response()->json([
            'status' => 'queued',
            'feed_profile_id' => $feedProfile->id,
        ], 202);
    }
}
