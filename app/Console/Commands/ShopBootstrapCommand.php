<?php

namespace App\Console\Commands;

use App\Actions\Admin\Shops\BootstrapShopForPilotAction;
use App\Models\Shop;
use App\Models\ShopMembership;
use App\Models\SourceConnection;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ShopBootstrapCommand extends Command
{
    protected $signature = 'shop:bootstrap
        {--email=pilot@example.com : Admin email}
        {--password= : Admin password}
        {--name=Pilot Admin : Admin display name}
        {--shop-name=Demo Shop : Shop name}
        {--shop-slug=demo-shop : Shop slug}
        {--driver=prom_yml : Source driver}
        {--source-url= : Source URL or local path for Prom YML}
        {--api-base-url= : API base URL for Prom API}
        {--api-token= : API token for Prom API}
        {--run-sync : Run source sync after bootstrap}';

    protected $description = 'Bootstrap a local/demo shop, admin user, source connection, and default pilot profile.';

    public function handle(BootstrapShopForPilotAction $action): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('shop:bootstrap is only allowed in local or testing environments.');

            return self::FAILURE;
        }

        $password = (string) ($this->option('password') ?: Str::password(16));
        $shop = Shop::query()->updateOrCreate(
            ['slug' => (string) $this->option('shop-slug')],
            [
                'name' => (string) $this->option('shop-name'),
                'currency' => 'UAH',
                'locale' => 'uk',
                'timezone' => config('app.timezone'),
                'is_active' => true,
            ]
        );

        $user = User::query()->updateOrCreate(
            ['email' => (string) $this->option('email')],
            [
                'shop_id' => $shop->id,
                'name' => (string) $this->option('name'),
                'password' => $password,
                'role' => User::ROLE_ADMIN,
                'is_active' => true,
            ]
        );

        ShopMembership::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'shop_id' => $shop->id,
            ],
            [
                'role' => ShopMembership::ROLE_SHOP_ADMIN,
                'status' => ShopMembership::STATUS_ACTIVE,
            ]
        );

        $driver = (string) $this->option('driver');
        $connection = SourceConnection::query()->updateOrCreate(
            [
                'shop_id' => $shop->id,
                'code' => 'bootstrap-source',
            ],
            [
                'name' => 'Bootstrap Source',
                'driver' => $driver,
                'status' => SourceConnection::STATUS_ACTIVE,
                'source_url' => $driver === SourceConnection::DRIVER_PROM_YML
                    ? ((string) $this->option('source-url') ?: base_path('tests/Fixtures/prom_sample.yml'))
                    : null,
                'api_base_url' => $driver === SourceConnection::DRIVER_PROM_API
                    ? ((string) $this->option('api-base-url') ?: SourceConnection::defaultPromApiBaseUrl())
                    : null,
                'api_token' => $driver === SourceConnection::DRIVER_PROM_API
                    ? ((string) $this->option('api-token') ?: null)
                    : null,
                'api_version' => $driver === SourceConnection::DRIVER_PROM_API
                    ? SourceConnection::defaultPromApiVersion()
                    : null,
                'sync_interval_minutes' => 60,
                'last_connection_check_status' => SourceConnection::CHECK_STATUS_OK,
                'last_sync_status' => SourceConnection::CHECK_STATUS_OK,
                'last_synced_at' => now(),
                'next_sync_at' => now()->addHour(),
            ]
        );

        $summary = $action->bootstrap($user->fresh(), (bool) $this->option('run-sync'), false);

        $this->info('Demo shop bootstrap finished.');
        $this->line('Shop: '.$shop->name.' (#'.$shop->id.')');
        $this->line('Admin email: '.$user->email);
        $this->line('Admin password: '.$password);
        $this->line('Source connection: '.$connection->name.' (#'.$connection->id.')');
        $this->line('Feed profile: #'.$summary['feed_profile_id']);

        return self::SUCCESS;
    }
}
