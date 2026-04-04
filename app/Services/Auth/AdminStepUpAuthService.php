<?php

namespace App\Services\Auth;

use App\Data\Auth\StepUpResult;
use App\Models\Shop;
use App\Models\User;
use App\Services\Governance\ApprovalPolicyService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AdminStepUpAuthService
{
    public function __construct(
        private readonly AdminAuthPolicyService $policyService,
        private readonly ApprovalPolicyService $approvalPolicyService,
        private readonly AdminSessionService $sessionService,
        private readonly AdminMfaService $mfaService,
        private readonly AdminAuthAuditService $auditService,
    ) {}

    public function authorizeAction(string $action, User $actor, ?Shop $shop = null): StepUpResult
    {
        /** @var Request|null $request */
        $request = app()->bound('request') ? request() : null;

        if (! $request instanceof Request || ! $request->hasSession()) {
            return new StepUpResult('allowed');
        }

        $approvalRule = $this->approvalPolicyService->rule($action, $shop);

        if (! $this->passwordConfirmedRecently($request)) {
            $this->auditService->record(
                'reauth_challenged',
                'Recent password confirmation required for dangerous action.',
                $actor,
                $shop,
                severity: 'warning',
                context: ['action' => $action, 'reauth' => 'password'],
                targetLabel: $actor->email
            );

            return new StepUpResult('password_reauth_required', 'Recent password confirmation is required.');
        }

        if (! $this->policyService->stepUpRequiresMfa($action, $approvalRule, $actor, $shop)) {
            return new StepUpResult('allowed');
        }

        if (! $actor->hasMfaEnabled()) {
            $this->auditService->record(
                'sensitive_action_blocked',
                'Dangerous action blocked because MFA is required by policy.',
                $actor,
                $shop,
                severity: 'warning',
                context: ['action' => $action, 'policy' => 'mfa_required'],
                targetLabel: $actor->email
            );

            return new StepUpResult('blocked_by_policy', 'MFA is required by policy before this action can proceed.');
        }

        if (! $this->mfaConfirmedRecently($request)) {
            $this->auditService->record(
                'reauth_challenged',
                'Recent MFA confirmation required for dangerous action.',
                $actor,
                $shop,
                severity: 'warning',
                context: ['action' => $action, 'reauth' => 'mfa'],
                targetLabel: $actor->email
            );

            return new StepUpResult('mfa_reauth_required', 'Recent MFA confirmation is required.');
        }

        return new StepUpResult('allowed');
    }

    public function passwordConfirmedRecently(Request $request): bool
    {
        $confirmedAt = $this->sessionService->passwordConfirmedAt($request);

        if (! filled($confirmedAt)) {
            return false;
        }

        return Carbon::parse((string) $confirmedAt)->greaterThanOrEqualTo(
            now()->subMinutes($this->policyService->passwordReauthTtlMinutes())
        );
    }

    public function mfaConfirmedRecently(Request $request): bool
    {
        $confirmedAt = $this->sessionService->mfaConfirmedAt($request);

        if (! filled($confirmedAt)) {
            return false;
        }

        return Carbon::parse((string) $confirmedAt)->greaterThanOrEqualTo(
            now()->subMinutes($this->policyService->mfaReauthTtlMinutes())
        );
    }

    public function confirmPassword(Request $request, User $actor, string $password): void
    {
        if (! Hash::check($password, $actor->password)) {
            $this->auditService->record(
                'reauth_password_failed',
                'Password re-authentication failed.',
                $actor,
                severity: 'warning',
                context: ['ip' => $request->ip()],
                targetLabel: $actor->email
            );

            throw ValidationException::withMessages([
                'password' => 'Password confirmation failed.',
            ]);
        }

        $this->sessionService->markPasswordConfirmed($request);

        $this->auditService->record(
            'reauth_password_succeeded',
            'Password re-authentication succeeded.',
            $actor,
            context: ['ip' => $request->ip()],
            targetLabel: $actor->email
        );
    }

    public function confirmMfa(Request $request, User $actor, string $code): void
    {
        try {
            $result = $this->mfaService->challenge($actor, $code);
        } catch (ValidationException $exception) {
            $this->auditService->record(
                'reauth_mfa_failed',
                'MFA re-authentication failed.',
                $actor,
                severity: 'warning',
                context: ['ip' => $request->ip()],
                targetLabel: $actor->email
            );

            throw $exception;
        }

        $this->sessionService->markMfaVerified($request, $actor);

        $this->auditService->record(
            'reauth_mfa_succeeded',
            'MFA re-authentication succeeded.',
            $actor,
            severity: 'warning',
            context: ['ip' => $request->ip(), 'method' => $result['method']],
            targetLabel: $actor->email
        );
    }
}
