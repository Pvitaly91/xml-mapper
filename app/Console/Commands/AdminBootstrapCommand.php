<?php

namespace App\Console\Commands;

use App\Models\Shop;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class AdminBootstrapCommand extends Command
{
    protected $signature = 'admin:bootstrap
        {email=admin@example.com : Admin email}
        {--password= : Admin password. Random password will be generated when omitted}
        {--name=Local Admin : Admin display name}
        {--shop-name=Demo Shop : Shop name}
        {--shop-slug=demo-shop : Shop slug}';

    protected $description = 'Create or update a local admin user bound to a shop.';

    public function handle(): int
    {
        $password = $this->option('password') ?: Str::password(16);

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
            ['email' => (string) $this->argument('email')],
            [
                'shop_id' => $shop->id,
                'name' => (string) $this->option('name'),
                'password' => $password,
                'role' => User::ROLE_ADMIN,
                'is_active' => true,
            ]
        );

        $this->info('Admin user is ready.');
        $this->line('Email: '.$user->email);
        $this->line('Password: '.$password);
        $this->line('Shop: '.$shop->name.' (#'.$shop->id.')');

        return self::SUCCESS;
    }
}
