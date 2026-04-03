<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\Dashboard\BuildDashboardMetricsAction;
use App\Services\Setup\DatabaseSetupInspector;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends AdminController
{
    public function __invoke(
        Request $request,
        BuildDashboardMetricsAction $action,
        DatabaseSetupInspector $databaseSetupInspector
    ): View {
        $shop = null;

        if ($databaseSetupInspector->hasAllTables(['shops']) && $request->user()?->shop_id !== null) {
            $shop = $request->user()->shop;
        }

        return view('admin.dashboard', [
            'shop' => $shop,
            'metrics' => $action->handle($shop),
        ]);
    }
}
