<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\Ops\OpsSilenceWindowRequest;
use App\Models\FeedProfile;
use App\Services\Governance\ApprovalPolicyService;
use App\Services\Governance\GovernedActionService;
use App\Services\Ops\SilenceWindowService;
use Illuminate\Http\RedirectResponse;
use Throwable;

class OpsSilenceWindowController extends AdminController
{
    public function store(
        OpsSilenceWindowRequest $request,
        FeedProfile $feedProfile,
        SilenceWindowService $service,
        GovernedActionService $governedActionService
    ): RedirectResponse {
        $this->ensureShopOwned($request, $feedProfile);
        $severity = (string) ($request->validated('severity') ?? 'critical');

        try {
            if ($severity === 'critical') {
                $result = $this->dispatchGovernedAction(
                    $request,
                    $governedActionService,
                    ApprovalPolicyService::ACTION_SILENCE_CRITICAL,
                    $feedProfile,
                    [
                        'feed_profile_id' => $feedProfile->id,
                        'from' => $request->validated('from'),
                        'to' => $request->validated('to'),
                        'severity' => $severity,
                        'reason' => (string) $request->validated('reason'),
                    ],
                    [
                        'feed_profile_id' => $feedProfile->id,
                        'severity' => $severity,
                        'from' => $request->validated('from'),
                        'to' => $request->validated('to'),
                    ],
                    (string) $request->validated('reason'),
                    targetLabel: $feedProfile->code
                );

                if ($result->status !== 'executed') {
                    return back()->with($this->governedFlashKey($result), $result->message ?: 'Approval workflow started for critical silence window.');
                }
            } else {
                $service->start(
                    $feedProfile,
                    $service->parse($request->validated('from'), $feedProfile),
                    $service->parse($request->validated('to'), $feedProfile),
                    $severity,
                    (string) $request->validated('reason'),
                    $request->user()
                );
            }
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
