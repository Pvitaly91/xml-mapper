<?php

namespace App\Services\Ops\Channels;

use App\Contracts\Ops\NotificationChannelDriver;
use App\Data\Ops\OpsNotificationChannelResult;
use App\Data\Ops\OpsNotificationMessage;
use App\Models\OpsNotificationDelivery;
use Illuminate\Support\Facades\Log;

class LogNotificationChannel implements NotificationChannelDriver
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
    ): OpsNotificationChannelResult {
        $target = (array) ($route['target'] ?? []);
        $channel = (string) ($target['channel'] ?? config('feed_mediator.notifications.defaults.log_channel', config('logging.default')));
        $level = match ($message->severity) {
            'critical', 'error', 'high' => 'error',
            'warning', 'medium' => 'warning',
            default => 'info',
        };

        Log::channel($channel)->log($level, $message->title, (array) $rendered['log_context']);

        return new OpsNotificationChannelResult(
            status: OpsNotificationDelivery::STATUS_DELIVERED,
            summary: 'Notification logged to ['.$channel.'].',
            responseMeta: [
                'channel' => $channel,
                'level' => $level,
            ],
        );
    }
}
