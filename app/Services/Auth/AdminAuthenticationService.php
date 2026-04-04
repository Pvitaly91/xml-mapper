<?php

namespace App\Services\Auth;

use App\Data\Auth\LoginResult;
use App\Models\User;
use App\Services\Access\AdminAccessService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AdminAuthenticationService
{
    public function __construct(
        private readonly AdminAccessService $accessService,
        private readonly AdminAuthPolicyService $policyService,
        private readonly AdminAuthAuditService $auditService,
        private readonly AdminSessionService $sessionService,
    ) {}

    /**
     * @param  array{email:string,password:string,remember?:bool}  $credentials
     *
     * @throws AuthenticationException
     */
    public function attempt(Request $request, array $credentials): LoginResult
    {
        $email = mb_strtolower(trim((string) $credentials['email']));
        $password = (string) $credentials['password'];
        $remember = (bool) ($credentials['remember'] ?? false);
        $user = User::query()->where('email', $email)->first();

        if (! $user instanceof User || ! Hash::check($password, $user->password)) {
            $this->recordLoginFailure($user, $request, 'invalid_credentials');

            throw new AuthenticationException('Invalid admin credentials.');
        }

        $this->unlockIfExpired($user);

        if (! $user->canUseAdminAuthentication()) {
            $this->recordLoginFailure($user, $request, $user->account_state ?: 'inactive');

            throw new AuthenticationException(match ($user->account_state) {
                User::STATE_INVITED => 'This invite has not been accepted yet.',
                User::STATE_SUSPENDED => 'This account is suspended.',
                User::STATE_LOCKED => 'This account is temporarily locked.',
                default => 'This account cannot access admin right now.',
            });
        }

        if (! $this->accessService->canAccessAdmin($user)) {
            $this->recordLoginFailure($user, $request, 'no_admin_access');

            throw new AuthenticationException('This account does not have admin access.');
        }

        Auth::guard('web')->login($user, $remember);
        $request->session()->regenerate();
        $request->session()->put('admin_auth.password_confirmed_at', now()->toIso8601String());
        $request->session()->forget('admin_auth.mfa_verified_at');
        $request->session()->forget('admin_auth.break_glass');

        $user->forceFill([
            'failed_login_attempts' => 0,
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
            'account_state' => $user->requiresPasswordReset()
                ? User::STATE_PASSWORD_RESET_REQUIRED
                : User::STATE_ACTIVE,
            'locked_until' => null,
        ])->save();

        $this->sessionService->markLogin($request, $user);

        $redirectRoute = $this->policyService->loginRedirectRoute($user);

        if ($redirectRoute === 'admin.dashboard') {
            $request->session()->put('admin_auth.login_verified_at', now()->toIso8601String());
        }

        $this->auditService->record(
            'login_success',
            'Admin login succeeded.',
            $user,
            target: $user,
            context: ['ip' => $request->ip(), 'redirect_route' => $redirectRoute],
            targetLabel: $user->email
        );

        if ($redirectRoute === 'admin.auth.mfa.challenge.create') {
            $this->auditService->record(
                'mfa_challenged',
                'MFA challenge required after login.',
                $user,
                target: $user,
                severity: 'warning',
                context: ['ip' => $request->ip()],
                targetLabel: $user->email
            );
        }

        return new LoginResult(
            $redirectRoute,
            $redirectRoute === 'admin.dashboard'
                ? 'Admin session started.'
                : 'Additional verification is required before admin access is complete.'
        );
    }

    public function updatePassword(Request $request, User $user, string $currentPassword, string $newPassword): void
    {
        if (! Hash::check($currentPassword, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'Current password is invalid.',
            ]);
        }

        $user->forceFill([
            'password' => Hash::make($newPassword),
            'password_changed_at' => now(),
            'password_reset_required_at' => null,
            'account_state' => User::STATE_ACTIVE,
            'is_active' => true,
        ])->save();

        $this->sessionService->markPasswordConfirmed($request);

        if ($this->policyService->revokeOtherSessionsOnPasswordChange()) {
            $this->sessionService->revokeUserSessions(
                $user,
                $user,
                false,
                $request->session()->getId(),
                'password changed'
            );
        }

        $this->auditService->record(
            'password_changed',
            'Admin password changed.',
            $user,
            target: $user,
            severity: 'warning',
            context: ['ip' => $request->ip()],
            targetLabel: $user->email
        );
    }

    public function logout(Request $request): void
    {
        $user = $request->user();

        if ($user instanceof User) {
            $this->sessionService->markLogout($request, $user);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    private function unlockIfExpired(User $user): void
    {
        if ($user->account_state !== User::STATE_LOCKED || ($user->locked_until?->isFuture() ?? false)) {
            return;
        }

        $user->forceFill([
            'account_state' => User::STATE_ACTIVE,
            'failed_login_attempts' => 0,
            'locked_until' => null,
        ])->save();

        $this->auditService->record(
            'account_unlocked',
            'Login lock window expired and account was unlocked.',
            $user,
            target: $user,
            severity: 'warning',
            targetLabel: $user->email
        );
    }

    private function recordLoginFailure(?User $user, Request $request, string $reason): void
    {
        if ($user instanceof User) {
            $attempts = (int) $user->failed_login_attempts + 1;
            $lockThreshold = $this->policyService->loginMaxFailures();
            $updates = [
                'failed_login_attempts' => $attempts,
            ];

            if ($attempts >= $lockThreshold) {
                $updates['account_state'] = User::STATE_LOCKED;
                $updates['locked_until'] = now()->addMinutes($this->policyService->loginLockMinutes());
            }

            $user->forceFill($updates)->save();
        }

        $this->auditService->record(
            'login_failed',
            'Admin login failed.',
            $user,
            target: $user,
            severity: 'warning',
            context: [
                'ip' => $request->ip(),
                'email' => $request->input('email'),
                'reason' => $reason,
            ],
            targetLabel: $user?->email ?: (string) $request->input('email')
        );

        if ($user instanceof User && $user->account_state === User::STATE_LOCKED) {
            $this->auditService->record(
                'account_locked',
                'Admin account locked after repeated login failures.',
                $user,
                target: $user,
                severity: 'warning',
                context: ['ip' => $request->ip(), 'locked_until' => $user->locked_until?->toIso8601String()],
                targetLabel: $user->email
            );
        }
    }
}
