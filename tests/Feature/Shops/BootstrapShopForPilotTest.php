<?php

namespace Tests\Feature\Shops;

use App\Actions\Admin\Shops\BootstrapShopForPilotAction;
use App\Models\FeedGeneration;
use App\Models\SourceConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAdminContext;
use Tests\TestCase;

class BootstrapShopForPilotTest extends TestCase
{
    use CreatesAdminContext;
    use RefreshDatabase;

    public function test_bootstrap_creates_sensible_defaults_and_is_idempotent(): void
    {
        $shop = $this->createShop(['slug' => 'bootstrap-shop']);
        $admin = $this->createAdminUser($shop, ['email' => 'bootstrap@example.com']);
        $this->createSourceConnection($shop, [
            'code' => 'bootstrap-source',
            'source_url' => base_path('tests/Fixtures/prom_sample.yml'),
        ]);

        $action = app(BootstrapShopForPilotAction::class);
        $action->bootstrap($admin->fresh(), false, false);
        $action->bootstrap($admin->fresh(), false, false);

        $this->assertSame(1, $shop->fresh()->feedProfiles()->count());
        $profile = $shop->fresh()->feedProfiles()->firstOrFail();

        $this->assertTrue($profile->publishGuardEnabled());
        $this->assertSame(1, $profile->minimumReadyItems());
        $this->assertSame(0.25, $profile->maximumInvalidRatio());
    }

    public function test_bootstrap_can_run_initial_sync_and_build_candidate(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));

        $shop = $this->createShop(['slug' => 'bootstrap-shop']);
        $admin = $this->createAdminUser($shop, ['email' => 'bootstrap@example.com']);
        $this->createSourceConnection($shop, [
            'code' => 'bootstrap-source',
            'source_url' => base_path('tests/Fixtures/prom_sample.yml'),
            'driver' => SourceConnection::DRIVER_PROM_YML,
        ]);

        $summary = app(BootstrapShopForPilotAction::class)->bootstrap($admin->fresh(), true, true);
        $profile = $shop->fresh()->feedProfiles()->firstOrFail();
        $generation = $profile->latestGeneration;

        $this->assertNotNull($summary['sync_import_id']);
        $this->assertSame(FeedGeneration::RELEASE_STATUS_CANDIDATE, $generation?->release_status);
        $this->assertGreaterThan(0, $generation?->items_total ?? 0);
    }
}
