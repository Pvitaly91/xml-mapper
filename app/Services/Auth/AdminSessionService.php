<?php

namespace App\Services\Auth;

use App\Models\AdminSession;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AdminSessionService
{
    public function __construct(
        private readonly AdminAuthAuditService $auditService,
    ) {}

    public function syncCurrentSession(Request $request, User $user): AdminSession
    {
        $sessionId = $request->session()->getId();
        $existing = AdminSession::query()->find($sessionId);
        $now = now();
        $payload = $existing?->getRawOriginal('payload');

        if (! is_string($payload) || $payload === '') {
            $payload = base64_encode(serialize($request->session()->all()));
        }

        DB::table('sessions')->updateOrInsert(
            ['id' => $sessionId],
            [
                'user_id' => $user->id,
                'ip_address' => $request->ip(),
                'user_agent' => (string) $request->userAgent(),
                'payload' => $payload,
                'last_activity' => $now->timestamp,
                'created_at' => $existing?->created_at ?: $now,
                'last_seen_at' => $now,
                'device_label' => $this->deviceLabel((string) $request->userAgent()),
                'mfa_verified_at' => $request->session()->get('admin_auth.mfa_verified_at')
                    ? Carbon::parse((string) $request->session()->get('admin_auth.mfa_verified_at'))
                    : $existing?->mfa_verified_at,
                'break_glass_reason' => $request->session()->get('admin_auth.break_glass.reason', $existing?->break_glass_reason),
                'break_glass_started_at' => $request->session()->get('admin_auth.break_glass.started_at')
                    ? Carbon::parse((string) $request->session()->get('admin_auth.break_glass.started_at'))
                    : $existing?->break_glass_started_at,
                'break_glass_expires_at' => $request->session()->get('admin_auth.break_glass.expires_at')
                    ? Carbon::parse((string) $request->session()->get('admin_auth.break_glass.expires_at'))
                    : $existing?->break_glass_expires_at,
            ]
        );

        return AdminSession::query()->findOrFail($sessionId);
    }

    public function current(Request $request): ?AdminSession
    {
        return AdminSession::query()->find($request->session()->getId());
    }

    public function currentSessionId(Request $request): string
    {
        return $request->session()->getId();
    }

    public function currentHasVerifiedMfa(Request $request): bool
    {
        return filled($request->session()->get('admin_auth.mfa_verified_at'));
    }

    public function currentHasFreshBreakGlass(Request $request): bool
    {
        $expiresAt = $request->session()->get('admin_auth.break_glass.expires_at');

        return filled($expiresAt) && now()->lt(Carbon::parse((string) $expiresAt));
    }

    public function sessionRequiresTermination(AdminSession $session): bool
    {
        return $session->revoked_at !== null;
    }

    public function markLogin(Request $request, User $user): AdminSession
    {
        return $this->syncCurrentSession($request, $user);
    }

    public function markLogout(Request $request, ?User $user = null): void
    {
        $session = $this->current($request);

        if (! $session instanceof AdminSession) {
            return;
        }

        $session->forceFill([
            'last_seen_at' => now(),
            'break_glass_ended_at' => $session->break_glass_expires_at && $session->break_glass_ended_at === null
                ? now()
                : $session->break_glass_ended_at,
        ])->save();

        if ($user instanceof User) {
            $this->auditService->record(
                'logout',
                'Admin session closed.',
                $user,
                target: $session,
                context: ['session_id' => $session->id],
                targetLabel: $session->device_label
            );
        }
    }

    public function markMfaVerified(Request $request, User $user): AdminSession
    {
        $request->session()->put('admin_auth.mfa_verified_at', now()->toIso8601String());
        $request->session()->put('admin_auth.login_verified_at', now()->toIso8601String());
        $user->forceFill(['mfa_last_verified_at' => now()])->save();

        $session = $this->syncCurrentSession($request, $user);
        $session->forceFill(['mfa_verified_at' => now()])->save();

        return $session;
    }

    public function markPasswordConfirmed(Request $request): void
    {
        $request->session()->put('admin_auth.password_confirmed_at', now()->toIso8601String());
    }

    public function passwordConfirmedAt(Request $request): ?string
    {
        return $request->session()->get('admin_auth.password_confirmed_at');
    }

    public function mfaConfirmedAt(Request $request): ?string
    {
        return $request->session()->get('admin_auth.mfa_verified_at');
    }

    /**
     * @return Collection<int, AdminSession>
     */
    public function listForUser(User $user): Collection
    {
        return AdminSession::query()
            ->where('user_id', $user->id)
            ->orderByDesc('last_seen_at')
            ->get();
    }

    public function revokeSession(AdminSession $session, User $actor, ?string $reason = null): AdminSession
    {
        if ($session->revoked_at !== null) {
            return $session;
        }

        $session->forceFill([
            'revoked_at' => now(),
            'revoked_by_user_id' => $actor->id,
        ])->save();

        $this->auditService->record(
            'session_revoked',
            'Session revoked.',
            $actor,
            target: $session,
            severity: 'warning',
            context: [
                'session_id' => $session->id,
                'reason' => $reason,
                'subject_user_id' => $session->user_id,
            ],
            targetLabel: $session->device_label
        );

        return $session;
    }

    public function revokeUserSessions(
        User $subject,
        User $actor,
        bool $all = true,
        ?string $exceptSessionId = null,
        ?string $reason = null,
    ): int {
        $query = AdminSession::query()
            ->where('user_id', $subject->id)
            ->whereNull('revoked_at');

        if (! $all && $exceptSessionId !== null) {
            $query->where('id', '!=', $exceptSessionId);
        }

        $count = 0;

        foreach ($query->get() as $session) {
            $this->revokeSession($session, $actor, $reason);
            $count++;
        }

        return $count;
    }

    public function startBreakGlass(Request $request, User $actor, string $reason, \DateTimeInterface $expiresAt): AdminSession
    {
        $request->session()->put('admin_auth.break_glass', [
            'reason' => $reason,
            'started_at' => now()->toIso8601String(),
            'expires_at' => $expiresAt->format(DATE_ATOM),
        ]);

        $session = $this->syncCurrentSession($request, $actor);
        $session->forceFill([
            'break_glass_reason' => $reason,
            'break_glass_started_at' => now(),
            'break_glass_expires_at' => $expiresAt,
            'break_glass_ended_at' => null,
        ])->save();

        return $session;
    }

    public function endBreakGlass(Request $request, User $actor, ?string $reason = null): ?AdminSession
    {
        $request->session()->forget('admin_auth.break_glass');
        $session = $this->current($request);

        if (! $session instanceof AdminSession) {
            return null;
        }

        $session->forceFill([
            'break_glass_ended_at' => now(),
            'break_glass_reason' => $reason ?: $session->break_glass_reason,
        ])->save();

        return $session;
    }

    public function expireBreakGlassIfNeeded(Request $request, User $actor): void
    {
        $session = $this->current($request);

        if (! $session instanceof AdminSession || $session->break_glass_expires_at === null || $session->break_glass_ended_at !== null) {
            return;
        }

        if ($session->break_glass_expires_at->isFuture()) {
            return;
        }

        $this->endBreakGlass($request, $actor, $session->break_glass_reason);

        $this->auditService->record(
            'break_glass_ended',
            'Break-glass window expired.',
            $actor,
            target: $session,
            severity: 'warning',
            context: ['session_id' => $session->id],
            targetLabel: $session->device_label
        );
    }

    public function suspiciousIpCount(User $user): int
    {
        return (int) AdminSession::query()
            ->where('user_id', $user->id)
            ->where('last_seen_at', '>=', now()->subDay())
            ->distinct('ip_address')
            ->count('ip_address');
    }

    private function deviceLabel(?string $userAgent): string
    {
        $agent = trim((string) $userAgent);

        if ($agent === '') {
            return 'Unknown device';
        }

        return mb_strimwidth($agent, 0, 72, '...');
    }
}
