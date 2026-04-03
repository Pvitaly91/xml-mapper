<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\FeedReleases\RestoreDrillRequest;
use App\Models\FeedProfile;
use App\Models\OpsRun;
use App\Services\Ops\RestoreDrillService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class FeedRestoreDrillController extends AdminController
{
    public function store(
        RestoreDrillRequest $request,
        FeedProfile $feedProfile,
        RestoreDrillService $service
    ): RedirectResponse {
        $this->ensureShopOwned($request, $feedProfile);

        try {
            $result = $service->run($feedProfile, $request->user(), $request->validated('note'));
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Restore drill recorded with status '.$result['run']->status.'.');
    }

    public function show(Request $request, FeedProfile $feedProfile, OpsRun $opsRun): BinaryFileResponse
    {
        $this->ensureShopOwned($request, $feedProfile);
        abort_unless($opsRun->feed_profile_id === $feedProfile->id, 404);
        abort_unless($opsRun->type === OpsRun::TYPE_RESTORE_DRILL, 404);
        abort_unless(filled($opsRun->artifact_path), 404);

        return response()->download(
            \Storage::disk(config('feed_mediator.storage_disk'))->path($opsRun->artifact_path),
            'restore-drill-'.$opsRun->id.'.md'
        );
    }
}
