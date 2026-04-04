<?php

namespace App\Services\Auth;

use App\Models\Shop;
use App\Models\ShopMembership;
use App\Models\User;
use App\Services\Access\AdminAccessService;
use App\Services\Ops\EnvironmentContextService;
use Illuminate\Validation\Rules\Password;

class AdminAuthPolicyService
{
    public function __construct(
        private readonly EnvironmentContextService $environmentContextService,
        private readonly AdminAccessService $accessService,
    ) {}

    public function passwordRule(): Password
    {
        $rule = Password::min((int) config('feed_mediator.auth.password.min_length', 12));

        if ((bool) config('feed_mediator.auth.password.require_mixed_case', true)) {
            $rule->mixedCase();
        }

        if ((bool) config('feed_mediator.auth.password.require_numbers', true)) {
            $rule->numbers();
        }

        if ((bool) config('feed_mediator.auth.password.require_symbols', false)) {
            $rule->symbols();
        }

        if ((bool) config('feed_mediator.auth.password.uncompromised', false)) {
            $rule->uncompromised();
        }

        return $rule;
    }

    public function inviteExpiryHours(): int
    {
        return (int) config('feed_mediator.auth.invites.expiry_hours', 72);
    }

    public function loginMaxFailures(): int
    {
        return (int) config('feed_mediator.auth.login.max_failures', 5);
    }

    public function loginLockMinutes(): int
    {
        return (int) config('feed_mediator.auth.login.lock_minutes', 15);
    }

    public function passwordReauthTtlMinutes(): int
    {
        return (int) config('feed_mediator.auth.reauth.password_ttl_minutes', 15);
    }

    public function mfaReauthTtlMinutes(): int
    {
        return (int) config('feed_mediator.auth.reauth.mfa_ttl_minutes', 10);
    }

    public function breakGlassTtlMinutes(): int
    {
        return (int) config('feed_mediator.auth.break_glass.ttl_minutes', 30);
    }

    public function revokeOtherSessionsOnPasswordChange(): bool
    {
        return (bool) config('feed_mediator.auth.sessions.revoke_on_password_change', true);
    }

    public function sessionRetentionDays(): int
    {
        return (int) config('feed_mediator.auth.sessions.retention_days', 30);
    }

    public function loginRedirectRoute(User $user, ?Shop $shop = null): string
    {
        if ($user->requiresPasswordReset()) {
            return 'admin.auth.password-reset.edit';
        }

        if ($this->requiresMfaEnrollment($user, $shop)) {
            return 'admin.auth.mfa.setup';
        }

        if ($this->requiresMfaChallenge($user, $shop)) {
            return 'admin.auth.mfa.challenge.create';
        }

        return 'admin.dashboard';
    }

    public function requiresMfaEnrollment(User $user, ?Shop $shop = null): bool
    {
        if ($user->hasMfaEnabled()) {
            return false;
        }

        if (! $this->mfaEnforcedInCurrentEnvironment()) {
            return false;
        }

        return collect($this->rolesForUser($user, $shop))
            ->intersect($this->requiredMfaRoles())
            ->isNotEmpty();
    }

    public function requiresMfaChallenge(User $user, ?Shop $shop = null): bool
    {
        if (! $user->hasMfaEnabled()) {
            return false;
        }

        return $this->mfaEnforcedInCurrentEnvironment()
            || (bool) config('feed_mediator.auth.mfa.challenge_when_enabled', true)
            || collect($this->rolesForUser($user, $shop))
                ->intersect($this->requiredMfaRoles())
                ->isNotEmpty();
    }

    public function stepUpRequiresMfa(string $action, array $approvalRule, User $user, ?Shop $shop = null): bool
    {
        if (! $this->mfaEnforcedInCurrentEnvironment()) {
            return false;
        }

        $actions = array_values(array_filter(array_map(
            static fn ($value) => trim((string) $value),
            explode(',', (string) config('feed_mediator.auth.reauth.mfa_actions_csv', ''))
        )));

        if ($actions !== [] && in_array($action, $actions, true)) {
            return true;
        }

        $classification = (string) ($approvalRule['classification'] ?? '');

        if ($classification === 'high_risk') {
            return true;
        }

        return collect($this->rolesForUser($user, $shop))
            ->intersect($this->requiredMfaRoles())
            ->isNotEmpty()
            && $classification === 'sensitive';
    }

    public function breakGlassRequiresMfa(User $user, ?Shop $shop = null): bool
    {
        if (! $this->mfaEnforcedInCurrentEnvironment()) {
            return false;
        }

        if ((bool) config('feed_mediator.auth.break_glass.require_mfa', true)) {
            return true;
        }

        return collect($this->rolesForUser($user, $shop))
            ->intersect($this->requiredMfaRoles())
            ->isNotEmpty();
    }

    public function mfaIssuer(): string
    {
        return (string) config('feed_mediator.auth.mfa.issuer', config('app.name', 'XML Mapper'));
    }

    public function recoveryCodeCount(): int
    {
        return (int) config('feed_mediator.auth.mfa.recovery_codes', 8);
    }

    public function totpWindow(): int
    {
        return (int) config('feed_mediator.auth.mfa.window', 1);
    }

    /**
     * @return list<string>
     */
    public function requiredMfaRoles(): array
    {
        $roles = array_values(array_filter(array_map(
            static fn ($value) => trim((string) $value),
            explode(',', (string) config('feed_mediator.auth.mfa.required_roles', ShopMembership::ROLE_PLATFORM_ADMIN))
        )));

        return $roles !== [] ? $roles : [ShopMembership::ROLE_PLATFORM_ADMIN];
    }

    /**
     * @return list<string>
     */
    private function rolesForUser(User $user, ?Shop $shop = null): array
    {
        $roles = [];
        $currentRole = $this->accessService->roleFor($user, $shop);

        if ($currentRole !== null) {
            $roles[] = $currentRole;
        }

        foreach ($this->accessService->memberships($user) as $membership) {
            $roles[] = $membership->role;
        }

        return array_values(array_unique(array_filter($roles)));
    }

    private function mfaEnforcedInCurrentEnvironment(): bool
    {
        $environment = $this->environmentContextService->summary();

        if ($environment['is_production']) {
            return true;
        }

        return (bool) config('feed_mediator.auth.mfa.enforce_non_production', false);
    }
}
