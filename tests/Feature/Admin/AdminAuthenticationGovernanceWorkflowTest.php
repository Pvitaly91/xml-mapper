<?php

namespace Tests\Feature\Admin;

use App\Models\GovernanceAudit;
use App\Models\ShopMembership;
use App\Models\User;
use App\Services\Auth\AdminInvitationService;
use App\Services\Auth\AdminMfaService;
use App\Services\Auth\AdminStepUpAuthService;
use App\Services\Governance\ApprovalPolicyService;
use App\Services\Governance\GovernedActionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesAdminContext;
use Tests\TestCase;

class AdminAuthenticationGovernanceWorkflowTest extends TestCase
{
    use CreatesAdminContext;
    use RefreshDatabase;

    public function test_invite_acceptance_activates_account_and_membership(): void
    {
        $shop = $this->createShop();
        $actor = $this->createAdminUser($shop, ['password' => 'ShopAdminPass123']);
        $service = app(AdminInvitationService::class);
        $result = $service->createInvite([
            'email' => 'invitee@example.com',
            'name' => 'Invited Operator',
            'role' => ShopMembership::ROLE_OPERATOR,
            'shop_id' => $shop->id,
            'note' => 'Pilot operator access',
        ], $actor);

        $invite = $result['invite'];
        $membership = $result['membership'];

        $this->assertSame(ShopMembership::STATUS_INVITED, $membership->status);
        $this->assertSame(User::STATE_INVITED, $invite->user->account_state);

        $this->post(route('admin.invites.accept', $invite->token_ciphertext), [
            'name' => 'Invited Operator',
            'password' => 'InvitePass123',
            'password_confirmation' => 'InvitePass123',
        ])->assertRedirect(route('admin.dashboard'));

        $invite->refresh();
        $membership->refresh();
        $user = $invite->user()->firstOrFail();

        $this->assertSame('accepted', $invite->status);
        $this->assertSame(ShopMembership::STATUS_ACTIVE, $membership->status);
        $this->assertSame(User::STATE_ACTIVE, $user->account_state);
        $this->assertNotNull($user->invite_accepted_at);

        $this->assertDatabaseHas('governance_audits', [
            'category' => GovernanceAudit::CATEGORY_AUTH,
            'event_type' => 'invite_created',
            'user_id' => $actor->id,
        ]);
        $this->assertDatabaseHas('governance_audits', [
            'category' => GovernanceAudit::CATEGORY_AUTH,
            'event_type' => 'invite_accepted',
            'user_id' => $user->id,
        ]);
    }

    public function test_revoked_invite_cannot_be_used_and_suspended_user_cannot_log_in(): void
    {
        $shop = $this->createShop();
        $actor = $this->createAdminUser($shop, ['password' => 'ShopAdminPass123']);
        $service = app(AdminInvitationService::class);
        $result = $service->createInvite([
            'email' => 'revoked@example.com',
            'name' => 'Revoked User',
            'role' => ShopMembership::ROLE_REVIEWER,
            'shop_id' => $shop->id,
        ], $actor);
        $token = $result['invite']->token_ciphertext;

        $invite = $service->revoke($result['invite'], $actor, 'Invite no longer needed');

        $this->post(route('admin.invites.accept', $token), [
            'name' => 'Revoked User',
            'password' => 'AnotherPass123',
            'password_confirmation' => 'AnotherPass123',
        ])->assertSessionHas('error', 'This invite was revoked.');

        $suspended = $this->createAdminUser($shop, [
            'email' => 'suspended@example.com',
            'password' => 'SuspendPass123',
            'account_state' => User::STATE_SUSPENDED,
        ]);

        $this->post(route('admin.login.store'), [
            'email' => $suspended->email,
            'password' => 'SuspendPass123',
        ])->assertSessionHasErrors([
            'email' => 'This account is suspended.',
        ]);
    }

    public function test_password_reset_required_login_redirects_and_password_change_revokes_other_sessions(): void
    {
        $shop = $this->createShop();
        $admin = $this->createAdminUser($shop, [
            'email' => 'reset@example.com',
            'password' => 'OldPassword123',
            'account_state' => User::STATE_PASSWORD_RESET_REQUIRED,
            'password_reset_required_at' => now(),
        ]);

        $this->post(route('admin.login.store'), [
            'email' => $admin->email,
            'password' => 'OldPassword123',
        ])->assertRedirect(route('admin.auth.password-reset.edit'));

        $extraSessionId = $this->insertSession($admin, [
            'device_label' => 'Other browser',
            'last_seen_at' => now()->subMinute(),
        ]);

        $this->put(route('admin.auth.password-reset.update'), [
            'current_password' => 'OldPassword123',
            'password' => 'NewPassword123',
            'password_confirmation' => 'NewPassword123',
        ])->assertRedirect(route('admin.dashboard'));

        $admin->refresh();

        $this->assertTrue($admin->canUseAdminAuthentication());
        $this->assertSame(User::STATE_ACTIVE, $admin->account_state);
        $this->assertNull($admin->password_reset_required_at);
        $this->assertNotNull(DB::table('sessions')->where('id', $extraSessionId)->value('revoked_at'));
    }

    public function test_mfa_enrollment_login_challenge_and_backup_codes_are_one_time(): void
    {
        config()->set('feed_mediator.auth.mfa.challenge_when_enabled', true);
        config()->set('feed_mediator.auth.mfa.enforce_non_production', false);

        $shop = $this->createShop();
        $admin = $this->createAdminUser($shop, [
            'email' => 'mfa@example.com',
            'password' => 'MfaPassword123',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.auth.mfa.setup'))
            ->assertOk()
            ->assertSee('MFA Setup');

        $secret = (string) $admin->fresh()->mfa_pending_secret;
        $code = app(AdminMfaService::class)->currentCode($secret);

        $this->actingAs($admin)
            ->post(route('admin.auth.mfa.enable'), ['code' => $code])
            ->assertRedirect(route('admin.dashboard'))
            ->assertSessionHas('mfa_recovery_codes');

        $recoveryCode = $this->app['session.store']->get('mfa_recovery_codes')[0];

        $this->post(route('admin.logout'))->assertRedirect(route('login'));

        $this->post(route('admin.login.store'), [
            'email' => $admin->email,
            'password' => 'MfaPassword123',
        ])->assertRedirect(route('admin.auth.mfa.challenge.create'));

        $this->post(route('admin.auth.mfa.challenge.store'), [
            'code' => $recoveryCode,
        ])->assertRedirect(route('admin.dashboard'));

        $this->post(route('admin.logout'))->assertRedirect(route('login'));

        $this->post(route('admin.login.store'), [
            'email' => $admin->email,
            'password' => 'MfaPassword123',
        ])->assertRedirect(route('admin.auth.mfa.challenge.create'));

        $this->post(route('admin.auth.mfa.challenge.store'), [
            'code' => $recoveryCode,
        ])->assertSessionHasErrors('code');

        $this->assertDatabaseHas('governance_audits', [
            'category' => GovernanceAudit::CATEGORY_AUTH,
            'event_type' => 'mfa_enrolled',
            'user_id' => $admin->id,
        ]);
        $this->assertDatabaseHas('governance_audits', [
            'category' => GovernanceAudit::CATEGORY_AUTH,
            'event_type' => 'mfa_verified',
            'user_id' => $admin->id,
        ]);
        $this->assertDatabaseHas('governance_audits', [
            'category' => GovernanceAudit::CATEGORY_AUTH,
            'event_type' => 'mfa_failed',
            'user_id' => $admin->id,
        ]);
    }

    public function test_step_up_auth_requires_recent_password_and_mfa_based_on_policy(): void
    {
        config()->set('feed_mediator.environment.class', 'staging');
        config()->set('feed_mediator.auth.mfa.enforce_non_production', false);
        config()->set('feed_mediator.auth.reauth.mfa_actions_csv', 'release.freeze_toggle');

        $shop = $this->createShop();
        $admin = $this->createAdminUser($shop, ['password' => 'FreezePass123']);
        $connection = $this->createSourceConnection($shop);
        $profile = $this->createFeedProfile($connection, $admin);
        $governed = app(GovernedActionService::class);
        $stepUp = app(AdminStepUpAuthService::class);

        $request = $this->bindSecurityRequest([
            'admin_auth.password_confirmed_at' => now()->toIso8601String(),
        ]);
        $result = $governed->dispatch(
            ApprovalPolicyService::ACTION_RELEASE_FREEZE,
            $admin,
            $shop,
            $profile,
            [
                'feed_profile_id' => $profile->id,
                'freeze' => true,
                'reason' => 'Enable freeze in staging',
            ],
            [
                'feed_profile_id' => $profile->id,
                'freeze' => true,
            ],
            'Enable freeze in staging',
            targetLabel: $profile->code
        );

        $this->assertSame('executed', $result->status);

        config()->set('feed_mediator.auth.mfa.enforce_non_production', true);

        $request = $this->bindSecurityRequest([
            'admin_auth.password_confirmed_at' => now()->subMinutes(45)->toIso8601String(),
        ]);
        $result = $governed->dispatch(
            ApprovalPolicyService::ACTION_RELEASE_FREEZE,
            $admin,
            $shop,
            $profile,
            [
                'feed_profile_id' => $profile->id,
                'freeze' => false,
                'reason' => 'Disable freeze without reauth',
            ],
            [
                'feed_profile_id' => $profile->id,
                'freeze' => false,
            ],
            'Disable freeze without reauth',
            targetLabel: $profile->code
        );

        $this->assertSame('password_reauth_required', $result->status);

        $request = $this->bindSecurityRequest();
        $stepUp->confirmPassword($request, $admin, 'FreezePass123');
        $result = $governed->dispatch(
            ApprovalPolicyService::ACTION_RELEASE_FREEZE,
            $admin,
            $shop,
            $profile,
            [
                'feed_profile_id' => $profile->id,
                'freeze' => false,
                'reason' => 'Disable freeze without MFA',
            ],
            [
                'feed_profile_id' => $profile->id,
                'freeze' => false,
            ],
            'Disable freeze without MFA',
            targetLabel: $profile->code
        );

        $this->assertSame('blocked_by_policy', $result->status);

        $setup = app(AdminMfaService::class)->beginEnrollment($admin->fresh());
        $enableCode = app(AdminMfaService::class)->currentCode($setup['secret']);
        app(AdminMfaService::class)->confirmEnrollment($admin->fresh(), $enableCode);

        $request = $this->bindSecurityRequest([
            'admin_auth.password_confirmed_at' => now()->toIso8601String(),
            'admin_auth.mfa_verified_at' => now()->subMinutes(20)->toIso8601String(),
        ]);
        $result = $governed->dispatch(
            ApprovalPolicyService::ACTION_RELEASE_FREEZE,
            $admin->fresh(),
            $shop,
            $profile,
            [
                'feed_profile_id' => $profile->id,
                'freeze' => false,
                'reason' => 'Disable freeze with stale MFA',
            ],
            [
                'feed_profile_id' => $profile->id,
                'freeze' => false,
            ],
            'Disable freeze with stale MFA',
            targetLabel: $profile->code
        );

        $this->assertSame('mfa_reauth_required', $result->status);

        $reauthCode = app(AdminMfaService::class)->currentCode((string) $admin->fresh()->mfa_secret);
        $request = $this->bindSecurityRequest([
            'admin_auth.password_confirmed_at' => now()->toIso8601String(),
            'admin_auth.mfa_verified_at' => now()->subMinutes(20)->toIso8601String(),
        ]);
        $stepUp->confirmMfa($request, $admin->fresh(), $reauthCode);
        $result = $governed->dispatch(
            ApprovalPolicyService::ACTION_RELEASE_FREEZE,
            $admin->fresh(),
            $shop,
            $profile,
            [
                'feed_profile_id' => $profile->id,
                'freeze' => false,
                'reason' => 'Disable freeze after step-up',
            ],
            [
                'feed_profile_id' => $profile->id,
                'freeze' => false,
            ],
            'Disable freeze after step-up',
            targetLabel: $profile->code
        );

        $this->assertSame('executed', $result->status);
    }

    public function test_break_glass_requires_reason_is_audited_and_expires(): void
    {
        config()->set('feed_mediator.auth.break_glass.ttl_minutes', 1);
        config()->set('feed_mediator.auth.break_glass.require_mfa', false);

        $admin = $this->createPlatformAdminUser([
            'email' => 'platform@example.com',
            'password' => 'PlatformPass123',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.auth.break-glass.start'), [])
            ->assertSessionHasErrors('reason');

        $this->actingAs($admin)
            ->withSession(['admin_auth.password_confirmed_at' => now()->toIso8601String()])
            ->post(route('admin.auth.break-glass.start'), [
                'reason' => 'Emergency recovery',
            ])->assertSessionHas('status', 'Break-glass mode started.');

        $this->assertDatabaseHas('governance_audits', [
            'category' => GovernanceAudit::CATEGORY_AUTH,
            'event_type' => 'break_glass_started',
            'user_id' => $admin->id,
        ]);

        $this->travel(2)->minutes();

        $this->actingAs($admin)
            ->withSession([
                'admin_auth.password_confirmed_at' => now()->subMinutes(2)->toIso8601String(),
                'admin_auth.break_glass' => [
                    'reason' => 'Emergency recovery',
                    'started_at' => now()->subMinutes(2)->toIso8601String(),
                    'expires_at' => now()->subMinute()->toIso8601String(),
                ],
            ])
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSessionMissing('admin_auth.break_glass');

        $this->assertDatabaseHas('governance_audits', [
            'category' => GovernanceAudit::CATEGORY_AUTH,
            'event_type' => 'break_glass_ended',
            'user_id' => $admin->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function insertSession(User $user, array $overrides = []): string
    {
        $sessionId = (string) ($overrides['id'] ?? Str::random(40));
        $payload = $overrides['payload'] ?? base64_encode(serialize(['_token' => Str::random(40)]));

        DB::table('sessions')->insert(array_merge([
            'id' => $sessionId,
            'user_id' => $user->id,
            'ip_address' => '127.0.0.10',
            'user_agent' => 'PHPUnit Session',
            'payload' => $payload,
            'last_activity' => now()->timestamp,
            'created_at' => now()->subMinutes(5),
            'last_seen_at' => now()->subMinutes(5),
            'device_label' => 'PHPUnit Session',
            'mfa_verified_at' => null,
            'revoked_at' => null,
            'revoked_by_user_id' => null,
            'break_glass_reason' => null,
            'break_glass_started_at' => null,
            'break_glass_expires_at' => null,
            'break_glass_ended_at' => null,
        ], $overrides));

        return $sessionId;
    }

    /**
     * @param  array<string, mixed>  $sessionData
     */
    private function bindSecurityRequest(array $sessionData = []): Request
    {
        $request = Request::create('/admin/security-check', 'POST');
        $session = app('session.store');
        $session->flush();
        $session->start();

        foreach ($sessionData as $key => $value) {
            $session->put($key, $value);
        }

        $request->setLaravelSession($session);
        app()->instance('request', $request);

        return $request;
    }
}
