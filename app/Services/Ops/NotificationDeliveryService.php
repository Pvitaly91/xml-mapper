<?php

namespace App\Services\Ops;

use App\Data\Ops\OpsNotificationMessage;
use App\Models\OpsAlert;
use App\Models\OpsNotificationDelivery;
use App\Models\OpsNotificationRoute;
use App\Support\SensitiveDataRedactor;
use Illuminate\Support\Collection;

class NotificationDeliveryService
{
    public function __construct(
        private readonly CorrelationContext $correlationContext,
        private readonly NotificationRoutingService $routingService,
        private readonly NotificationRenderService $renderService,
        private readonly NotificationChannelRegistry $registry,
        private readonly SensitiveDataRedactor $redactor,
    ) {}

    /**
     * @return Collection<int, OpsNotificationDelivery>
     */
    public function dispatch(OpsNotificationMessage $message): Collection
    {
        $message = $this->withCorrelation($message);
        $routes = $this->routingService->routesFor($message);

        return $this->dispatchWithRoutes($message, $routes->all());
    }

    /**
     * @param  array<int, array<string, mixed>>  $routes
     * @return Collection<int, OpsNotificationDelivery>
     */
    public function dispatchWithRoutes(OpsNotificationMessage $message, array $routes): Collection
    {
        $message = $this->withCorrelation($message);
        $routes = collect($routes);

        if ($routes->isEmpty()) {
            return collect([
                $this->recordDropped($message, 'No matching notification routes were found.'),
            ]);
        }

        return $routes->map(function (array $route) use ($message): OpsNotificationDelivery {
            if ($suppressionReason = $this->routingService->suppressionReason($route)) {
                return $this->recordSuppressed($message, $route, $suppressionReason);
            }

            if ($repeatReason = $this->repeatSuppressionReason($message, $route)) {
                return $this->recordSuppressed($message, $route, $repeatReason);
            }

            $rendered = $this->renderService->render($message, $route);
            $delivery = OpsNotificationDelivery::create([
                'ops_notification_route_id' => $route['route_id'] ?? null,
                'shop_id' => $message->shopId,
                'feed_profile_id' => $message->feedProfileId,
                'ops_alert_id' => $message->opsAlertId,
                'merchant_launch_id' => $message->merchantLaunchId,
                'feed_hypercare_window_id' => $message->feedHypercareWindowId,
                'pilot_run_id' => $message->pilotRunId,
                'event_family' => $message->eventFamily,
                'event_type' => $message->eventType,
                'severity' => $message->severity,
                'channel' => $route['channel'],
                'target_label' => $route['target_label'] ?? null,
                'status' => OpsNotificationDelivery::STATUS_PENDING,
                'attempts' => 0,
                'is_test' => $message->isTest,
                'correlation_id' => $message->correlationId,
                'dedup_key' => $this->dedupKey($message),
                'summary' => $rendered['summary'] ?? $message->title,
                'rendered_payload' => json_encode($rendered, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
                'started_at' => now(),
                'meta' => [
                    'route' => [
                        'name' => $route['name'] ?? null,
                        'scope' => $route['scope'] ?? null,
                        'policy' => $route['policy'] ?? [],
                        'target' => $this->storableTarget($route),
                    ],
                ],
            ]);

            $this->syncAlertNotificationState($message->opsAlertId, OpsAlert::NOTIFICATION_PENDING, [
                'last_delivery_id' => $delivery->id,
            ]);

            return $this->attempt($delivery, $message, $route, $rendered);
        })->values();
    }

    public function retry(OpsNotificationDelivery $delivery): OpsNotificationDelivery
    {
        $route = $this->routeForRetry($delivery);
        $payload = json_decode((string) $delivery->rendered_payload, true) ?: [];
        $message = $this->withCorrelation(new OpsNotificationMessage(
            eventType: $delivery->event_type,
            eventFamily: (string) ($delivery->event_family ?? '*'),
            severity: $delivery->severity,
            title: (string) ($delivery->summary ?: $delivery->event_type),
            message: (string) data_get($payload, 'database.message', $delivery->summary ?: $delivery->event_type),
            context: (array) data_get($payload, 'database.context', []),
            links: (array) data_get($payload, 'database.links', []),
            shopId: $delivery->shop_id,
            feedProfileId: $delivery->feed_profile_id,
            opsAlertId: $delivery->ops_alert_id,
            merchantLaunchId: $delivery->merchant_launch_id,
            feedHypercareWindowId: $delivery->feed_hypercare_window_id,
            pilotRunId: $delivery->pilot_run_id,
            correlationId: $delivery->correlation_id,
            dedupKey: $delivery->dedup_key,
            isTest: $delivery->is_test,
        ));

        return $this->attempt($delivery, $message, $route, $payload);
    }

    /**
     * @return Collection<int, OpsNotificationDelivery>
     */
    public function dispatchPending(): Collection
    {
        return OpsNotificationDelivery::query()
            ->whereIn('status', [OpsNotificationDelivery::STATUS_PENDING, OpsNotificationDelivery::STATUS_FAILED])
            ->where(function ($query): void {
                $query->whereNull('next_retry_at')->orWhere('next_retry_at', '<=', now());
            })
            ->orderBy('id')
            ->get()
            ->map(fn (OpsNotificationDelivery $delivery) => $this->retry($delivery));
    }

    public function markAlertState(OpsAlert $alert, string $state, ?string $reason = null): void
    {
        $alert->forceFill([
            'notification_state' => $state,
            'notification_meta' => array_merge($alert->notification_meta ?? [], [
                'state_reason' => $reason,
                'updated_at' => now()->toIso8601String(),
            ]),
            'notification_acknowledged_at' => $state === OpsAlert::NOTIFICATION_ACKNOWLEDGED ? now() : $alert->notification_acknowledged_at,
            'notification_resolved_at' => $state === OpsAlert::NOTIFICATION_RESOLVED ? now() : $alert->notification_resolved_at,
            'notification_escalated_at' => $state === OpsAlert::NOTIFICATION_ESCALATED ? now() : $alert->notification_escalated_at,
        ])->save();
    }

    private function attempt(
        OpsNotificationDelivery $delivery,
        OpsNotificationMessage $message,
        array $route,
        array $rendered
    ): OpsNotificationDelivery {
        $result = $this->registry
            ->driver($delivery->channel)
            ->send($delivery, $message, $route, $rendered);

        $attempts = $delivery->attempts + 1;
        $maxAttempts = max(1, (int) ($route['policy']['max_attempts'] ?? 1));
        $backoff = array_values((array) ($route['policy']['backoff_seconds'] ?? []));
        $nextRetry = null;

        if ($result->status === OpsNotificationDelivery::STATUS_FAILED && $attempts < $maxAttempts) {
            $delay = (int) ($backoff[$attempts - 1] ?? end($backoff) ?: 60);
            $nextRetry = now()->addSeconds($delay);
        }

        $delivery->forceFill([
            'status' => $result->status,
            'attempts' => $attempts,
            'summary' => $result->summary ?: $delivery->summary,
            'last_error' => $result->errorMessage,
            'delivered_at' => $result->status === OpsNotificationDelivery::STATUS_DELIVERED ? now() : $delivery->delivered_at,
            'failed_at' => $result->status === OpsNotificationDelivery::STATUS_FAILED ? now() : $delivery->failed_at,
            'next_retry_at' => $nextRetry,
            'response_meta' => $this->redactor->redactArray($result->responseMeta),
        ])->save();

        if ($delivery->route) {
            $delivery->route->forceFill([
                'last_delivery_at' => now(),
                'last_delivery_status' => $delivery->status,
                'last_test_succeeded_at' => $delivery->is_test && $delivery->status === OpsNotificationDelivery::STATUS_DELIVERED
                    ? now()
                    : $delivery->route->last_test_succeeded_at,
                'last_test_failed_at' => $delivery->is_test && $delivery->status === OpsNotificationDelivery::STATUS_FAILED
                    ? now()
                    : $delivery->route->last_test_failed_at,
            ])->save();
        }

        $alertState = match (true) {
            str_ends_with($message->eventType, '.escalated') => OpsAlert::NOTIFICATION_ESCALATED,
            $delivery->status === OpsNotificationDelivery::STATUS_DELIVERED => OpsAlert::NOTIFICATION_DELIVERED,
            OpsNotificationDelivery::STATUS_SUPPRESSED => OpsAlert::NOTIFICATION_SUPPRESSED,
            OpsNotificationDelivery::STATUS_ESCALATED => OpsAlert::NOTIFICATION_ESCALATED,
            OpsNotificationDelivery::STATUS_DROPPED => OpsAlert::NOTIFICATION_DROPPED,
            default => OpsAlert::NOTIFICATION_PENDING,
        };
        $this->syncAlertNotificationState($message->opsAlertId, $alertState, [
            'last_delivery_id' => $delivery->id,
            'last_status' => $delivery->status,
        ]);

        return $delivery->fresh(['route', 'alert', 'feedProfile', 'launch', 'hypercareWindow']);
    }

    private function repeatSuppressionReason(OpsNotificationMessage $message, array $route): ?string
    {
        $dedupKey = $this->dedupKey($message);

        if ($dedupKey === null) {
            return null;
        }

        $last = OpsNotificationDelivery::query()
            ->where('dedup_key', $dedupKey)
            ->where('channel', (string) $route['channel'])
            ->where('target_label', (string) ($route['target_label'] ?? ''))
            ->latest('id')
            ->first();

        if (! $last instanceof OpsNotificationDelivery) {
            return null;
        }

        $suppressionWindow = (int) ($route['policy']['suppression_window_minutes'] ?? 15);
        $repeatWindow = (int) ($route['policy']['repeat_interval_minutes'] ?? 30);
        $happenedAt = $last->delivered_at ?? $last->created_at;

        if ($last->status === OpsNotificationDelivery::STATUS_PENDING && $last->created_at?->gte(now()->subMinutes($suppressionWindow))) {
            return 'Previous delivery is still pending.';
        }

        if (in_array($last->status, [
            OpsNotificationDelivery::STATUS_DELIVERED,
            OpsNotificationDelivery::STATUS_SUPPRESSED,
            OpsNotificationDelivery::STATUS_ESCALATED,
        ], true) && $happenedAt?->gte(now()->subMinutes($repeatWindow))) {
            return 'Repeat interval is still active.';
        }

        return null;
    }

    private function recordSuppressed(OpsNotificationMessage $message, array $route, string $reason): OpsNotificationDelivery
    {
        $delivery = OpsNotificationDelivery::create([
            'ops_notification_route_id' => $route['route_id'] ?? null,
            'shop_id' => $message->shopId,
            'feed_profile_id' => $message->feedProfileId,
            'ops_alert_id' => $message->opsAlertId,
            'merchant_launch_id' => $message->merchantLaunchId,
            'feed_hypercare_window_id' => $message->feedHypercareWindowId,
            'pilot_run_id' => $message->pilotRunId,
            'event_family' => $message->eventFamily,
            'event_type' => $message->eventType,
            'severity' => $message->severity,
            'channel' => (string) ($route['channel'] ?? 'unknown'),
            'target_label' => $route['target_label'] ?? null,
            'status' => OpsNotificationDelivery::STATUS_SUPPRESSED,
            'attempts' => 0,
            'is_test' => $message->isTest,
            'correlation_id' => $message->correlationId,
            'dedup_key' => $this->dedupKey($message),
            'summary' => $message->title,
            'last_error' => $reason,
            'meta' => [
                'route' => [
                    'name' => $route['name'] ?? null,
                    'scope' => $route['scope'] ?? null,
                ],
            ],
        ]);

        $this->syncAlertNotificationState($message->opsAlertId, OpsAlert::NOTIFICATION_SUPPRESSED, [
            'last_delivery_id' => $delivery->id,
            'reason' => $reason,
        ]);

        return $delivery;
    }

    private function recordDropped(OpsNotificationMessage $message, string $reason): OpsNotificationDelivery
    {
        $delivery = OpsNotificationDelivery::create([
            'shop_id' => $message->shopId,
            'feed_profile_id' => $message->feedProfileId,
            'ops_alert_id' => $message->opsAlertId,
            'merchant_launch_id' => $message->merchantLaunchId,
            'feed_hypercare_window_id' => $message->feedHypercareWindowId,
            'pilot_run_id' => $message->pilotRunId,
            'event_family' => $message->eventFamily,
            'event_type' => $message->eventType,
            'severity' => $message->severity,
            'channel' => 'unrouted',
            'status' => OpsNotificationDelivery::STATUS_DROPPED,
            'attempts' => 0,
            'is_test' => $message->isTest,
            'correlation_id' => $message->correlationId,
            'dedup_key' => $this->dedupKey($message),
            'summary' => $message->title,
            'last_error' => $reason,
        ]);

        $this->syncAlertNotificationState($message->opsAlertId, OpsAlert::NOTIFICATION_DROPPED, [
            'last_delivery_id' => $delivery->id,
            'reason' => $reason,
        ]);

        return $delivery;
    }

    /**
     * @return array<string, mixed>
     */
    private function routeForRetry(OpsNotificationDelivery $delivery): array
    {
        if ($delivery->route instanceof OpsNotificationRoute) {
            return [
                'route_id' => $delivery->route->id,
                'name' => $delivery->route->name,
                'scope' => $delivery->route->scope,
                'channel' => $delivery->route->channel,
                'target' => (array) ($delivery->route->target ?? []),
                'target_label' => $delivery->route->target_label,
                'policy' => array_merge((array) data_get($delivery->meta, 'route.policy', []), (array) ($delivery->route->policy ?? [])),
            ];
        }

        return [
            'route_id' => null,
            'name' => data_get($delivery->meta, 'route.name'),
            'scope' => data_get($delivery->meta, 'route.scope'),
            'channel' => $delivery->channel,
            'target' => (array) data_get($delivery->meta, 'route.target', []),
            'target_label' => $delivery->target_label,
            'policy' => (array) data_get($delivery->meta, 'route.policy', []),
        ];
    }

    private function dedupKey(OpsNotificationMessage $message): ?string
    {
        return $message->dedupKey !== null && $message->dedupKey !== ''
            ? $message->eventType.'|'.$message->dedupKey
            : ($message->opsAlertId !== null ? $message->eventType.'|alert:'.$message->opsAlertId : null);
    }

    /**
     * @return array<string, mixed>
     */
    private function storableTarget(array $route): array
    {
        return $route['channel'] === OpsNotificationRoute::CHANNEL_WEBHOOK && ($route['route_id'] ?? null) === null
            ? ['url' => $this->redactor->redactUrl((string) data_get($route, 'target.url'))]
            : (array) ($route['target'] ?? []);
    }

    private function withCorrelation(OpsNotificationMessage $message): OpsNotificationMessage
    {
        $correlationId = $this->correlationContext->ensure($message->correlationId);

        return new OpsNotificationMessage(
            eventType: $message->eventType,
            eventFamily: $message->eventFamily,
            severity: $message->severity,
            title: $message->title,
            message: $message->message,
            context: $message->context,
            links: $message->links,
            shopId: $message->shopId,
            feedProfileId: $message->feedProfileId,
            opsAlertId: $message->opsAlertId,
            merchantLaunchId: $message->merchantLaunchId,
            feedHypercareWindowId: $message->feedHypercareWindowId,
            pilotRunId: $message->pilotRunId,
            correlationId: $correlationId,
            dedupKey: $message->dedupKey,
            isTest: $message->isTest,
            databaseNotification: $message->databaseNotification,
        );
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function syncAlertNotificationState(?int $alertId, string $state, array $meta = []): void
    {
        if ($alertId === null) {
            return;
        }

        $alert = OpsAlert::find($alertId);

        if (! $alert instanceof OpsAlert) {
            return;
        }

        $alert->forceFill([
            'notification_state' => $state,
            'notification_last_delivery_at' => now(),
            'notification_suppressed_at' => $state === OpsAlert::NOTIFICATION_SUPPRESSED ? now() : $alert->notification_suppressed_at,
            'notification_escalated_at' => $state === OpsAlert::NOTIFICATION_ESCALATED ? now() : $alert->notification_escalated_at,
            'notification_meta' => array_merge($alert->notification_meta ?? [], $meta),
        ])->save();
    }
}
