<?php

namespace Tests\Feature\Admin;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesAdminContext;
use Tests\TestCase;

class DashboardSchemaReadinessTest extends TestCase
{
    use CreatesAdminContext;
    use RefreshDatabase;

    public function test_dashboard_opens_when_schema_is_ready(): void
    {
        $shop = $this->createShop();
        $admin = $this->createAdminUser($shop);

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Dashboard')
            ->assertSee('Source products')
            ->assertDontSee('Database Setup Required');
    }

    public function test_dashboard_returns_setup_required_state_when_source_products_table_is_missing(): void
    {
        $shop = $this->createShop();
        $admin = $this->createAdminUser($shop);

        Schema::disableForeignKeyConstraints();
        Schema::drop('validation_errors');
        Schema::drop('feed_items');
        Schema::drop('source_variants');
        Schema::drop('source_products');
        Schema::enableForeignKeyConstraints();

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Database Setup Required')
            ->assertSee('source_products')
            ->assertSee('php artisan migrate')
            ->assertDontSee('SQLSTATE');
    }
}
