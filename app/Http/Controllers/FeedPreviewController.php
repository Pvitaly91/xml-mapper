<?php

namespace App\Http\Controllers;

use App\Models\FeedGenerationPreviewLink;
use App\Services\Feeds\FeedPreviewLinkService;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

class FeedPreviewController extends Controller
{
    public function show(
        FeedGenerationPreviewLink $previewLink,
        string $token,
        FeedPreviewLinkService $previewLinkService
    ): Response {
        abort_unless($previewLink->isActive(), 403);
        abort_unless(hash_equals((string) $previewLink->token, $token), 404);

        $generation = $previewLink->feedGeneration;
        abort_if(blank($generation?->file_path), 404);

        $disk = Storage::disk(config('feed_mediator.storage_disk'));
        abort_unless($disk->exists($generation->file_path), 404);

        $previewLinkService->markAccessed($previewLink);

        return response($disk->get($generation->file_path), 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'private, no-store, no-cache, must-revalidate',
        ]);
    }
}
