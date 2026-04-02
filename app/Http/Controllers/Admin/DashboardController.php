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
    ): View
    {
        $shop = null;

        if ($databaseSetupInspector->hasAllTables(['shops']) && $request->user()?->shop_id !== null) {
            $shop = $request->user()->shop;
        }

        if ($databaseSetupInspector->dashboardReport()['schema_ready'] && $shop === null) {
            abort(403, 'Admin user is not assigned to a shop. Run php artisan admin:bootstrap.');
        }

        return view('admin.dashboard', [
            'shop' => $shop,
            'metrics' => $action->handle($shop),
        ]);
    }
}
