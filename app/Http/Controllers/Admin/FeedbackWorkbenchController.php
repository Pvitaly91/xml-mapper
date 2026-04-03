<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\Feedback\FeedbackResolutionRequest;
use App\Models\FeedProfile;
use App\Models\FeedbackRecord;
use App\Services\Feeds\FeedbackRemediationWorkbenchService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class FeedbackWorkbenchController extends AdminController
{
    public function index(Request $request, FeedProfile $feedProfile, FeedbackRemediationWorkbenchService $service): View
    {
        $this->ensureShopOwned($request, $feedProfile);

        return view('admin.feedback.workbench', [
            'feedProfile' => $feedProfile,
            'workbench' => $service->summarize($feedProfile, $request->only(['problem', 'status', 'resolution_status'])),
        ]);
    }

    public function update(
        FeedbackResolutionRequest $request,
        FeedProfile $feedProfile,
        FeedbackRecord $feedbackRecord,
        FeedbackRemediationWorkbenchService $service
    ): RedirectResponse {
        $this->ensureShopOwned($request, $feedProfile);
        abort_unless($feedbackRecord->feed_profile_id === $feedProfile->id, 404);

        try {
            $service->updateResolution(
                $feedbackRecord,
                $request->validated('resolution_status'),
                $request->validated('resolution_note'),
                $request->user()
            );
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Feedback remediation status updated.');
    }
}
