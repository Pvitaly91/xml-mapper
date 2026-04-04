<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\Access\ApprovalDecisionRequest;
use App\Http\Requests\Admin\Access\ShopMembershipRequest;
use App\Http\Requests\Admin\Access\ShopMembershipStatusRequest;
use App\Models\ApprovalRequest;
use App\Models\ShopMembership;
use App\Services\Access\AdminAccessService;
use App\Services\Governance\AccessCenterService;
use App\Services\Governance\ComplianceReportService;
use App\Services\Governance\GovernedActionService;
use App\Services\Governance\MembershipService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class AccessCenterController extends AdminController
{
    public function index(Request $request, AccessCenterService $service): View
    {
        return view('admin.access-center.index', $service->index(
            $request->user(),
            $this->currentShop($request),
            $request->all()
        ));
    }

    public function switchShop(Request $request, AdminAccessService $accessService): RedirectResponse
    {
        if (filled($request->input('shop_id'))) {
            $shop = $accessService->accessibleShop($request->user(), $request->input('shop_id'));

            if ($shop === null) {
                return back()->with('error', 'You do not have access to that shop.');
            }

            $request->session()->put('admin_shop_id', $shop->id);
        }

        return back()->with('status', 'Current shop changed.');
    }

    public function storeMembership(ShopMembershipRequest $request, MembershipService $service): RedirectResponse
    {
        try {
            $service->grant($request->validated(), $request->user());
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Membership saved.');
    }

    public function updateMembership(
        ShopMembershipStatusRequest $request,
        ShopMembership $shopMembership,
        MembershipService $service
    ): RedirectResponse {
        $this->ensureScopedMembership($request, $shopMembership);

        try {
            $service->updateStatus(
                $shopMembership,
                (string) $request->validated('status'),
                $request->user(),
                $request->validated('note')
            );
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Membership updated.');
    }

    public function revokeMembership(Request $request, ShopMembership $shopMembership, MembershipService $service): RedirectResponse
    {
        $this->ensureScopedMembership($request, $shopMembership);

        try {
            $service->revoke($shopMembership, $request->user(), $request->input('note'));
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Membership revoked.');
    }

    public function showApproval(Request $request, ApprovalRequest $approvalRequest, AccessCenterService $service): View
    {
        $this->ensureScopedApproval($request, $approvalRequest);

        return view('admin.access-center.approval', [
            'approval' => $service->approvalDetail($approvalRequest),
            'currentShop' => $this->currentShop($request),
        ]);
    }

    public function approve(
        ApprovalDecisionRequest $request,
        ApprovalRequest $approvalRequest,
        GovernedActionService $service
    ): RedirectResponse {
        $this->ensureScopedApproval($request, $approvalRequest);

        try {
            $service->approve($approvalRequest, $request->user(), $request->validated('note'));
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Approval executed.');
    }

    public function reject(
        ApprovalDecisionRequest $request,
        ApprovalRequest $approvalRequest,
        GovernedActionService $service
    ): RedirectResponse {
        $this->ensureScopedApproval($request, $approvalRequest);

        try {
            $service->reject($approvalRequest, $request->user(), $request->validated('note'));
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Approval rejected.');
    }

    public function compliance(Request $request, AccessCenterService $service): View
    {
        return view('admin.access-center.compliance', $service->compliance(
            $request->user(),
            $this->currentShop($request),
            $request->all()
        ));
    }

    public function exportCompliance(Request $request, ComplianceReportService $service): BinaryFileResponse
    {
        $accessService = app(AdminAccessService::class);
        $currentShop = $this->currentShop($request);
        $shopId = $request->input('shop_id') ?: $currentShop?->id;

        if (! $accessService->isPlatformAdmin($request->user())) {
            $shopId = $currentShop?->id;
        } elseif ($shopId && $accessService->accessibleShop($request->user(), $shopId) === null) {
            abort(404);
        }

        $report = $service->export(array_merge($request->all(), [
            'shop_id' => $shopId,
        ]));

        return response()->download($report['absolute_path'], $report['filename']);
    }

    private function ensureScopedMembership(Request $request, ShopMembership $membership): void
    {
        if ($membership->shop) {
            $this->ensureShopOwned($request, $membership->shop);

            return;
        }

        abort_unless(app(AdminAccessService::class)->isPlatformAdmin($request->user()), 404);
    }

    private function ensureScopedApproval(Request $request, ApprovalRequest $approvalRequest): void
    {
        if ($approvalRequest->shop) {
            $this->ensureShopOwned($request, $approvalRequest->shop);

            return;
        }

        abort_unless(app(AdminAccessService::class)->isPlatformAdmin($request->user()), 404);
    }
}
