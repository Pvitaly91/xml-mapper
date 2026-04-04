<?php

namespace App\Services\Ops\Channels;

use App\Contracts\Ops\NotificationChannelDriver;
use App\Data\Ops\OpsNotificationChannelResult;
use App\Data\Ops\OpsNotificationMessage;
use App\Models\OpsNotificationDelivery;
use App\Support\SensitiveDataRedactor;
use Illuminate\Http\Client\Factory as HttpFactory;
use Throwable;

class WebhookNotificationChannel implements NotificationChannelDriver
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly SensitiveDataRedactor $redactor,
    ) {}

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
        $url = (string) ($target['url'] ?? '');

        if ($url === '') {
            return new OpsNotificationChannelResult(
                status: OpsNotificationDelivery::STATUS_DROPPED,
                summary: 'Webhook URL is missing.',
            );
        }

        $headers = array_filter((array) ($target['headers'] ?? []), fn ($value) => filled($value));
        $timeout = (int) ($route['policy']['timeout_seconds'] ?? config('feed_mediator.notifications.webhook.timeout_seconds', 5));

        try {
            $response = $this->http
                ->timeout($timeout)
                ->withHeaders($headers)
                ->post($url, (array) $rendered['webhook']);

            if (! $response->successful()) {
                return new OpsNotificationChannelResult(
                    status: OpsNotificationDelivery::STATUS_FAILED,
                    summary: 'Webhook returned HTTP '.$response->status().'.',
                    errorMessage: 'Webhook returned HTTP '.$response->status().'.',
                    responseMeta: [
                        'status' => $response->status(),
                        'url' => $this->redactor->redactUrl($url),
                    ],
                );
            }

            return new OpsNotificationChannelResult(
                status: OpsNotificationDelivery::STATUS_DELIVERED,
                summary: 'Webhook delivered successfully.',
                responseMeta: [
                    'status' => $response->status(),
                    'url' => $this->redactor->redactUrl($url),
                ],
            );
        } catch (Throwable $exception) {
            return new OpsNotificationChannelResult(
                status: OpsNotificationDelivery::STATUS_FAILED,
                summary: 'Webhook delivery failed.',
                errorMessage: $exception->getMessage(),
                responseMeta: [
                    'url' => $this->redactor->redactUrl($url),
                ],
            );
        }
    }
}
