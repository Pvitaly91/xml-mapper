<?php

namespace App\Services\Admin;

use App\Models\Shop;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CurrentAdminShopResolver
{
    public function resolve(Request|User|null $subject): ?Shop
    {
        $user = $subject instanceof Request ? $subject->user() : $subject;

        return $user?->shop;
    }

    public function require(Request|User|null $subject): Shop
    {
        $shop = $this->resolve($subject);

        if ($shop === null) {
            throw new HttpException(403, 'Admin user is not assigned to a shop. Open the onboarding wizard or run php artisan admin:bootstrap.');
        }

        return $shop;
    }

    public function ensureOwns(Request|User|null $subject, Model $model): void
    {
        $shop = $this->require($subject);

        if ($model instanceof Shop) {
            abort_unless((int) $model->getKey() === (int) $shop->getKey(), 404);

            return;
        }

        abort_unless((int) $model->getAttribute('shop_id') === (int) $shop->getKey(), 404);
    }

    public function ensureAllOwned(Request|User|null $subject, Model ...$models): void
    {
        foreach ($models as $model) {
            $this->ensureOwns($subject, $model);
        }
    }
}
