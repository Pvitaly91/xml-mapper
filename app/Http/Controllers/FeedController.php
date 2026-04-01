<?php

namespace App\Http\Controllers;

use App\Models\FeedProfile;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

class FeedController extends Controller
{
    public function show(string $token): Response
    {
        $feedProfile = FeedProfile::query()
            ->where('public_token', $token)
            ->where('status', FeedProfile::STATUS_ACTIVE)
            ->firstOrFail();

        abort_if(blank($feedProfile->published_path), 404);

        $disk = Storage::disk(config('feed_mediator.storage_disk'));
        abort_unless($disk->exists($feedProfile->published_path), 404);

        return response($disk->get($feedProfile->published_path), 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }
}
