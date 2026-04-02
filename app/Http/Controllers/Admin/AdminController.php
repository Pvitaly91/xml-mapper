<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Services\Admin\CurrentAdminShopResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

abstract class AdminController extends Controller
{
    protected function adminShop(Request $request): Shop
    {
        return app(CurrentAdminShopResolver::class)->require($request);
    }

    protected function ensureShopOwned(Request $request, Model $model): void
    {
        app(CurrentAdminShopResolver::class)->ensureOwns($request, $model);
    }

    protected function currentShop(Request $request): ?Shop
    {
        return app(CurrentAdminShopResolver::class)->resolve($request);
    }
}
