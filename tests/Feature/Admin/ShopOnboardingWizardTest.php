<?php

namespace Tests\Feature\Admin;

use App\Actions\Admin\Shops\BootstrapShopForPilotAction;
use App\Contracts\Feeds\FeedBuildServiceInterface;
use App\Models\Shop;
use App\Models\SourceConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAdminContext;
use Tests\TestCase;

class ShopOnboardingWizardTest extends TestCase
{
    use CreatesAdminContext;
    use RefreshDatabase;

    public function test_onboarding_steps_render_and_progress_persists(): void
    {
        $admin = User::factory()->create([
            'shop_id' => null,
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.onboarding.show'))
            ->assertOk()
            ->assertSee('Shop Onboarding')
            ->assertSee('Create or edit shop');

        $this->actingAs($admin)
            ->put(route('admin.onboarding.shop'), [
                'name' => 'Pilot Shop',
                'slug' => 'pilot-shop',
                'currency' => 'UAH',
                'locale' => 'uk',
                'timezone' => 'Europe/Kiev',
                'is_active' => '1',
            ])
            ->assertRedirect(route('admin.onboarding.show'));

        $admin = $admin->fresh();

        $this->actingAs($admin)
            ->post(route('admin.onboarding.source-driver'), [
                'driver' => SourceConnection::DRIVER_PROM_YML,
            ])
            ->assertRedirect(route('admin.onboarding.show'));

        $admin = $admin->fresh();

        $shop = Shop::query()->where('slug', 'pilot-shop')->firstOrFail();
        $this->createSourceConnection($shop, [
            'name' => 'Pilot Source',
            'code' => 'pilot-source',
            'source_url' => base_path('tests/Fixtures/prom_sample.yml'),
        ]);

        $this->actingAs($admin->fresh()->forceFill(['shop_id' => $shop->id]))
            ->get(route('admin.onboarding.show'))
            ->assertOk()
            ->assertSee('Pilot Shop')
            ->assertSee('Pilot Source')
            ->assertSee('completed');
    }

    public function test_onboarding_shows_blocking_reason_when_source_connection_is_missing(): void
    {
        $admin = User::factory()->create([
            'shop_id' => null,
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->put(route('admin.onboarding.shop'), [
                'name' => 'Pilot Shop',
                'slug' => 'pilot-shop',
                'currency' => 'UAH',
                'locale' => 'uk',
                'timezone' => 'Europe/Kiev',
                'is_active' => '1',
            ]);

        $this->actingAs($admin->fresh())
            ->get(route('admin.onboarding.show'))
            ->assertOk()
            ->assertSee('Source connection is not configured yet.');
    }

    public function test_release_center_step_is_not_repeated_after_it_was_opened(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));

        ['admin' => $admin, 'feedProfile' => $feedProfile] = $this->seedBuildableCatalog();
        app(FeedBuildServiceInterface::class)->build($feedProfile);
        app(BootstrapShopForPilotAction::class)->buildReleaseCandidate($admin, $feedProfile);

        $this->actingAs($admin)
            ->get(route('admin.feed-profiles.release-center', $feedProfile))
            ->assertOk();

        $this->actingAs($admin)
            ->get(route('admin.onboarding.show'))
            ->assertOk()
            ->assertDontSee('Open the release center and continue with approval or publish.');
    }

    public function test_go_live_control_panel_renders_for_current_shop(): void
    {
        ['admin' => $admin] = $this->seedBuildableCatalog();

        $this->actingAs($admin)
            ->get(route('admin.shop-control.show'))
            ->assertOk()
            ->assertSee('Go-Live Control')
            ->assertSee('Source Status')
            ->assertSee('Release State');
    }
}
