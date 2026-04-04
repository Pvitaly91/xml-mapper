<?php

namespace App\Data\Ops;

use Illuminate\Notifications\Notification;

readonly class OpsNotificationMessage
{
    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $links
     */
    public function __construct(
        public string $eventType,
        public string $eventFamily,
        public string $severity,
        public string $title,
        public string $message,
        public array $context = [],
        public array $links = [],
        public ?int $shopId = null,
        public ?int $feedProfileId = null,
        public ?int $opsAlertId = null,
        public ?int $merchantLaunchId = null,
        public ?int $feedHypercareWindowId = null,
        public ?int $pilotRunId = null,
        public ?string $correlationId = null,
        public ?string $dedupKey = null,
        public bool $isTest = false,
        public ?Notification $databaseNotification = null,
    ) {}
}
