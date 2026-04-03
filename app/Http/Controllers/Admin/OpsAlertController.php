<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\Ops\OpsAlertActionRequest;
use App\Models\FeedProfile;
use App\Models\OpsAlert;
use App\Services\Ops\OpsAlertService;
use Illuminate\Http\RedirectResponse;
use Throwable;

class OpsAlertController extends AdminController
{
    public function acknowledge(
        OpsAlertActionRequest $request,
        FeedProfile $feedProfile,
        OpsAlert $opsAlert,
        OpsAlertService $service
    ): RedirectResponse {
        $this->ensureShopOwned($request, $feedProfile);
        abort_unless($opsAlert->feed_profile_id === $feedProfile->id, 404);

        try {
            $service->acknowledge(
                $opsAlert,
                (string) $request->validated('reason'),
                $request->validated('note'),
                $request->user()
            );
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Alert acknowledged.');
    }

    public function resolve(
        OpsAlertActionRequest $request,
        FeedProfile $feedProfile,
        OpsAlert $opsAlert,
        OpsAlertService $service
    ): RedirectResponse {
        $this->ensureShopOwned($request, $feedProfile);
        abort_unless($opsAlert->feed_profile_id === $feedProfile->id, 404);

        try {
            $service->resolve(
                $opsAlert,
                (string) $request->validated('reason'),
                $request->validated('note'),
                $request->user()
            );
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Incident resolved.');
    }

    public function falsePositive(
        OpsAlertActionRequest $request,
        FeedProfile $feedProfile,
        OpsAlert $opsAlert,
        OpsAlertService $service
    ): RedirectResponse {
        $this->ensureShopOwned($request, $feedProfile);
        abort_unless($opsAlert->feed_profile_id === $feedProfile->id, 404);

        try {
            $service->resolve(
                $opsAlert,
                (string) $request->validated('reason'),
                $request->validated('note'),
                $request->user(),
                true
            );
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Incident marked as false positive.');
    }
}
