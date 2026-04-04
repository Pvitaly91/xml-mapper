<?php

namespace App\Services\Ops;

use App\Data\Ops\OpsNotificationMessage;
use App\Support\SensitiveDataRedactor;

class NotificationRenderService
{
    public function __construct(
        private readonly SensitiveDataRedactor $redactor,
    ) {}

    /**
     * @param  array<string, mixed>  $route
     * @return array<string, mixed>
     */
    public function render(OpsNotificationMessage $message, array $route): array
    {
        $context = $this->redactor->redactArray($message->context);
        $subject = sprintf('[%s] %s', mb_strtoupper($message->severity), $message->title);

        return [
            'summary' => $message->title,
            'subject' => $subject,
            'lines' => array_values(array_filter([
                $message->message,
                'Event: '.$message->eventType,
                $message->eventFamily !== '' ? 'Family: '.$message->eventFamily : null,
                $message->correlationId ? 'Correlation: '.$message->correlationId : null,
                $message->feedProfileId ? 'Feed profile: #'.$message->feedProfileId : null,
                $message->opsAlertId ? 'Alert: #'.$message->opsAlertId : null,
                $message->merchantLaunchId ? 'Launch: #'.$message->merchantLaunchId : null,
            ])),
            'database' => [
                'event' => $message->eventType,
                'family' => $message->eventFamily,
                'severity' => $message->severity,
                'title' => $message->title,
                'message' => $message->message,
                'context' => $context,
                'links' => $message->links,
                'correlation_id' => $message->correlationId,
            ],
            'webhook' => [
                'event_type' => $message->eventType,
                'event_family' => $message->eventFamily,
                'severity' => $message->severity,
                'title' => $message->title,
                'message' => $message->message,
                'context' => $context,
                'links' => $message->links,
                'correlation_id' => $message->correlationId,
                'target' => $route['target_label'] ?? null,
            ],
            'log_context' => array_merge($context, [
                'event_type' => $message->eventType,
                'event_family' => $message->eventFamily,
                'severity' => $message->severity,
                'correlation_id' => $message->correlationId,
                'feed_profile_id' => $message->feedProfileId,
                'ops_alert_id' => $message->opsAlertId,
                'merchant_launch_id' => $message->merchantLaunchId,
                'feed_hypercare_window_id' => $message->feedHypercareWindowId,
            ]),
        ];
    }
}
