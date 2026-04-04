<?php

namespace Tests\Feature\Admin;

use App\Models\ShopMembership;
use App\Models\User;
use App\Services\Governance\MembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAdminContext;
use Tests\TestCase;

class AccessGovernanceCenterTest extends TestCase
{
    use CreatesAdminContext;
    use RefreshDatabase;

    public function test_platform_admin_can_view_other_shop_resources_and_compliance_exports(): void
    {
        $primaryShop = $this->createShop(['slug' => 'primary-shop']);
        $secondaryShop = $this->createShop(['slug' => 'secondary-shop']);
        $platformAdmin = $this->createPlatformAdminUser(['email' => 'platform@example.com']);
        $connection = $this->createSourceConnection($secondaryShop, ['code' => 'secondary-source']);
        $user = User::factory()->create([
            'shop_id' => $secondaryShop->id,
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
            'email' => 'review-target@example.com',
        ]);

        app(MembershipService::class)->grant([
            'user_id' => $user->id,
            'shop_id' => $secondaryShop->id,
            'role' => ShopMembership::ROLE_OPERATOR,
            'status' => ShopMembership::STATUS_ACTIVE,
            'note' => 'Initial production operator access.',
        ], $platformAdmin);

        $this->actingAs($platformAdmin)
            ->get(route('admin.source-connections.show', $connection))
            ->assertOk()
            ->assertSee($connection->name);

        $this->actingAs($platformAdmin)
            ->withSession(['admin_shop_id' => $primaryShop->id])
            ->get(route('admin.access.index'))
            ->assertOk()
            ->assertSee('Access Center');

        $this->actingAs($platformAdmin)
            ->withSession(['admin_shop_id' => $secondaryShop->id])
            ->get(route('admin.access.compliance'))
            ->assertOk()
            ->assertSee('Compliance Report')
            ->assertSee('membership_granted');

        $this->actingAs($platformAdmin)
            ->withSession(['admin_shop_id' => $secondaryShop->id])
            ->get(route('admin.access.compliance.export', ['shop_id' => $secondaryShop->id]))
            ->assertOk()
            ->assertDownload();
    }

    public function test_role_permissions_and_revoked_memberships_are_enforced(): void
    {
        $shop = $this->createShop(['slug' => 'rbac-shop']);
        $observer = User::factory()->create([
            'shop_id' => $shop->id,
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
            'email' => 'observer@example.com',
        ]);
        $operator = User::factory()->create([
            'shop_id' => $shop->id,
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
            'email' => 'operator@example.com',
        ]);
        $reviewer = User::factory()->create([
            'shop_id' => $shop->id,
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
            'email' => 'reviewer@example.com',
        ]);
        $revoked = User::factory()->create([
            'shop_id' => $shop->id,
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
            'email' => 'revoked@example.com',
        ]);

        $this->grantMembership($observer, $shop, ShopMembership::ROLE_OBSERVER);
        $this->grantMembership($operator, $shop, ShopMembership::ROLE_OPERATOR);
        $this->grantMembership($reviewer, $shop, ShopMembership::ROLE_REVIEWER);
        $this->grantMembership($revoked, $shop, ShopMembership::ROLE_SHOP_ADMIN, ShopMembership::STATUS_REVOKED);

        $this->actingAs($observer)
            ->get(route('admin.access.index'))
            ->assertForbidden();

        $this->actingAs($operator)
            ->get(route('admin.merchant-launches.index'))
            ->assertOk()
            ->assertSee('Launch Center');

        $this->actingAs($operator)
            ->get(route('admin.access.index'))
            ->assertForbidden();

        $this->actingAs($reviewer)
            ->get(route('admin.access.index'))
            ->assertOk()
            ->assertSee('Pending Approval Queue');

        $this->actingAs($revoked)
            ->get(route('admin.dashboard'))
            ->assertForbidden();
    }
}
