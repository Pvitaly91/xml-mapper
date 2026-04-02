<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\FeedProfiles\ToggleFeedProfileStatusAction;
use App\Models\FeedProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FeedProfileStatusController extends AdminController
{
    public function store(Request $request, FeedProfile $feedProfile, ToggleFeedProfileStatusAction $action): RedirectResponse
    {
        $this->ensureShopOwned($request, $feedProfile);

        $payload = $request->validate([
            'status' => ['required', Rule::in([FeedProfile::STATUS_ACTIVE, FeedProfile::STATUS_INACTIVE, FeedProfile::STATUS_DRAFT])],
        ]);

        $action->handle($feedProfile, $payload['status']);

        return back()->with('status', 'Feed profile status updated.');
    }
}
