<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\FeedReleases\FeedRollbackRequest;
use App\Models\FeedGeneration;
use App\Models\FeedProfile;
use App\Services\Governance\ApprovalPolicyService;
use App\Services\Governance\GovernedActionService;
use Illuminate\Http\RedirectResponse;
use Throwable;

class FeedRollbackController extends AdminController
{
    public function store(
        FeedRollbackRequest $request,
        FeedProfile $feedProfile,
        GovernedActionService $governedActionService
    ): RedirectResponse {
        $this->ensureShopOwned($request, $feedProfile);

        try {
            $targetGeneration = $request->integer('to_generation_id')
                ? FeedGeneration::query()
                    ->where('feed_profile_id', $feedProfile->id)
                    ->findOrFail($request->integer('to_generation_id'))
                : null;

            $result = $this->dispatchGovernedAction(
                $request,
                $governedActionService,
                ApprovalPolicyService::ACTION_RELEASE_ROLLBACK,
                $feedProfile,
                [
                    'feed_profile_id' => $feedProfile->id,
                    'to_generation_id' => $targetGeneration?->id,
                    'reason' => (string) $request->validated('reason'),
                ],
                [
                    'feed_profile_id' => $feedProfile->id,
                    'to_generation_id' => $targetGeneration?->id,
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
                ? 'Feed rolled back to generation #'.($result->execution['generation_id'] ?? $targetGeneration?->id ?? 'n/a').'.'
                : ($result->message ?: 'Approval workflow started for rollback.')
        );
    }
}
