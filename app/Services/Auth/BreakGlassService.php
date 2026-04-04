<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\Access\AdminAccessService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class BreakGlassService
{
    public function __construct(
        private readonly AdminAccessService $accessService,
        private readonly AdminAuthPolicyService $policyService,
        private readonly AdminSessionService $sessionService,
        private readonly AdminAuthAuditService $auditService,
    ) {}

    public function start(Request $request, User $actor, string $reason): void
    {
        if (! $this->accessService->isPlatformAdmin($actor)) {
            throw ValidationException::withMessages([
                'reason' => 'Break-glass mode is restricted to platform administrators.',
            ]);
        }

        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'reason' => 'Break-glass reason is required.',
            ]);
        }

        if (! filled($this->sessionService->passwordConfirmedAt($request))) {
            throw ValidationException::withMessages([
                'reason' => 'Recent password confirmation is required before starting break-glass mode.',
            ]);
        }

        if ($this->policyService->breakGlassRequiresMfa($actor) && ! $this->sessionService->currentHasVerifiedMfa($request)) {
            throw ValidationException::withMessages([
                'reason' => 'Recent MFA confirmation is required before starting break-glass mode.',
            ]);
        }

        $session = $this->sessionService->startBreakGlass(
            $request,
            $actor,
            $reason,
            now()->addMinutes($this->policyService->breakGlassTtlMinutes())
        );

        $this->auditService->record(
            'break_glass_started',
            'Break-glass mode started.',
            $actor,
            target: $session,
            severity: 'warning',
            context: [
                'reason' => $reason,
                'expires_at' => $session->break_glass_expires_at?->toIso8601String(),
            ],
            targetLabel: $actor->email
        );
    }

    public function end(Request $request, User $actor, ?string $reason = null): void
    {
        $session = $this->sessionService->endBreakGlass($request, $actor, $reason);

        if ($session === null) {
            return;
        }

        $this->auditService->record(
            'break_glass_ended',
            'Break-glass mode ended.',
            $actor,
            target: $session,
            severity: 'warning',
            context: ['reason' => $reason],
            targetLabel: $actor->email
        );
    }
}
