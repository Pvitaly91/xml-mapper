<?php

namespace Tests\Feature\Demo;

use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class DemoBootstrapE2eCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_bootstrap_command_builds_reproducible_safe_summary_and_manifest(): void
    {
        config()->set('app.url', 'http://127.0.0.1:8101');
        $manifestPath = storage_path('app/e2e/demo-manifest.json');
        $summaryPath = storage_path('app/e2e/demo-summary.json');

        File::delete([$manifestPath, $summaryPath]);

        $status = Artisan::call('demo:bootstrap-e2e', ['--json' => true]);

        $this->assertSame(0, $status);
        $this->assertFileExists($manifestPath);
        $this->assertFileExists($summaryPath);

        $output = Artisan::output();
        $summary = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $manifest = json_decode(File::get($manifestPath), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('e2e-main-shop', data_get($summary, 'shops.main.slug'));
        $this->assertSame('platform-admin@e2e.test', data_get($summary, 'users.platform_admin.email'));
        $this->assertSame('reviewer@e2e.test', data_get($summary, 'users.reviewer.email'));
        $this->assertSame(route('login'), data_get($summary, 'paths.login'));
        $this->assertStringEndsWith('/__e2e/mock-webhook/success', (string) data_get($manifest, 'fixtures.mock_webhook_success'));
        $this->assertNotEmpty(data_get($manifest, 'users.platform_admin.totp_secret'));
        $this->assertNotEmpty(data_get($manifest, 'users.reviewer.totp_secret'));
        $this->assertNotEmpty(data_get($manifest, 'users.invited_shop_admin.accept_url'));
        $this->assertStringNotContainsString('PlatformAdminPass123!', $output);
        $this->assertStringNotContainsString('totp_secret', $output);

        $this->assertDatabaseHas('shops', ['slug' => 'e2e-main-shop']);
        $this->assertDatabaseHas('users', ['email' => 'platform-admin@e2e.test']);
        $this->assertDatabaseHas('users', ['email' => 'reviewer@e2e.test']);

        $this->assertSame(2, Shop::query()->count());
        $this->assertGreaterThanOrEqual(4, User::query()->count());
    }
}
