<?php

namespace App\Http\Controllers\Admin;

use App\Models\FeedGeneration;
use App\Models\FeedProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FeedGenerationArtifactController extends AdminController
{
    public function preview(Request $request, FeedProfile $feedProfile, FeedGeneration $feedGeneration)
    {
        $this->ensureShopOwned($request, $feedProfile);
        abort_unless($feedGeneration->feed_profile_id === $feedProfile->id, 404);
        abort_if(blank($feedGeneration->file_path), 404);

        return response(
            Storage::disk(config('feed_mediator.storage_disk'))->get($feedGeneration->file_path),
            200,
            ['Content-Type' => 'application/xml; charset=UTF-8']
        );
    }

    public function download(Request $request, FeedProfile $feedProfile, FeedGeneration $feedGeneration): StreamedResponse
    {
        $this->ensureShopOwned($request, $feedProfile);
        abort_unless($feedGeneration->feed_profile_id === $feedProfile->id, 404);
        abort_if(blank($feedGeneration->file_path), 404);

        return Storage::disk(config('feed_mediator.storage_disk'))->download(
            $feedGeneration->file_path,
            'generation-'.$feedGeneration->id.'-candidate.xml',
            ['Content-Type' => 'application/xml; charset=UTF-8']
        );
    }
}
