<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\FeedReleases\FeedRehearsalRequest;
use App\Models\FeedProfile;
use App\Services\Feeds\FeedRehearsalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class FeedRehearsalController extends AdminController
{
    public function show(Request $request, FeedProfile $feedProfile, FeedRehearsalService $service): View
    {
        $this->ensureShopOwned($request, $feedProfile);

        return view('admin.feed-rehearsal.show', [
            'feedProfile' => $feedProfile,
            'rehearsal' => $service->summarize($feedProfile),
        ]);
    }

    public function store(
        FeedRehearsalRequest $request,
        FeedProfile $feedProfile,
        FeedRehearsalService $service
    ): RedirectResponse {
        $this->ensureShopOwned($request, $feedProfile);

        try {
            $result = $service->run($feedProfile, [
                'with_sync' => $request->boolean('with_sync'),
                'with_build' => $request->boolean('with_build'),
                'with_preview' => $request->boolean('with_preview', true),
                'with_smoke' => $request->boolean('with_smoke'),
                'with_rollback_check' => $request->boolean('with_rollback_check'),
            ], $request->user());
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('admin.feed-profiles.rehearsal.show', $feedProfile)
            ->with('status', 'Rehearsal finished with status '.$result['status'].'.');
    }
}
