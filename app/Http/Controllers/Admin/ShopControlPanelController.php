<?php

namespace App\Http\Controllers\Admin;

use App\Services\Shops\ShopControlPanelService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ShopControlPanelController extends AdminController
{
    public function show(Request $request, ShopControlPanelService $service): View
    {
        $shop = $this->adminShop($request);

        return view('admin.shops.control-panel', [
            'shop' => $shop,
            'panel' => $service->summarize($shop),
        ]);
    }
}
