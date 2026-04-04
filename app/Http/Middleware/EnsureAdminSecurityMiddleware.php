<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Auth\AdminAuthAuditService;
use App\Services\Auth\AdminAuthPolicyService;
use App\Services\Auth\AdminSessionService;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminSecurityMiddleware
{
    public function __construct(
        private readonly AdminSessionService $sessionService,
        private readonly AdminAuthPolicyService $policyService,
        private readonly AdminAuthAuditService $auditService,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        if (! $user->isAdmin()) {
            abort(403);
        }

        $session = $this->sessionService->syncCurrentSession($request, $user);

        if ($this->sessionService->sessionRequiresTermination($session)) {
            $this->auditService->record(
                'session_reuse_blocked',
                'Revoked session attempted to access admin.',
                $user,
                target: $session,
                severity: 'warning',
                context: ['session_id' => $session->id],
                targetLabel: $session->device_label
            );

            return $this->terminateSession($request, 'This session was revoked. Please sign in again.');
        }

        if (! $user->canUseAdminAuthentication()) {
            return $this->terminateSession($request, 'This account cannot access admin right now.');
        }

        $this->sessionService->expireBreakGlassIfNeeded($request, $user);

        if ($user->requiresPasswordReset() && ! $this->allowsPasswordResetRoute($request)) {
            return redirect()->route('admin.auth.password-reset.edit')
                ->with('error', 'Password reset is required before continuing.');
        }

        if ($this->policyService->requiresMfaEnrollment($user) && ! $this->allowsMfaSetupRoute($request)) {
            return redirect()->route('admin.auth.mfa.setup')
                ->with('error', 'MFA setup is required before continuing.');
        }

        if ($this->policyService->requiresMfaChallenge($user) && ! $this->sessionService->currentHasVerifiedMfa($request) && ! $this->allowsMfaChallengeRoute($request)) {
            return redirect()->route('admin.auth.mfa.challenge.create')
                ->with('error', 'MFA confirmation is required before continuing.');
        }

        return $next($request);
    }

    private function terminateSession(Request $request, string $message): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('error', $message);
    }

    private function allowsPasswordResetRoute(Request $request): bool
    {
        return $request->routeIs('admin.auth.password-reset.*')
            || $request->routeIs('admin.logout')
            || $request->routeIs('admin.auth.reauth.*');
    }

    private function allowsMfaSetupRoute(Request $request): bool
    {
        return $request->routeIs('admin.auth.mfa.*')
            || $request->routeIs('admin.auth.password-reset.*')
            || $request->routeIs('admin.logout')
            || $request->routeIs('admin.auth.reauth.*');
    }

    private function allowsMfaChallengeRoute(Request $request): bool
    {
        return $request->routeIs('admin.auth.mfa.challenge.*')
            || $request->routeIs('admin.logout')
            || $request->routeIs('admin.auth.reauth.*');
    }
}
