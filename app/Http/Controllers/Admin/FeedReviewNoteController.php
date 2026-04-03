<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\FeedReleases\FeedReviewNoteRequest;
use App\Models\FeedGeneration;
use App\Models\FeedProfile;
use App\Services\Feeds\FeedReleaseNotesService;
use Illuminate\Http\RedirectResponse;
use Throwable;

class FeedReviewNoteController extends AdminController
{
    public function store(
        FeedReviewNoteRequest $request,
        FeedProfile $feedProfile,
        FeedGeneration $feedGeneration,
        FeedReleaseNotesService $notesService
    ): RedirectResponse {
        $this->ensureShopOwned($request, $feedProfile);
        abort_unless($feedGeneration->feed_profile_id === $feedProfile->id, 404);

        try {
            $notesService->add(
                $feedGeneration,
                (string) $request->validated('body'),
                (string) $request->validated('note_type'),
                $request->boolean('important'),
                $request->user()
            );
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Review note saved.');
    }
}
