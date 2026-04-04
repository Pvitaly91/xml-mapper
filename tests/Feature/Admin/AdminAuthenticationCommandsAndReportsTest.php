<?php

namespace Tests\Feature\Admin;

use App\Models\AdminInvite;
use App\Models\GovernanceAudit;
use App\Models\ShopMembership;
use App\Services\Auth\AdminAuthAuditService;
use App\Services\Auth\AdminInvitationService;
use App\Services\Auth\AdminMfaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAdminContext;
use Tests\TestCase;

class AdminAuthenticationCommandsAndReportsTest extends TestCase
{
    use CreatesAdminContext;
    use RefreshDatabase;

    public function test_access_and_auth_commands_manage_invites_sessions_and_account_controls(): void
    {
        $shop = $this->createShop();
        $actor = $this->createPlatformAdminUser([
            'email' => 'platform-actor@example.com',
            'password' => 'ActorPass123',
        ]);
        $subject = $this->createAdminUser($shop, [
            'email' => 'subject@example.com',
            'password' => 'SubjectPass123',
        ]);

        $inviteStatus = Artisan::call('access:invite', [
            'email' => 'cli-invite@example.com',
            'role' => ShopMembership::ROLE_REVIEWER,
            '--shop' => $shop->id,
            '--by' => $actor->email,
        ]);
        $this->assertSame(0, $inviteStatus);
        $invitePayload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('cli-invite@example.com', $invitePayload['email']);
        $this->assertNotEmpty($invitePayload['accept_url']);

        $invite = app(AdminInvitationService::class)->createInvite([
            'email' => 'cli-resend@example.com',
            'name' => 'CLI Invite',
            'role' => ShopMembership::ROLE_REVIEWER,
            'shop_id' => $shop->id,
        ], $actor)['invite'];

        $this->artisan('access:resend-invite', [
            'inviteOrMembershipId' => $invite->id,
            '--by' => $actor->email,
        ])->assertSuccessful();

        $this->assertNotNull($invite->fresh()->last_resent_at);

        $setup = app(AdminMfaService::class)->beginEnrollment($subject);
        app(AdminMfaService::class)->confirmEnrollment($subject->fresh(), app(AdminMfaService::class)->currentCode($setup['secret']));

        $sessionId = $this->insertSession($subject);

        $this->artisan('auth:sessions:list', ['user' => $subject->email])
            ->expectsOutputToContain($sessionId)
            ->assertSuccessful();

        $this->artisan('auth:sessions:revoke', [
            'user' => $subject->email,
            '--all' => true,
            '--by' => $actor->email,
            '--reason' => 'Security cleanup',
        ])->assertSuccessful();

        $this->assertNotNull(\DB::table('sessions')->where('id', $sessionId)->value('revoked_at'));

        $this->artisan('access:suspend', [
            'user' => $subject->email,
            '--shop' => $shop->id,
            '--by' => $actor->email,
            '--reason' => 'Temporary block',
        ])->assertSuccessful();

        $this->assertSame(
            ShopMembership::STATUS_SUSPENDED,
            $subject->fresh()->memberships()->where('shop_id', $shop->id)->firstOrFail()->status
        );

        $this->artisan('access:reactivate', [
            'user' => $subject->email,
            '--shop' => $shop->id,
            '--by' => $actor->email,
            '--reason' => 'Recovered',
        ])->assertSuccessful();

        $this->assertSame(
            ShopMembership::STATUS_ACTIVE,
            $subject->fresh()->memberships()->where('shop_id', $shop->id)->firstOrFail()->status
        );

        $this->artisan('auth:force-password-reset', [
            'user' => $subject->email,
            '--by' => $actor->email,
            '--reason' => 'Routine rotation',
        ])->assertSuccessful();

        $this->assertTrue($subject->fresh()->requiresPasswordReset());

        $this->artisan('auth:mfa:reset', [
            'user' => $subject->email,
            '--shop' => $shop->id,
            '--by' => $actor->email,
            '--reason' => 'New authenticator device',
        ])->assertSuccessful();

        $this->assertSame('not_enabled', $subject->fresh()->mfaStatus());
    }

    public function test_auth_audit_report_and_access_center_views_do_not_expose_sensitive_values(): void
    {
        Storage::fake('auth-audit');
        config()->set('feed_mediator.storage_disk', 'auth-audit');

        $shop = $this->createShop();
        $actor = $this->createPlatformAdminUser([
            'email' => 'audit-actor@example.com',
            'password' => 'ActorPass123',
        ]);

        app(AdminAuthAuditService::class)->record(
            'mfa_enrolled',
            'MFA enrollment recorded.',
            $actor,
            $shop,
            target: $actor,
            severity: 'warning',
            context: [
                'mfa_secret' => 'TOP-SECRET-MFA',
                'recovery_code' => 'RCODE-12345',
                'token' => 'raw-token',
            ],
            targetLabel: $actor->email
        );

        $audit = GovernanceAudit::query()->latest('id')->firstOrFail();

        $this->assertSame('[redacted]', data_get($audit->context, 'mfa_secret'));
        $this->assertSame('[redacted]', data_get($audit->context, 'recovery_code'));
        $this->assertSame('[redacted]', data_get($audit->context, 'token'));

        $this->artisan('auth:audit:report', [
            '--shop' => $shop->id,
            '--user' => $actor->id,
        ])->assertSuccessful();

        $files = Storage::disk('auth-audit')->allFiles(config('feed_mediator.governance.reports_directory', 'feeds/runbooks/compliance'));
        $this->assertNotEmpty($files);

        $report = Storage::disk('auth-audit')->get($files[0]);

        $this->assertStringNotContainsString('TOP-SECRET-MFA', $report);
        $this->assertStringNotContainsString('RCODE-12345', $report);
        $this->assertStringNotContainsString('raw-token', $report);

        $this->actingAs($actor)
            ->withSession(['admin_shop_id' => $shop->id])
            ->get(route('admin.access.auth-audit'))
            ->assertOk()
            ->assertDontSee('TOP-SECRET-MFA')
            ->assertDontSee('RCODE-12345')
            ->assertDontSee('raw-token');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function insertSession(\App\Models\User $user, array $overrides = []): string
    {
        $sessionId = (string) ($overrides['id'] ?? \Illuminate\Support\Str::random(40));
        $payload = $overrides['payload'] ?? base64_encode(serialize(['_token' => \Illuminate\Support\Str::random(40)]));

        \DB::table('sessions')->insert(array_merge([
            'id' => $sessionId,
            'user_id' => $user->id,
            'ip_address' => '127.0.0.11',
            'user_agent' => 'CLI Test Session',
            'payload' => $payload,
            'last_activity' => now()->timestamp,
            'created_at' => now()->subMinute(),
            'last_seen_at' => now()->subMinute(),
            'device_label' => 'CLI Test Session',
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
}
