<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\Feedback\FeedbackImportRequest;
use App\Models\FeedGeneration;
use App\Models\FeedProfile;
use App\Services\Feeds\FeedbackImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class FeedbackImportController extends AdminController
{
    public function create(Request $request, FeedProfile $feedProfile): View
    {
        $this->ensureShopOwned($request, $feedProfile);

        return view('admin.feedback.import', [
            'feedProfile' => $feedProfile,
            'preview' => session('feedback_preview'),
        ]);
    }

    public function preview(
        FeedbackImportRequest $request,
        FeedProfile $feedProfile,
        FeedbackImportService $service
    ): RedirectResponse {
        $this->ensureShopOwned($request, $feedProfile);

        try {
            $generation = $request->integer('generation_id')
                ? FeedGeneration::query()->where('feed_profile_id', $feedProfile->id)->findOrFail($request->integer('generation_id'))
                : $feedProfile->publishedGeneration;
            $content = file_get_contents($request->file('file')->getRealPath());

            if ($content === false) {
                throw new \RuntimeException('Unable to read feedback file.');
            }

            $preview = $service->preview($feedProfile, $request->validated('format'), $content, $generation);
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('admin.feed-profiles.feedback.create', $feedProfile)
            ->with('feedback_preview', $preview)
            ->with('status', 'Feedback dry-run preview generated.');
    }

    public function store(
        FeedbackImportRequest $request,
        FeedProfile $feedProfile,
        FeedbackImportService $service
    ): RedirectResponse {
        $this->ensureShopOwned($request, $feedProfile);

        try {
            $generation = $request->integer('generation_id')
                ? FeedGeneration::query()->where('feed_profile_id', $feedProfile->id)->findOrFail($request->integer('generation_id'))
                : $feedProfile->publishedGeneration;
            $result = $service->importUploadedFile(
                $feedProfile,
                $request->validated('format'),
                $request->file('file'),
                $request->boolean('dry_run'),
                $request->user(),
                $generation
            );
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        if ($result['dry_run']) {
            return redirect()
                ->route('admin.feed-profiles.feedback.create', $feedProfile)
                ->with('feedback_preview', $result)
                ->with('status', 'Feedback dry-run preview generated.');
        }

        return redirect()
            ->route('admin.feed-profiles.feedback-workbench.index', $feedProfile)
            ->with('status', sprintf(
                'Feedback imported: matched %d, unmatched %d, rejected %d.',
                $result['summary']['matched'],
                $result['summary']['unmatched'],
                $result['summary']['rejected'],
            ));
    }
}
