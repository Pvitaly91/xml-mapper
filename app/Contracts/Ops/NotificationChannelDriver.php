<?php

namespace App\Contracts\Ops;

use App\Data\Ops\OpsNotificationChannelResult;
use App\Data\Ops\OpsNotificationMessage;
use App\Models\OpsNotificationDelivery;

interface NotificationChannelDriver
{
    /**
     * @param  array<string, mixed>  $route
     * @param  array<string, mixed>  $rendered
     */
    public function send(
        OpsNotificationDelivery $delivery,
        OpsNotificationMessage $message,
        array $route,
        array $rendered
    ): OpsNotificationChannelResult;
}
