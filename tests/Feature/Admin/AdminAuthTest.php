<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAdminContext;
use Tests\TestCase;

class AdminAuthTest extends TestCase
{
    use CreatesAdminContext;
    use RefreshDatabase;

    public function test_admin_routes_are_protected_for_guests(): void
    {
        $response = $this->get(route('admin.dashboard'));

        $response->assertRedirect(route('login'));
    }

    public function test_only_active_admin_users_can_access_admin_dashboard(): void
    {
        $shop = $this->createShop();
        $admin = $this->createAdminUser($shop);
        $regularUser = User::factory()->create([
            'shop_id' => $shop->id,
            'role' => 'manager',
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Dashboard');

        $this->actingAs($regularUser)
            ->get(route('admin.dashboard'))
            ->assertForbidden();
    }
}
