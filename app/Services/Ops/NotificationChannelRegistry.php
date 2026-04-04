<?php

namespace App\Services\Ops;

use App\Contracts\Ops\NotificationChannelDriver;
use App\Models\OpsNotificationRoute;
use App\Services\Ops\Channels\DatabaseNotificationChannel;
use App\Services\Ops\Channels\LogNotificationChannel;
use App\Services\Ops\Channels\MailNotificationChannel;
use App\Services\Ops\Channels\WebhookNotificationChannel;
use RuntimeException;

class NotificationChannelRegistry
{
    public function __construct(
        private readonly DatabaseNotificationChannel $database,
        private readonly LogNotificationChannel $log,
        private readonly MailNotificationChannel $mail,
        private readonly WebhookNotificationChannel $webhook,
    ) {}

    public function driver(string $channel): NotificationChannelDriver
    {
        return match ($channel) {
            OpsNotificationRoute::CHANNEL_DATABASE => $this->database,
            OpsNotificationRoute::CHANNEL_LOG => $this->log,
            OpsNotificationRoute::CHANNEL_EMAIL => $this->mail,
            OpsNotificationRoute::CHANNEL_WEBHOOK => $this->webhook,
            default => throw new RuntimeException('Unsupported notification channel ['.$channel.'].'),
        };
    }
}
