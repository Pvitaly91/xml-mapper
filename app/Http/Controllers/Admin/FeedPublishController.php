<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\FeedProfiles\PublishFeedProfileAction;
use App\Http\Requests\Admin\FeedReleases\FeedPublishRequest;
use App\Models\FeedGeneration;
use App\Models\FeedProfile;
use App\Services\Governance\ApprovalPolicyService;
use App\Services\Governance\GovernedActionService;
use Illuminate\Http\RedirectResponse;
use Throwable;

class FeedPublishController extends AdminController
{
    public function store(
        FeedPublishRequest $request,
        FeedProfile $feedProfile,
        PublishFeedProfileAction $action,
        GovernedActionService $governedActionService
    ): RedirectResponse
    {
        $this->ensureShopOwned($request, $feedProfile);

        try {
            $generation = $request->integer('generation_id')
                ? FeedGeneration::query()->where('feed_profile_id', $feedProfile->id)->findOrFail($request->integer('generation_id'))
                : null;

            if ($request->boolean('force_publish')) {
                $result = $this->dispatchGovernedAction(
                    $request,
                    $governedActionService,
                    ApprovalPolicyService::ACTION_RELEASE_FORCE_PUBLISH,
                    $feedProfile,
                    [
                        'feed_profile_id' => $feedProfile->id,
                        'generation_id' => $generation?->id,
                        'reason' => (string) $request->validated('reason'),
                    ],
                    [
                        'feed_profile_id' => $feedProfile->id,
                        'generation_id' => $generation?->id,
                        'force_publish' => true,
                    ],
                    (string) $request->validated('reason'),
                    targetLabel: $feedProfile->code
                );

                return back()->with(
                    $this->governedFlashKey($result),
                    $result->status === 'executed'
                        ? 'Feed force-published from generation #'.($result->execution['generation_id'] ?? $generation?->id ?? 'n/a').'.'
                        : ($result->message ?: 'Approval workflow started for force publish.')
                );
            }

            $published = $action->handle($feedProfile, $generation, false, $request->validated('reason'), $request->user());
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Feed published from generation #'.$published->id.'.');
    }
}
