<?php

namespace App\Services\Feeds;

use App\Data\Ops\OpsNotificationMessage;
use App\Models\FeedGeneration;
use App\Models\FeedProfile;
use App\Models\Shop;
use App\Models\SourceConnection;
use App\Models\SyncLog;
use App\Models\User;
use App\Notifications\PilotEventNotification;
use App\Services\Ops\CorrelationContext;
use App\Services\Ops\NotificationDeliveryService;
use App\Services\Ops\OpsStructuredLogService;
use Illuminate\Support\Collection;

class PilotNotificationService
{
    public function __construct(
        private readonly NotificationDeliveryService $deliveryService,
        private readonly CorrelationContext $correlationContext,
        private readonly OpsStructuredLogService $structuredLogService,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function notifyFeedProfileAdmins(
        FeedProfile $feedProfile,
        string $event,
        string $title,
        string $message,
        array $context = [],
        string $severity = 'warning',
        ?FeedGeneration $generation = null
    ): void {
        $correlationId = $this->correlationContext->ensure();
        $context = array_merge($context, [
            'correlation_id' => $correlationId,
        ]);

        $this->deliveryService->dispatch(new OpsNotificationMessage(
            eventType: $event,
            eventFamily: $this->eventFamily($event, $severity),
            severity: $severity,
            title: $title,
            message: $message,
            context: $context,
            shopId: $feedProfile->shop_id,
            feedProfileId: $feedProfile->id,
            correlationId: $correlationId,
            dedupKey: (string) ($context['dedup_key'] ?? $event),
            databaseNotification: new PilotEventNotification($event, $title, $message, $context, $severity),
        ));

        SyncLog::create([
            'shop_id' => $feedProfile->shop_id,
            'source_connection_id' => $feedProfile->source_connection_id,
            'feed_profile_id' => $feedProfile->id,
            'feed_generation_id' => $generation?->id,
            'level' => match ($severity) {
                'error' => 'error',
                'info' => 'info',
                default => 'warning',
            },
            'event' => $event,
            'message' => $message,
            'context' => $context,
            'occurred_at' => now(),
        ]);

        $this->structuredLogService->{$this->severityMethod($severity)}('pilot_notification', $message, [
            'event' => $event,
            'title' => $title,
            'shop_id' => $feedProfile->shop_id,
            'feed_profile_id' => $feedProfile->id,
            'feed_generation_id' => $generation?->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function notifySourceConnectionAdmins(
        SourceConnection $connection,
        string $event,
        string $title,
        string $message,
        array $context = [],
        string $severity = 'warning'
    ): void {
        $correlationId = $this->correlationContext->ensure();
        $context = array_merge($context, [
            'correlation_id' => $correlationId,
        ]);

        $this->deliveryService->dispatch(new OpsNotificationMessage(
            eventType: $event,
            eventFamily: $this->eventFamily($event, $severity),
            severity: $severity,
            title: $title,
            message: $message,
            context: $context,
            shopId: $connection->shop_id,
            correlationId: $correlationId,
            dedupKey: (string) ($context['dedup_key'] ?? $event),
            databaseNotification: new PilotEventNotification($event, $title, $message, $context, $severity),
        ));

        SyncLog::create([
            'shop_id' => $connection->shop_id,
            'source_connection_id' => $connection->id,
            'level' => match ($severity) {
                'error' => 'error',
                'info' => 'info',
                default => 'warning',
            },
            'event' => $event,
            'message' => $message,
            'context' => $context,
            'occurred_at' => now(),
        ]);

        $this->structuredLogService->{$this->severityMethod($severity)}('pilot_notification', $message, [
            'event' => $event,
            'title' => $title,
            'shop_id' => $connection->shop_id,
            'source_connection_id' => $connection->id,
        ]);
    }

    /**
     * @return Collection<int, User>
     */
    private function activeAdmins(Shop $shop): Collection
    {
        return $shop->users()
            ->where('role', 'admin')
            ->where('is_active', true)
            ->get();
    }

    private function eventFamily(string $event, string $severity): string
    {
        return match (true) {
            str_contains($event, 'source.auth_broken') => 'source_auth_broken',
            str_contains($event, 'publish_failed') || str_contains($event, 'publish_blocked') => 'publish_failed',
            str_contains($event, 'smoke_check_failed') => 'smoke_failed',
            str_contains($event, 'first_pull') => 'first_pull_failed',
            str_contains($event, 'promotion') && (str_contains($event, 'blocked') || $severity === 'error') => 'promotion_blocked',
            str_contains($event, 'signoff') && (str_contains($event, 'blocked') || $severity === 'error') => 'signoff_blocked',
            str_contains($event, 'rollback') => 'rollback_executed',
            str_contains($event, 'launch') => 'launch_degraded',
            str_contains($event, 'feedback') => 'rejection_spike',
            $severity === 'error' => 'hypercare_critical_issue',
            default => '*',
        };
    }

    private function severityMethod(string $severity): string
    {
        return match ($severity) {
            'error', 'critical', 'high' => 'error',
            'warning', 'medium' => 'warning',
            default => 'info',
        };
    }
}
