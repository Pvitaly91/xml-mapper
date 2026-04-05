<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\Promotion\PromotionActionRequest;
use App\Http\Requests\Admin\Promotion\PromotionRollbackRequest;
use App\Http\Requests\Admin\Promotion\PromotionSnapshotGenerateRequest;
use App\Http\Requests\Admin\Promotion\PromotionSnapshotImportRequest;
use App\Models\FeedProfile;
use App\Models\PromotionRun;
use App\Models\PromotionSnapshot;
use App\Services\Governance\ApprovalPolicyService;
use App\Services\Governance\GovernedActionService;
use App\Services\Promotion\PromotionCenterService;
use App\Services\Promotion\PromotionReportService;
use App\Services\Promotion\PromotionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class FeedPromotionController extends AdminController
{
    public function show(Request $request, FeedProfile $feedProfile, PromotionCenterService $service): View
    {
        $this->ensureShopOwned($request, $feedProfile);

        return view('admin.feed-promotions.show', [
            'feedProfile' => $feedProfile,
            'center' => $service->summarize($feedProfile),
        ]);
    }

    public function snapshot(
        PromotionSnapshotGenerateRequest $request,
        FeedProfile $feedProfile,
        PromotionService $service
    ): RedirectResponse {
        $this->ensureShopOwned($request, $feedProfile);

        $service->generateSnapshot(
            $feedProfile,
            $request->user(),
            $request->validated('env'),
            $request->validated('label'),
            $request->validated('name')
        );

        return back()->with('status', 'Promotion snapshot generated.');
    }

    public function import(
        PromotionSnapshotImportRequest $request,
        FeedProfile $feedProfile,
        PromotionService $service
    ): RedirectResponse {
        $this->ensureShopOwned($request, $feedProfile);
        $json = (string) file_get_contents($request->file('snapshot_file')->getRealPath());

        try {
            $service->importSnapshotForTarget(
                $feedProfile,
                $json,
                $request->user(),
                $request->validated('name')
            );
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Promotion snapshot imported.');
    }

    public function compare(
        PromotionActionRequest $request,
        FeedProfile $feedProfile,
        PromotionService $service
    ): RedirectResponse {
        $this->ensureShopOwned($request, $feedProfile);
        $snapshot = $this->findSnapshot($feedProfile, (int) $request->validated('source_snapshot_id'));
        $run = $service->compareSnapshot($snapshot, $feedProfile, $request->user(), $request->validated('reason'));

        return redirect()
            ->route('admin.feed-profiles.promotion.runs.show', [$feedProfile, $run])
            ->with('status', 'Promotion compare report generated.');
    }

    public function dryRun(
        PromotionActionRequest $request,
        FeedProfile $feedProfile,
        PromotionService $service
    ): RedirectResponse {
        $this->ensureShopOwned($request, $feedProfile);
        $snapshot = $this->findSnapshot($feedProfile, (int) $request->validated('source_snapshot_id'));
        $run = $service->dryRunSnapshot(
            $snapshot,
            $feedProfile,
            (string) ($request->validated('strategy') ?: PromotionRun::STRATEGY_SAFE_MERGE),
            $request->user(),
            $request->validated('reason')
        );

        return redirect()
            ->route('admin.feed-profiles.promotion.runs.show', [$feedProfile, $run])
            ->with('status', 'Promotion dry-run completed.');
    }

    public function apply(
        PromotionActionRequest $request,
        FeedProfile $feedProfile,
        GovernedActionService $governedActionService
    ): RedirectResponse {
        $this->ensureShopOwned($request, $feedProfile);
        $snapshot = $this->findSnapshot($feedProfile, (int) $request->validated('source_snapshot_id'));

        try {
            $result = $this->dispatchGovernedAction(
                $request,
                $governedActionService,
                ApprovalPolicyService::ACTION_PROMOTION_APPLY,
                $feedProfile,
                [
                    'feed_profile_id' => $feedProfile->id,
                    'source_snapshot_id' => $snapshot->id,
                    'strategy' => (string) ($request->validated('strategy') ?: PromotionRun::STRATEGY_SAFE_MERGE),
                    'reason' => (string) $request->validated('reason'),
                ],
                [
                    'feed_profile_id' => $feedProfile->id,
                    'source_snapshot_id' => $snapshot->id,
                    'strategy' => (string) ($request->validated('strategy') ?: PromotionRun::STRATEGY_SAFE_MERGE),
                ],
                (string) $request->validated('reason'),
                targetLabel: $feedProfile->code
            );
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        if ($result->status !== 'executed') {
            return $this->redirectWithGovernedResult(
                $request,
                $result,
                null,
                'Promotion apply is waiting on re-authentication or approval.'
            );
        }

        $run = PromotionRun::query()->findOrFail((int) ($result->execution['promotion_run_id'] ?? 0));

        return redirect()
            ->route('admin.feed-profiles.promotion.runs.show', [$feedProfile, $run])
            ->with('status', 'Promotion apply finished with status: '.$run->status);
    }

    public function run(Request $request, FeedProfile $feedProfile, PromotionRun $promotionRun): View
    {
        $this->ensureShopOwned($request, $feedProfile);
        abort_unless($promotionRun->target_feed_profile_id === $feedProfile->id || $promotionRun->source_feed_profile_id === $feedProfile->id, 404);

        return view('admin.feed-promotions.run', [
            'feedProfile' => $feedProfile,
            'run' => $promotionRun->load(['sourceSnapshot', 'targetSnapshot', 'resultSnapshot', 'user']),
        ]);
    }

    public function rollback(
        PromotionRollbackRequest $request,
        FeedProfile $feedProfile,
        PromotionRun $promotionRun,
        GovernedActionService $governedActionService
    ): RedirectResponse {
        $this->ensureShopOwned($request, $feedProfile);
        abort_unless($promotionRun->target_feed_profile_id === $feedProfile->id, 404);

        try {
            $result = $this->dispatchGovernedAction(
                $request,
                $governedActionService,
                ApprovalPolicyService::ACTION_PROMOTION_ROLLBACK,
                $promotionRun,
                [
                    'promotion_run_id' => $promotionRun->id,
                    'reason' => (string) $request->validated('reason'),
                ],
                [
                    'promotion_run_id' => $promotionRun->id,
                    'feed_profile_id' => $feedProfile->id,
                ],
                (string) $request->validated('reason'),
                targetLabel: 'Promotion run #'.$promotionRun->id
            );
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        if ($result->status !== 'executed') {
            return $this->redirectWithGovernedResult(
                $request,
                $result,
                null,
                'Promotion rollback is waiting on re-authentication or approval.'
            );
        }

        $run = PromotionRun::query()->findOrFail((int) ($result->execution['promotion_run_id'] ?? $promotionRun->id));

        return redirect()
            ->route('admin.feed-profiles.promotion.runs.show', [$feedProfile, $run])
            ->with('status', 'Promotion rollback finished with status: '.$run->status);
    }

    public function downloadRun(
        Request $request,
        FeedProfile $feedProfile,
        PromotionRun $promotionRun,
        PromotionReportService $service
    ): BinaryFileResponse {
        $this->ensureShopOwned($request, $feedProfile);
        abort_unless($promotionRun->target_feed_profile_id === $feedProfile->id || $promotionRun->source_feed_profile_id === $feedProfile->id, 404);
        $report = $service->store($promotionRun->loadMissing(['sourceSnapshot', 'targetSnapshot', 'resultSnapshot']));

        return response()->download($report['absolute_path'], $report['filename']);
    }

    public function downloadSnapshot(
        Request $request,
        FeedProfile $feedProfile,
        PromotionSnapshot $promotionSnapshot,
        PromotionReportService $service
    ): BinaryFileResponse {
        $this->ensureShopOwned($request, $feedProfile);
        abort_unless($promotionSnapshot->shop_id === $feedProfile->shop_id || $promotionSnapshot->feed_profile_id === $feedProfile->id, 404);
        $report = $service->storeSnapshot($promotionSnapshot);

        return response()->download($report['absolute_path'], $report['filename']);
    }

    private function findSnapshot(FeedProfile $feedProfile, int $snapshotId): PromotionSnapshot
    {
        return PromotionSnapshot::query()
            ->where('id', $snapshotId)
            ->where(function ($query) use ($feedProfile): void {
                $query->where('shop_id', $feedProfile->shop_id)
                    ->orWhere('feed_profile_id', $feedProfile->id);
            })
            ->firstOrFail();
    }
}
