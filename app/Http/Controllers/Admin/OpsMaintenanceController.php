<?php

namespace App\Http\Controllers\Admin;

use App\Models\FeedProfile;
use App\Models\OpsRun;
use App\Services\Governance\ApprovalPolicyService;
use App\Services\Governance\GovernedActionService;
use App\Services\Ops\BackupService;
use App\Services\Ops\BenchmarkService;
use App\Services\Ops\ProductionPreflightService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

class OpsMaintenanceController extends AdminController
{
    public function preflight(Request $request, ProductionPreflightService $service): RedirectResponse
    {
        try {
            $result = $service->run($request->user());
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with(
            $result['status'] === OpsRun::STATUS_FAILED ? 'error' : 'status',
            'Production preflight finished with status '.$result['status'].'.'
        );
    }

    public function backupDb(Request $request, BackupService $service): RedirectResponse
    {
        try {
            $result = $service->backupDatabase($request->user());
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Database backup created: '.$result['path']);
    }

    public function backupFiles(Request $request, BackupService $service): RedirectResponse
    {
        try {
            $result = $service->backupFiles($request->user());
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Files backup created: '.$result['path']);
    }

    public function prune(Request $request, GovernedActionService $governedActionService): RedirectResponse
    {
        try {
            $result = $this->dispatchGovernedAction(
                $request,
                $governedActionService,
                ApprovalPolicyService::ACTION_PRUNE,
                null,
                ['requested_by' => $request->user()?->id],
                ['action' => 'ops.prune'],
                'Ops prune requested from admin maintenance panel.'
            );
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with(
            $this->governedFlashKey($result),
            $result->status === 'executed'
                ? 'Prune finished with '.count((array) ($result->execution['summary'] ?? [])).' retention counters updated.'
                : ($result->message ?: 'Approval workflow started for prune.')
        );
    }

    public function benchmark(Request $request, FeedProfile $feedProfile, BenchmarkService $service): RedirectResponse
    {
        $this->ensureShopOwned($request, $feedProfile);

        try {
            $result = $service->run($feedProfile, $request->user());
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Benchmark finished. Peak memory: '.$result['summary']['peak_memory_mb'].' MB.');
    }
}
