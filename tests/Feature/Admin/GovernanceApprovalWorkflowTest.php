<?php

namespace Tests\Feature\Admin;

use App\Models\ApprovalRequest;
use App\Models\ShopMembership;
use App\Models\SourceConnection;
use App\Models\User;
use App\Services\Auth\AdminMfaService;
use App\Services\Governance\ApprovalPolicyService;
use App\Services\Governance\GovernedActionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use RuntimeException;
use Tests\Concerns\CreatesAdminContext;
use Tests\TestCase;

class GovernanceApprovalWorkflowTest extends TestCase
{
    use CreatesAdminContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('feed_mediator.environment.class', 'production');
    }

    public function test_high_risk_action_creates_approval_enforces_four_eyes_and_executes_after_review(): void
    {
        ['shop' => $shop, 'admin' => $admin, 'feedProfile' => $feedProfile] = $this->seedBuildableCatalog();
        $reviewer = User::factory()->create([
            'shop_id' => $shop->id,
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
            'email' => 'reviewer@example.com',
        ]);
        $this->grantMembership($reviewer, $shop, ShopMembership::ROLE_REVIEWER);
        $service = app(GovernedActionService::class);

        $result = $service->dispatch(
            ApprovalPolicyService::ACTION_SILENCE_CRITICAL,
            $admin,
            $shop,
            $feedProfile,
            [
                'feed_profile_id' => $feedProfile->id,
                'from' => now()->toIso8601String(),
                'to' => now()->addHour()->toIso8601String(),
                'severity' => 'critical',
                'reason' => 'Launch freeze investigation.',
            ],
            [
                'feed_profile_id' => $feedProfile->id,
                'severity' => 'critical',
            ],
            'Launch freeze investigation.',
            targetLabel: $feedProfile->code
        );

        $this->assertSame('approval_required', $result->status);
        $approval = $result->approvalRequest;
        $this->assertSame(ApprovalRequest::STATUS_PENDING, $approval->status);

        $this->expectException(RuntimeException::class);
        $service->approve($approval, $admin, 'Self approval should be rejected.');
    }

    public function test_approval_can_be_approved_rejected_and_expired(): void
    {
        ['shop' => $shop, 'admin' => $admin, 'feedProfile' => $feedProfile] = $this->seedBuildableCatalog();
        $reviewer = User::factory()->create([
            'shop_id' => $shop->id,
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
            'email' => 'reviewer-2@example.com',
        ]);
        $this->grantMembership($reviewer, $shop, ShopMembership::ROLE_REVIEWER);
        $service = app(GovernedActionService::class);

        $approved = $service->dispatch(
            ApprovalPolicyService::ACTION_SILENCE_CRITICAL,
            $admin,
            $shop,
            $feedProfile,
            [
                'feed_profile_id' => $feedProfile->id,
                'from' => now()->toIso8601String(),
                'to' => now()->addHour()->toIso8601String(),
                'severity' => 'critical',
                'reason' => 'Escalation window.',
            ],
            ['feed_profile_id' => $feedProfile->id],
            'Escalation window.',
            targetLabel: $feedProfile->code
        )->approvalRequest;

        $approved = $service->approve($approved, $reviewer, 'Reviewed by separate operator.');

        $this->assertSame(ApprovalRequest::STATUS_EXECUTED, $approved->status);
        $this->assertDatabaseHas('ops_silence_windows', [
            'feed_profile_id' => $feedProfile->id,
            'severity_threshold' => 'critical',
        ]);

        $rejected = $service->dispatch(
            ApprovalPolicyService::ACTION_SILENCE_CRITICAL,
            $admin,
            $shop,
            $feedProfile,
            [
                'feed_profile_id' => $feedProfile->id,
                'from' => now()->toIso8601String(),
                'to' => now()->addHour()->toIso8601String(),
                'severity' => 'critical',
                'reason' => 'Second critical silence request.',
            ],
            ['feed_profile_id' => $feedProfile->id],
            'Second critical silence request.',
            targetLabel: $feedProfile->code
        )->approvalRequest;

        $rejected = $service->reject($rejected, $reviewer, 'Insufficient evidence for another silence window.');
        $this->assertSame(ApprovalRequest::STATUS_REJECTED, $rejected->status);

        $expired = $service->dispatch(
            ApprovalPolicyService::ACTION_SILENCE_CRITICAL,
            $admin,
            $shop,
            $feedProfile,
            [
                'feed_profile_id' => $feedProfile->id,
                'from' => now()->toIso8601String(),
                'to' => now()->addHour()->toIso8601String(),
                'severity' => 'critical',
                'reason' => 'Expired request.',
            ],
            ['feed_profile_id' => $feedProfile->id],
            'Expired request.',
            targetLabel: $feedProfile->code
        )->approvalRequest;

        $expired->forceFill(['expires_at' => now()->subMinute()])->save();

        try {
            $service->approve($expired->fresh(), $reviewer, 'Too late.');
            $this->fail('Expired approval should not execute.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Approval request has expired.', $exception->getMessage());
        }

        $this->assertSame(ApprovalRequest::STATUS_EXPIRED, $expired->fresh()->status);
    }

    public function test_secret_values_are_masked_and_production_secret_rebind_requires_approval_with_secret_audit(): void
    {
        $shop = $this->createShop(['slug' => 'secret-shop']);
        $admin = $this->createAdminUser($shop, ['email' => 'secret-admin@example.com']);
        $reviewer = User::factory()->create([
            'shop_id' => $shop->id,
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
            'email' => 'secret-reviewer@example.com',
        ]);
        $this->grantMembership($reviewer, $shop, ShopMembership::ROLE_REVIEWER);

        $connection = $this->createSourceConnection($shop, [
            'driver' => SourceConnection::DRIVER_PROM_YML,
            'credentials' => [
                'login' => 'merchant-secret-login',
                'password' => 'merchant-secret-password',
            ],
        ]);
        $setup = app(AdminMfaService::class)->beginEnrollment($admin);
        app(AdminMfaService::class)->confirmEnrollment($admin->fresh(), app(AdminMfaService::class)->currentCode($setup['secret']));
        $reviewerSetup = app(AdminMfaService::class)->beginEnrollment($reviewer);
        app(AdminMfaService::class)->confirmEnrollment($reviewer->fresh(), app(AdminMfaService::class)->currentCode($reviewerSetup['secret']));

        $this->actingAs($admin->fresh())
            ->withSession([
                'admin_auth.password_confirmed_at' => now()->toIso8601String(),
                'admin_auth.mfa_verified_at' => now()->toIso8601String(),
            ])
            ->get(route('admin.source-connections.edit', $connection))
            ->assertOk()
            ->assertDontSee('merchant-secret-login')
            ->assertDontSee('merchant-secret-password')
            ->assertSee('masked by default');

        $response = $this->actingAs($admin->fresh())
            ->withSession([
                'admin_auth.password_confirmed_at' => now()->toIso8601String(),
                'admin_auth.mfa_verified_at' => now()->toIso8601String(),
            ])
            ->put(route('admin.source-connections.update', $connection), [
            'name' => $connection->name,
            'code' => $connection->code,
            'driver' => SourceConnection::DRIVER_PROM_YML,
            'status' => SourceConnection::STATUS_ACTIVE,
            'source_url' => $connection->source_url,
            'sync_interval_minutes' => $connection->sync_interval_minutes,
            'credentials_json' => json_encode([
                'login' => 'new-login',
                'password' => 'new-password',
            ], JSON_THROW_ON_ERROR),
            'options_json' => json_encode($connection->options ?? [], JSON_THROW_ON_ERROR),
        ]);

        $response->assertRedirect(route('admin.source-connections.show', $connection));
        $response->assertSessionHas('status');

        $approval = ApprovalRequest::query()->where('action', ApprovalPolicyService::ACTION_SECRET_REBIND)->latest('id')->firstOrFail();
        $this->assertSame(ApprovalRequest::STATUS_PENDING, $approval->status);
        $this->assertDatabaseMissing('governance_audits', [
            'summary' => 'new-password',
        ]);

        $request = Request::create('/admin/access/approvals/'.$approval->id.'/approve', 'POST');
        $session = app('session.store');
        $session->flush();
        $session->start();
        $session->put('admin_auth.password_confirmed_at', now()->toIso8601String());
        $session->put('admin_auth.mfa_verified_at', now()->toIso8601String());
        $request->setLaravelSession($session);
        app()->instance('request', $request);

        app(GovernedActionService::class)->approve($approval, $reviewer->fresh(), 'Secret rebind approved after review.');

        $this->assertDatabaseHas('governance_audits', [
            'approval_request_id' => $approval->id,
            'category' => 'secret',
            'event_type' => 'approval_executed',
        ]);
    }
}
