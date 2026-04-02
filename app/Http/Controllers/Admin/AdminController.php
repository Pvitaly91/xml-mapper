<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

abstract class AdminController extends Controller
{
    protected function adminShop(Request $request): Shop
    {
        $shop = $request->user()?->shop;

        if ($shop === null) {
            throw new HttpException(403, 'Admin user is not assigned to a shop. Run php artisan admin:bootstrap.');
        }

        return $shop;
    }

    protected function ensureShopOwned(Request $request, Model $model): void
    {
        abort_unless((int) $model->getAttribute('shop_id') === (int) $request->user()->shop_id, 404);
    }
}
