<?php

namespace App\Http\Controllers\Admin;

use App\Models\FeedGeneration;
use App\Models\FeedProfile;
use App\Services\Feeds\FeedRunbookService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class FeedRunbookController extends AdminController
{
    public function show(Request $request, FeedProfile $feedProfile, FeedRunbookService $service): BinaryFileResponse
    {
        $this->ensureShopOwned($request, $feedProfile);
        $generation = $request->integer('generation_id')
            ? FeedGeneration::query()->where('feed_profile_id', $feedProfile->id)->findOrFail($request->integer('generation_id'))
            : $feedProfile->latestGeneration;
        $runbook = $service->generate($feedProfile, $generation);

        return response()->download($runbook['absolute_path'], $runbook['filename']);
    }
}
