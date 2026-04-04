<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\FeedReleases\FeedFreezeRequest;
use App\Models\FeedProfile;
use App\Services\Governance\ApprovalPolicyService;
use App\Services\Governance\GovernedActionService;
use Illuminate\Http\RedirectResponse;
use Throwable;

class FeedFreezeController extends AdminController
{
    public function store(
        FeedFreezeRequest $request,
        FeedProfile $feedProfile,
        GovernedActionService $governedActionService
    ): RedirectResponse {
        $this->ensureShopOwned($request, $feedProfile);

        try {
            $result = $this->dispatchGovernedAction(
                $request,
                $governedActionService,
                ApprovalPolicyService::ACTION_RELEASE_FREEZE,
                $feedProfile,
                [
                    'feed_profile_id' => $feedProfile->id,
                    'freeze' => $request->boolean('freeze'),
                    'reason' => (string) $request->validated('reason'),
                ],
                [
                    'feed_profile_id' => $feedProfile->id,
                    'freeze' => $request->boolean('freeze'),
                ],
                (string) $request->validated('reason'),
                targetLabel: $feedProfile->code
            );
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with(
            $this->governedFlashKey($result),
            $result->status === 'executed'
                ? ($request->boolean('freeze') ? 'Freeze mode enabled.' : 'Freeze mode disabled.')
                : ($result->message ?: 'Approval workflow started for freeze toggle.')
        );
    }
}
