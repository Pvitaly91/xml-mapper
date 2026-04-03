<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\FeedReleases\FeedHypercareCloseRequest;
use App\Http\Requests\Admin\FeedReleases\FeedHypercareNoteRequest;
use App\Http\Requests\Admin\FeedReleases\FeedHypercareStartRequest;
use App\Models\FeedProfile;
use App\Services\Feeds\FeedHypercareDashboardService;
use App\Services\Feeds\FeedHypercareService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class FeedHypercareController extends AdminController
{
    public function show(Request $request, FeedProfile $feedProfile, FeedHypercareDashboardService $service): View
    {
        $this->ensureShopOwned($request, $feedProfile);

        return view('admin.feed-hypercare.show', [
            'feedProfile' => $feedProfile,
            'dashboard' => $service->summarize($feedProfile),
        ]);
    }

    public function start(
        FeedHypercareStartRequest $request,
        FeedProfile $feedProfile,
        FeedHypercareService $service
    ): RedirectResponse {
        $this->ensureShopOwned($request, $feedProfile);

        try {
            $service->start(
                $feedProfile->fresh(['publishedGeneration', 'latestGeneration', 'currentCutover']),
                (int) ($request->validated('hours') ?? config('feed_mediator.hypercare.default_hours', 24)),
                $request->validated('note'),
                $request->user()
            );
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('admin.feed-profiles.hypercare.show', $feedProfile)
            ->with('status', 'Hypercare window started.');
    }

    public function extend(
        FeedHypercareStartRequest $request,
        FeedProfile $feedProfile,
        FeedHypercareService $service
    ): RedirectResponse {
        $this->ensureShopOwned($request, $feedProfile);
        $window = $service->current($feedProfile);

        abort_unless($window !== null, 404);

        try {
            $service->extend(
                $window,
                (int) ($request->validated('hours') ?? config('feed_mediator.hypercare.default_hours', 24)),
                $request->validated('note'),
                $request->user()
            );
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Hypercare window extended.');
    }

    public function close(
        FeedHypercareCloseRequest $request,
        FeedProfile $feedProfile,
        FeedHypercareService $service
    ): RedirectResponse {
        $this->ensureShopOwned($request, $feedProfile);
        $window = $service->current($feedProfile);

        abort_unless($window !== null, 404);

        try {
            $result = $service->close($window, (string) $request->validated('reason'), $request->user());
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Hypercare closed. Closeout report: '.$result['report']['absolute_path']);
    }

    public function abort(
        FeedHypercareCloseRequest $request,
        FeedProfile $feedProfile,
        FeedHypercareService $service
    ): RedirectResponse {
        $this->ensureShopOwned($request, $feedProfile);
        $window = $service->current($feedProfile);

        abort_unless($window !== null, 404);

        try {
            $service->abort($window, (string) $request->validated('reason'), $request->user());
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Hypercare aborted.');
    }

    public function note(
        FeedHypercareNoteRequest $request,
        FeedProfile $feedProfile,
        FeedHypercareService $service
    ): RedirectResponse {
        $this->ensureShopOwned($request, $feedProfile);

        try {
            $service->addNote($feedProfile, (string) $request->validated('body'), $request->user());
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Operator note saved.');
    }
}
