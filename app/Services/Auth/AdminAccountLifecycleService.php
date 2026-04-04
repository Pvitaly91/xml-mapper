<?php

namespace App\Services\Auth;

use App\Models\Shop;
use App\Models\ShopMembership;
use App\Models\User;
use App\Services\Governance\MembershipService;
use Illuminate\Validation\ValidationException;

class AdminAccountLifecycleService
{
    public function __construct(
        private readonly MembershipService $membershipService,
        private readonly AdminSessionService $sessionService,
        private readonly AdminMfaService $mfaService,
        private readonly AdminAuthAuditService $auditService,
    ) {}

    public function suspend(User $subject, User $actor, ?Shop $shop = null, ?string $reason = null): void
    {
        if ($shop instanceof Shop) {
            $membership = ShopMembership::query()
                ->where('user_id', $subject->id)
                ->where('shop_id', $shop->id)
                ->firstOrFail();

            $this->membershipService->updateStatus($membership, ShopMembership::STATUS_SUSPENDED, $actor, $reason);

            return;
        }

        $subject->forceFill([
            'is_active' => false,
            'account_state' => User::STATE_SUSPENDED,
            'locked_until' => null,
        ])->save();

        $this->sessionService->revokeUserSessions($subject, $actor, true, null, $reason ?: 'global suspension');

        $this->auditService->record(
            'account_suspended',
            'Admin account suspended.',
            $actor,
            $shop,
            $subject,
            severity: 'warning',
            context: ['subject_user_id' => $subject->id, 'reason' => $reason],
            targetLabel: $subject->email
        );
    }

    public function reactivate(User $subject, User $actor, ?Shop $shop = null, ?string $reason = null): void
    {
        if ($shop instanceof Shop) {
            $membership = ShopMembership::query()
                ->where('user_id', $subject->id)
                ->where('shop_id', $shop->id)
                ->firstOrFail();

            $this->membershipService->updateStatus($membership, ShopMembership::STATUS_ACTIVE, $actor, $reason);

            return;
        }

        $subject->forceFill([
            'is_active' => true,
            'account_state' => $subject->requiresPasswordReset()
                ? User::STATE_PASSWORD_RESET_REQUIRED
                : User::STATE_ACTIVE,
            'failed_login_attempts' => 0,
            'locked_until' => null,
        ])->save();

        $this->auditService->record(
            'account_reactivated',
            'Admin account reactivated.',
            $actor,
            $shop,
            $subject,
            severity: 'warning',
            context: ['subject_user_id' => $subject->id, 'reason' => $reason],
            targetLabel: $subject->email
        );
    }

    public function forcePasswordReset(User $subject, User $actor, ?string $reason = null): void
    {
        $subject->forceFill([
            'account_state' => User::STATE_PASSWORD_RESET_REQUIRED,
            'password_reset_required_at' => now(),
            'is_active' => true,
            'failed_login_attempts' => 0,
            'locked_until' => null,
        ])->save();

        $this->sessionService->revokeUserSessions($subject, $actor, true, null, $reason ?: 'force password reset');

        $this->auditService->record(
            'password_reset_required',
            'Password reset required for admin account.',
            $actor,
            target: $subject,
            severity: 'warning',
            context: ['subject_user_id' => $subject->id, 'reason' => $reason],
            targetLabel: $subject->email
        );
    }

    public function resetMfa(User $subject, User $actor, ?string $reason = null): void
    {
        if (! $subject->hasMfaEnabled() && ! $subject->hasPendingMfaSetup()) {
            throw ValidationException::withMessages([
                'user' => 'MFA is not configured for this user.',
            ]);
        }

        $this->mfaService->reset($subject);
        $this->sessionService->revokeUserSessions($subject, $actor, true, null, $reason ?: 'mfa reset');

        $this->auditService->record(
            'mfa_reset',
            'MFA reset for admin account.',
            $actor,
            target: $subject,
            severity: 'warning',
            context: ['subject_user_id' => $subject->id, 'reason' => $reason],
            targetLabel: $subject->email
        );
    }
}
