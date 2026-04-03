<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\Ops\OpsSilenceWindowRequest;
use App\Models\FeedProfile;
use App\Services\Ops\SilenceWindowService;
use Illuminate\Http\RedirectResponse;
use Throwable;

class OpsSilenceWindowController extends AdminController
{
    public function store(
        OpsSilenceWindowRequest $request,
        FeedProfile $feedProfile,
        SilenceWindowService $service
    ): RedirectResponse {
        $this->ensureShopOwned($request, $feedProfile);

        try {
            $service->start(
                $feedProfile,
                $service->parse($request->validated('from'), $feedProfile),
                $service->parse($request->validated('to'), $feedProfile),
                (string) ($request->validated('severity') ?? 'critical'),
                (string) $request->validated('reason'),
                $request->user()
            );
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Silence window saved.');
    }

    public function clear(OpsSilenceWindowRequest $request, FeedProfile $feedProfile, SilenceWindowService $service): RedirectResponse
    {
        $this->ensureShopOwned($request, $feedProfile);

        try {
            $service->clear($feedProfile, $request->user(), (string) $request->validated('reason'));
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Silence window cleared.');
    }
}
