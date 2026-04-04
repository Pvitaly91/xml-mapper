<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\Access\AdminInviteRequest;
use App\Http\Requests\Admin\Access\ApprovalDecisionRequest;
use App\Http\Requests\Admin\Access\ShopMembershipRequest;
use App\Http\Requests\Admin\Access\ShopMembershipStatusRequest;
use App\Http\Requests\Admin\Access\UserLifecycleRequest;
use App\Models\AdminInvite;
use App\Models\AdminSession;
use App\Models\ApprovalRequest;
use App\Models\ShopMembership;
use App\Models\User;
use App\Services\Access\AdminAccessService;
use App\Services\Auth\AdminAccountLifecycleService;
use App\Services\Auth\AdminInvitationService;
use App\Services\Auth\AdminSessionService;
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

    public function storeInvite(AdminInviteRequest $request, AdminInvitationService $service): RedirectResponse
    {
        try {
            $result = $service->createInvite($request->validated(), $request->user());
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()->route('admin.access.invites.show', $result['invite'])
            ->with('status', 'Invite created.')
            ->with('invite_accept_url', $result['accept_url']);
    }

    public function showInvite(Request $request, AdminInvite $adminInvite, AccessCenterService $service): View
    {
        $this->ensureScopedInvite($request, $adminInvite);

        return view('admin.access-center.invite', [
            'invite' => $service->inviteDetail($adminInvite),
            'currentShop' => $this->currentShop($request),
            'acceptUrl' => $adminInvite->token_ciphertext
                ? route('admin.invites.show', ['token' => $adminInvite->token_ciphertext])
                : null,
        ]);
    }

    public function resendInvite(Request $request, AdminInvite $adminInvite, AdminInvitationService $service): RedirectResponse
    {
        $this->ensureScopedInvite($request, $adminInvite);

        try {
            $result = $service->resend($adminInvite, $request->user());
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()
            ->with('status', 'Invite resent.')
            ->with('invite_accept_url', $result['accept_url']);
    }

    public function revokeInvite(Request $request, AdminInvite $adminInvite, AdminInvitationService $service): RedirectResponse
    {
        $this->ensureScopedInvite($request, $adminInvite);

        try {
            $service->revoke($adminInvite, $request->user(), $request->input('reason'));
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Invite revoked.');
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

    public function authAudit(Request $request, AccessCenterService $service): View
    {
        return view('admin.access-center.auth-audit', $service->authAudit(
            $request->user(),
            $this->currentShop($request),
            $request->all()
        ));
    }

    public function sessions(Request $request, AccessCenterService $service): View
    {
        return view('admin.access-center.sessions', $service->index(
            $request->user(),
            $this->currentShop($request),
            $request->all()
        ));
    }

    public function revokeSession(
        Request $request,
        AdminSession $adminSession,
        AdminSessionService $service
    ): RedirectResponse {
        $this->ensureScopedSession($request, $adminSession);

        try {
            $service->revokeSession($adminSession, $request->user(), $request->input('reason'));
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Session revoked.');
    }

    public function revokeUserSessions(
        UserLifecycleRequest $request,
        User $user,
        AdminSessionService $service
    ): RedirectResponse {
        $this->ensureScopedUser($request, $user);

        $all = (bool) $request->boolean('all', true);

        try {
            $count = $service->revokeUserSessions(
                $user,
                $request->user(),
                $all,
                $all ? null : $request->session()->getId(),
                $request->validated('reason')
            );
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', sprintf('%d session(s) revoked.', $count));
    }

    public function suspendUser(
        UserLifecycleRequest $request,
        User $user,
        AdminAccountLifecycleService $service
    ): RedirectResponse {
        $shop = $request->filled('shop_id') ? app(AdminAccessService::class)->accessibleShop($request->user(), $request->validated('shop_id')) : $this->currentShop($request);
        $this->ensureScopedUser($request, $user, $shop);

        try {
            $service->suspend($user, $request->user(), $shop, $request->validated('reason'));
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'User suspended.');
    }

    public function reactivateUser(
        UserLifecycleRequest $request,
        User $user,
        AdminAccountLifecycleService $service
    ): RedirectResponse {
        $shop = $request->filled('shop_id') ? app(AdminAccessService::class)->accessibleShop($request->user(), $request->validated('shop_id')) : $this->currentShop($request);
        $this->ensureScopedUser($request, $user, $shop);

        try {
            $service->reactivate($user, $request->user(), $shop, $request->validated('reason'));
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'User reactivated.');
    }

    public function forcePasswordReset(
        UserLifecycleRequest $request,
        User $user,
        AdminAccountLifecycleService $service
    ): RedirectResponse {
        $this->ensureScopedUser($request, $user);

        try {
            $service->forcePasswordReset($user, $request->user(), $request->validated('reason'));
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Password reset requirement applied.');
    }

    public function resetMfa(
        UserLifecycleRequest $request,
        User $user,
        AdminAccountLifecycleService $service
    ): RedirectResponse {
        $this->ensureScopedUser($request, $user);

        try {
            $service->resetMfa($user, $request->user(), $request->validated('reason'));
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'MFA reset completed.');
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

    private function ensureScopedInvite(Request $request, AdminInvite $invite): void
    {
        $membership = $invite->membership;

        if ($membership?->shop) {
            $this->ensureShopOwned($request, $membership->shop);

            return;
        }

        abort_unless(app(AdminAccessService::class)->isPlatformAdmin($request->user()), 404);
    }

    private function ensureScopedSession(Request $request, AdminSession $adminSession): void
    {
        if ((int) $adminSession->user_id === (int) $request->user()->id) {
            return;
        }

        $this->ensureScopedUser($request, $adminSession->user);
    }

    private function ensureScopedUser(Request $request, User $user, ?\App\Models\Shop $shop = null): void
    {
        $accessService = app(AdminAccessService::class);

        if ($accessService->isPlatformAdmin($request->user())) {
            return;
        }

        $shop ??= $this->currentShop($request);
        abort_unless($shop !== null, 404);

        $membership = $user->memberships()
            ->where('shop_id', $shop->id)
            ->exists();

        abort_unless($membership, 404);
    }
}
