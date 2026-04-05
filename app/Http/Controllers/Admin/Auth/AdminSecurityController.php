<?php

namespace App\Http\Controllers\Admin\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Auth\AdminBreakGlassRequest;
use App\Http\Requests\Admin\Auth\AdminInviteAcceptRequest;
use App\Http\Requests\Admin\Auth\AdminMfaCodeRequest;
use App\Http\Requests\Admin\Auth\AdminPasswordResetRequest;
use App\Http\Requests\Admin\Auth\AdminReauthPasswordRequest;
use App\Models\User;
use App\Services\Auth\AdminAuthAuditService;
use App\Services\Auth\AdminAuthenticationService;
use App\Services\Auth\AdminAuthPolicyService;
use App\Services\Auth\AdminInvitationService;
use App\Services\Auth\AdminMfaService;
use App\Services\Auth\AdminSessionService;
use App\Services\Auth\AdminStepUpAuthService;
use App\Services\Auth\BreakGlassService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class AdminSecurityController extends Controller
{
    public function showInvite(string $token, AdminInvitationService $service): View
    {
        $invite = $service->findByToken($token);

        return view('admin.auth.invite', [
            'invite' => $invite,
            'token' => $token,
        ]);
    }

    public function acceptInvite(
        AdminInviteAcceptRequest $request,
        string $token,
        AdminInvitationService $inviteService,
        AdminAuthPolicyService $policyService,
        AdminSessionService $sessionService,
        AdminAuthAuditService $auditService,
    ): RedirectResponse {
        $invite = $inviteService->findByToken($token);

        if ($invite === null) {
            return back()->with('error', 'Invite is invalid or expired.');
        }

        try {
            $user = $inviteService->accept($invite, $request->validated());
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        Auth::guard('web')->login($user, true);
        $request->session()->regenerate();
        $sessionService->markPasswordConfirmed($request);
        $sessionService->markLogin($request, $user);

        $auditService->record(
            'login_success',
            'Admin login succeeded after invite acceptance.',
            $user,
            target: $user,
            targetLabel: $user->email
        );

        return redirect()->route($policyService->loginRedirectRoute($user))
            ->with('status', 'Invite accepted. Admin access is ready to finish setup.');
    }

    public function editPasswordReset(Request $request): View
    {
        return view('admin.auth.password-reset', [
            'user' => $request->user(),
        ]);
    }

    public function updatePassword(
        AdminPasswordResetRequest $request,
        AdminAuthenticationService $service,
        AdminAuthPolicyService $policyService,
    ): RedirectResponse {
        try {
            $service->updatePassword(
                $request,
                $request->user(),
                (string) $request->validated('current_password'),
                (string) $request->validated('password')
            );
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()->route($policyService->loginRedirectRoute($request->user()))
            ->with('status', 'Password updated.');
    }

    public function showMfaSetup(Request $request, AdminMfaService $service): View
    {
        $user = $request->user();
        $setup = $user->hasMfaEnabled()
            ? ['secret' => null, 'provisioning_uri' => null, 'qr_svg' => null]
            : $service->beginEnrollment($user);

        return view('admin.auth.mfa-setup', [
            'user' => $user,
            'setup' => $setup,
            'recoveryCodes' => session('mfa_recovery_codes', []),
            'stepUp' => session('admin_step_up'),
        ]);
    }

    public function enableMfa(
        AdminMfaCodeRequest $request,
        AdminMfaService $service,
        AdminSessionService $sessionService,
        AdminAuthAuditService $auditService,
    ): RedirectResponse {
        try {
            $result = $service->confirmEnrollment($request->user(), (string) $request->validated('code'));
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $sessionService->markMfaVerified($request, $request->user());
        $auditService->record(
            'mfa_enrolled',
            'MFA enrollment completed.',
            $request->user(),
            target: $request->user(),
            severity: 'warning',
            targetLabel: $request->user()->email
        );

        return redirect()->route('admin.auth.mfa.setup')
            ->with('status', 'MFA enabled. Store the recovery codes now; they will not be shown again.')
            ->with('mfa_recovery_codes', $result['recovery_codes']);
    }

    public function showMfaChallenge(Request $request): View
    {
        return view('admin.auth.mfa-challenge', [
            'user' => $request->user(),
            'stepUp' => session('admin_step_up'),
        ]);
    }

    public function verifyMfaChallenge(
        AdminMfaCodeRequest $request,
        AdminMfaService $service,
        AdminSessionService $sessionService,
        AdminAuthAuditService $auditService,
    ): RedirectResponse {
        try {
            $result = $service->challenge($request->user(), (string) $request->validated('code'));
        } catch (ValidationException $exception) {
            $auditService->record(
                'mfa_failed',
                'MFA challenge failed.',
                $request->user(),
                target: $request->user(),
                severity: 'warning',
                targetLabel: $request->user()->email
            );

            throw $exception;
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $sessionService->markMfaVerified($request, $request->user());
        $auditService->record(
            'mfa_verified',
            'MFA challenge completed.',
            $request->user(),
            target: $request->user(),
            severity: 'warning',
            context: ['method' => $result['method']],
            targetLabel: $request->user()->email
        );

        return redirect()->route('admin.dashboard')
            ->with('status', 'MFA verified.');
    }

    public function showPasswordReauth(): View
    {
        return view('admin.auth.reauth-password', [
            'stepUp' => session('admin_step_up'),
        ]);
    }

    public function verifyPasswordReauth(
        AdminReauthPasswordRequest $request,
        AdminStepUpAuthService $service,
    ): RedirectResponse {
        try {
            $service->confirmPassword($request, $request->user(), (string) $request->validated('password'));
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $intendedUrl = $request->session()->pull('admin_step_up.intended_url', route('admin.dashboard'));
        $request->session()->forget('admin_step_up');

        return redirect()->to($intendedUrl)
            ->with('status', 'Password re-authentication confirmed. Resume the blocked action.');
    }

    public function showMfaReauth(): View
    {
        return view('admin.auth.reauth-mfa', [
            'stepUp' => session('admin_step_up'),
        ]);
    }

    public function verifyMfaReauth(
        AdminMfaCodeRequest $request,
        AdminStepUpAuthService $service,
    ): RedirectResponse {
        try {
            $service->confirmMfa($request, $request->user(), (string) $request->validated('code'));
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $intendedUrl = $request->session()->pull('admin_step_up.intended_url', route('admin.dashboard'));
        $request->session()->forget('admin_step_up');

        return redirect()->to($intendedUrl)
            ->with('status', 'MFA re-authentication confirmed. Resume the blocked action.');
    }

    public function startBreakGlass(
        AdminBreakGlassRequest $request,
        BreakGlassService $service,
    ): RedirectResponse {
        try {
            $service->start($request, $request->user(), (string) $request->validated('reason'));
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Break-glass mode started.');
    }

    public function endBreakGlass(Request $request, BreakGlassService $service): RedirectResponse
    {
        $service->end($request, $request->user(), $request->input('reason'));

        return back()->with('status', 'Break-glass mode ended.');
    }
}
