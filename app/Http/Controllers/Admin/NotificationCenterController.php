<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\Ops\NotificationMuteRequest;
use App\Http\Requests\Admin\Ops\NotificationRouteRequest;
use App\Http\Requests\Admin\Ops\NotificationTestRequest;
use App\Models\OpsNotificationDelivery;
use App\Models\OpsNotificationRoute;
use App\Services\Ops\NotificationCenterService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class NotificationCenterController extends AdminController
{
    public function index(Request $request, NotificationCenterService $service): View
    {
        $shop = $this->adminShop($request);

        return view('admin.notification-center.index', [
            'shop' => $shop,
            'deliveries' => $service->deliveries($shop, $request->all()),
            'routes' => $service->routes($shop),
            'feedProfiles' => $shop->feedProfiles()->orderBy('name')->get(),
            'channelStatus' => $service->channelStatus($shop),
        ]);
    }

    public function show(Request $request, OpsNotificationDelivery $opsNotificationDelivery): View
    {
        $shop = $this->adminShop($request);
        abort_unless((int) $opsNotificationDelivery->shop_id === (int) $shop->id, 404);
        $opsNotificationDelivery->load(['route', 'alert.feedProfile', 'feedProfile', 'launch', 'hypercareWindow']);

        return view('admin.notification-center.show', [
            'delivery' => $opsNotificationDelivery,
            'shop' => $shop,
        ]);
    }

    public function storeRoute(
        NotificationRouteRequest $request,
        NotificationCenterService $service
    ): RedirectResponse {
        $shop = $this->adminShop($request);

        try {
            $service->saveRoute($shop, $request->validated(), null, $request->user());
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Notification route saved.');
    }

    public function updateRoute(
        NotificationRouteRequest $request,
        OpsNotificationRoute $opsNotificationRoute,
        NotificationCenterService $service
    ): RedirectResponse {
        $shop = $this->adminShop($request);
        abort_unless($opsNotificationRoute->shop_id === null || (int) $opsNotificationRoute->shop_id === (int) $shop->id, 404);

        try {
            $service->saveRoute($shop, $request->validated(), $opsNotificationRoute, $request->user());
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Notification route updated.');
    }

    public function testRoute(
        Request $request,
        OpsNotificationRoute $opsNotificationRoute,
        NotificationCenterService $service
    ): RedirectResponse {
        $shop = $this->adminShop($request);
        abort_unless($opsNotificationRoute->shop_id === null || (int) $opsNotificationRoute->shop_id === (int) $shop->id, 404);

        try {
            $delivery = $service->testRoute($opsNotificationRoute);
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Test delivery recorded as #'.$delivery->id.'.');
    }

    public function testChannel(
        NotificationTestRequest $request,
        NotificationCenterService $service
    ): RedirectResponse {
        $shop = $this->adminShop($request);

        try {
            $delivery = $service->testChannel(
                (string) $request->validated('channel'),
                $shop,
                $request->validated('target')
            );
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Test delivery recorded as #'.$delivery->id.'.');
    }

    public function retry(
        Request $request,
        OpsNotificationDelivery $opsNotificationDelivery,
        NotificationCenterService $service
    ): RedirectResponse {
        $shop = $this->adminShop($request);
        abort_unless((int) $opsNotificationDelivery->shop_id === (int) $shop->id, 404);

        try {
            $service->retry($opsNotificationDelivery);
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Delivery retried.');
    }

    public function mute(
        NotificationMuteRequest $request,
        OpsNotificationRoute $opsNotificationRoute,
        NotificationCenterService $service
    ): RedirectResponse {
        $shop = $this->adminShop($request);
        abort_unless($opsNotificationRoute->shop_id === null || (int) $opsNotificationRoute->shop_id === (int) $shop->id, 404);

        try {
            $service->muteRoute($opsNotificationRoute, $request->validated('until'));
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Route muted.');
    }
}
