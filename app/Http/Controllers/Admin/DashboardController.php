<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\Dashboard\BuildDashboardMetricsAction;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends AdminController
{
    public function __invoke(Request $request, BuildDashboardMetricsAction $action): View
    {
        $shop = $this->adminShop($request);

        return view('admin.dashboard', [
            'shop' => $shop,
            'metrics' => $action->handle($shop),
        ]);
    }
}
