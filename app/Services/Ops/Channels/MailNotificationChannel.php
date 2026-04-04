<?php

namespace App\Services\Ops\Channels;

use App\Contracts\Ops\NotificationChannelDriver;
use App\Data\Ops\OpsNotificationChannelResult;
use App\Data\Ops\OpsNotificationMessage;
use App\Models\OpsNotificationDelivery;
use App\Notifications\OpsMailNotification;
use Illuminate\Support\Facades\Notification;

class MailNotificationChannel implements NotificationChannelDriver
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
        $emails = array_values(array_filter((array) data_get($route, 'target.emails', [])));

        if ($emails === []) {
            return new OpsNotificationChannelResult(
                status: OpsNotificationDelivery::STATUS_DROPPED,
                summary: 'No email recipients configured.',
            );
        }

        Notification::route('mail', $emails)
            ->notify(new OpsMailNotification((string) $rendered['subject'], (array) $rendered['lines']));

        return new OpsNotificationChannelResult(
            status: OpsNotificationDelivery::STATUS_DELIVERED,
            summary: 'Email delivered to '.count($emails).' recipient(s).',
            responseMeta: [
                'recipients' => $emails,
            ],
        );
    }
}
