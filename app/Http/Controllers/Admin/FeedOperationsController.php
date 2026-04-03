<?php

namespace App\Http\Controllers\Admin;

use App\Models\FeedProfile;
use App\Services\Feeds\FeedOperationsService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FeedOperationsController extends AdminController
{
    public function show(Request $request, FeedProfile $feedProfile, FeedOperationsService $service): View
    {
        $this->ensureShopOwned($request, $feedProfile);

        return view('admin.feed-operations.show', [
            'feedProfile' => $feedProfile,
            'operations' => $service->summarize($feedProfile),
        ]);
    }
}
