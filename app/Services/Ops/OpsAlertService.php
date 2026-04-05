<?php

namespace App\Services\Ops;

use App\Data\Ops\OpsNotificationMessage;
use App\Models\FeedGeneration;
use App\Models\FeedHypercareWindow;
use App\Models\FeedProfile;
use App\Models\OpsAlert;
use App\Models\Shop;
use App\Models\SourceConnection;
use App\Models\SyncLog;
use App\Models\User;
use App\Notifications\OpsAlertNotification;
use App\Services\Feeds\FeedReleaseAuditService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

class OpsAlertService
{
    public function __construct(
        private readonly FeedReleaseAuditService $auditService,
        private readonly SilenceWindowService $silenceWindowService,
        private readonly NotificationDeliveryService $deliveryService,
        private readonly CorrelationContext $correlationContext,
        private readonly OpsStructuredLogService $structuredLogService,
    ) {}

    public function raiseForProfile(
        FeedProfile $feedProfile,
        string $source,
        string $severity,
        string $title,
        string $message,
        array $context = [],
        ?FeedGeneration $generation = null,
        ?FeedHypercareWindow $hypercare = null,
        ?string $fingerprint = null
    ): OpsAlert {
        $hypercare ??= $feedProfile->currentHypercareWindow()->first();
        $fingerprint ??= $source;
        $existing = OpsAlert::query()
            ->where('feed_profile_id', $feedProfile->id)
            ->where('fingerprint', $fingerprint)
            ->whereNotIn('state', [OpsAlert::STATE_RESOLVED, OpsAlert::STATE_FALSE_POSITIVE])
            ->latest('id')
            ->first();
        $silenceWindow = $this->silenceWindowService->current($feedProfile);
        $silenced = $silenceWindow?->silencesSeverity($severity) ?? false;
        $state = $silenced
            ? OpsAlert::STATE_SILENCED
            : ($existing?->state === OpsAlert::STATE_ACKNOWLEDGED ? OpsAlert::STATE_ACKNOWLEDGED : OpsAlert::STATE_RAISED);
        $now = now();
        $correlationId = $this->correlationContext->ensure();
        $stateChanged = false;
        $wasNew = ! $existing instanceof OpsAlert;

        if (! $existing instanceof OpsAlert) {
            $alert = OpsAlert::create([
                'shop_id' => $feedProfile->shop_id,
                'feed_profile_id' => $feedProfile->id,
                'feed_generation_id' => $generation?->id ?? $hypercare?->feed_generation_id,
                'source_connection_id' => $feedProfile->source_connection_id,
                'feed_hypercare_window_id' => $hypercare?->id,
                'source' => $source,
                'state' => $state,
                'severity' => $severity,
                'fingerprint' => $fingerprint,
                'correlation_id' => $correlationId,
                'title' => $title,
                'message' => $message,
                'reason' => $silenced ? 'Silenced by maintenance window.' : null,
                'silenced_at' => $silenced ? $now : null,
                'first_raised_at' => $now,
                'last_raised_at' => $now,
                'last_reviewed_at' => $now,
                'context' => $context,
            ]);
            $stateChanged = true;
        } else {
            $stateChanged = $existing->state !== $state
                || $existing->severity !== $severity
                || $existing->title !== $title
                || $existing->message !== $message;

            $existing->forceFill([
                'feed_generation_id' => $generation?->id ?? $existing->feed_generation_id,
                'feed_hypercare_window_id' => $hypercare?->id ?? $existing->feed_hypercare_window_id,
                'source_connection_id' => $feedProfile->source_connection_id,
                'severity' => $severity,
                'state' => $state,
                'title' => $title,
                'message' => $message,
                'correlation_id' => $correlationId,
                'reason' => $silenced ? 'Silenced by maintenance window.' : $existing->reason,
                'silenced_at' => $silenced ? $now : null,
                'last_raised_at' => $now,
                'last_reviewed_at' => $now,
                'context' => array_merge($existing->context ?? [], $context),
            ])->save();

            $alert = $existing->fresh();
        }

        if ($alert->severity === OpsAlert::SEVERITY_CRITICAL) {
            $this->degradeHypercare($hypercare ?? $feedProfile->currentHypercareWindow()->first());
        }

        $this->logAlert($feedProfile, $alert, $silenced ? 'ops.alert.silenced' : 'ops.alert.raised');

        if ($wasNew || $stateChanged) {
            $this->recordAlertEvent(
                $feedProfile,
                $generation,
                $silenced ? 'hypercare_alert_silenced' : 'hypercare_alert_raised',
                $alert
            );
        }

        if (! $silenced && ($wasNew || $stateChanged)) {
            $this->deliveryService->dispatch($this->messageForAlert($alert, 'ops.alert.'.$alert->state));
        }

        $this->structuredLogService->{$this->severityMethod($severity)}('ops_alert', $message, [
            'alert_id' => $alert->id,
            'feed_profile_id' => $feedProfile->id,
            'source' => $source,
            'state' => $state,
            'silenced' => $silenced,
        ]);

        return $alert;
    }

    public function resolveFingerprint(
        FeedProfile $feedProfile,
        string $fingerprint,
        ?string $reason = null,
        ?User $user = null
    ): ?OpsAlert {
        $alert = OpsAlert::query()
            ->where('feed_profile_id', $feedProfile->id)
            ->where('fingerprint', $fingerprint)
            ->whereNotIn('state', [OpsAlert::STATE_RESOLVED, OpsAlert::STATE_FALSE_POSITIVE])
            ->latest('id')
            ->first();

        if (! $alert instanceof OpsAlert) {
            return null;
        }

        return $this->resolve($alert, $reason ?: 'Condition recovered.', null, $user);
    }

    /**
     * @return Collection<int, OpsAlert>
     */
    public function syncConnectionAlert(
        SourceConnection $connection,
        string $source,
        ?string $severity,
        string $title,
        string $message,
        array $context = []
    ): Collection {
        $profiles = $connection->feedProfiles()
            ->with(['currentHypercareWindow', 'publishedGeneration', 'latestGeneration'])
            ->where('status', FeedProfile::STATUS_ACTIVE)
            ->get();

        return $profiles->map(function (FeedProfile $profile) use ($source, $severity, $title, $message, $context) {
            if ($severity === null) {
                return $this->resolveFingerprint($profile, $source, 'Source condition recovered.');
            }

            return $this->raiseForProfile(
                $profile,
                $source,
                $severity,
                $title,
                $message,
                $context,
                $profile->publishedGeneration ?? $profile->latestGeneration,
                $profile->currentHypercareWindow,
                $source
            );
        })->filter();
    }

    public function acknowledge(OpsAlert $alert, string $reason, ?string $note = null, ?User $user = null): OpsAlert
    {
        $alert->forceFill([
            'state' => OpsAlert::STATE_ACKNOWLEDGED,
            'reason' => $reason,
            'note' => $note,
            'acknowledged_by_user_id' => $user?->id,
            'acknowledged_at' => now(),
            'last_reviewed_at' => now(),
        ])->save();

        $this->recordAlertEvent($alert->feedProfile, $alert->feedGeneration, 'hypercare_alert_acknowledged', $alert, $user);
        $this->deliveryService->markAlertState($alert->fresh(), OpsAlert::NOTIFICATION_ACKNOWLEDGED, $reason);

        return $alert->fresh();
    }

    public function resolve(
        OpsAlert $alert,
        string $reason,
        ?string $note = null,
        ?User $user = null,
        bool $falsePositive = false
    ): OpsAlert {
        $state = $falsePositive ? OpsAlert::STATE_FALSE_POSITIVE : OpsAlert::STATE_RESOLVED;

        $alert->forceFill([
            'state' => $state,
            'reason' => $reason,
            'note' => $note,
            'resolved_by_user_id' => $user?->id,
            'resolved_at' => now(),
            'false_positive_at' => $falsePositive ? now() : $alert->false_positive_at,
            'last_reviewed_at' => now(),
        ])->save();

        $this->recordAlertEvent(
            $alert->feedProfile,
            $alert->feedGeneration,
            $falsePositive ? 'hypercare_alert_false_positive' : 'hypercare_alert_resolved',
            $alert,
            $user
        );
        $this->deliveryService->markAlertState($alert->fresh(), OpsAlert::NOTIFICATION_RESOLVED, $reason);

        return $alert->fresh();
    }

    /**
     * @return Collection<int, OpsAlert>
     */
    public function escalateDue(?Shop $shop = null, ?FeedProfile $feedProfile = null): Collection
    {
        $minutes = (int) config('feed_mediator.hypercare.alerts.escalate_after_minutes', 15);
        $alerts = OpsAlert::query()
            ->when($shop !== null, fn ($query) => $query->where('shop_id', $shop->id))
            ->when($feedProfile !== null, fn ($query) => $query->where('feed_profile_id', $feedProfile->id))
            ->whereIn('state', [OpsAlert::STATE_RAISED])
            ->where('last_raised_at', '<=', now()->subMinutes($minutes))
            ->get();

        foreach ($alerts as $alert) {
            $alert->forceFill([
                'state' => OpsAlert::STATE_ESCALATED,
                'escalation_level' => min(9, $alert->escalation_level + 1),
                'escalated_at' => now(),
                'last_reviewed_at' => now(),
            ])->save();

            $this->degradeHypercare($alert->hypercareWindow);
            $this->recordAlertEvent($alert->feedProfile, $alert->feedGeneration, 'hypercare_alert_escalated', $alert);
            $this->deliveryService->dispatch($this->messageForAlert($alert->fresh(), 'ops.alert.escalated'));
        }

        return $alerts;
    }

    /**
     * @return EloquentCollection<int, OpsAlert>
     */
    public function openAlertsForProfile(FeedProfile $feedProfile, ?FeedHypercareWindow $hypercare = null)
    {
        return OpsAlert::query()
            ->where('feed_profile_id', $feedProfile->id)
            ->when($hypercare !== null, fn ($query) => $query->where('feed_hypercare_window_id', $hypercare->id))
            ->whereNotIn('state', [OpsAlert::STATE_RESOLVED, OpsAlert::STATE_FALSE_POSITIVE])
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @return EloquentCollection<int, OpsAlert>
     */
    public function blockingAlertsForProfile(FeedProfile $feedProfile, ?FeedHypercareWindow $hypercare = null)
    {
        return OpsAlert::query()
            ->where('feed_profile_id', $feedProfile->id)
            ->when($hypercare !== null, fn ($query) => $query->where('feed_hypercare_window_id', $hypercare->id))
            ->where('severity', OpsAlert::SEVERITY_CRITICAL)
            ->whereNotIn('state', [OpsAlert::STATE_RESOLVED, OpsAlert::STATE_FALSE_POSITIVE])
            ->orderByDesc('id')
            ->get();
    }

    private function degradeHypercare(?FeedHypercareWindow $hypercare): void
    {
        if (! $hypercare instanceof FeedHypercareWindow || $hypercare->isTerminal()) {
            return;
        }

        if ($hypercare->status !== FeedHypercareWindow::STATUS_DEGRADED) {
            $hypercare->forceFill([
                'status' => FeedHypercareWindow::STATUS_DEGRADED,
                'escalation_level' => max(1, (int) $hypercare->escalation_level),
            ])->save();
        }
    }

    private function recordAlertEvent(
        ?FeedProfile $feedProfile,
        ?FeedGeneration $generation,
        string $action,
        OpsAlert $alert,
        ?User $user = null
    ): void {
        if (! $feedProfile instanceof FeedProfile) {
            return;
        }

        $this->auditService->record(
            $feedProfile,
            $generation ?? $feedProfile->publishedGeneration ?? $feedProfile->latestGeneration,
            $action,
            $user,
            $alert->reason,
            [
                'alert_id' => $alert->id,
                'alert_source' => $alert->source,
                'severity' => $alert->severity,
                'state' => $alert->state,
                'fingerprint' => $alert->fingerprint,
                'title' => $alert->title,
            ]
        );
    }

    private function logAlert(FeedProfile $feedProfile, OpsAlert $alert, string $event): void
    {
        SyncLog::create([
            'shop_id' => $feedProfile->shop_id,
            'source_connection_id' => $feedProfile->source_connection_id,
            'feed_profile_id' => $feedProfile->id,
            'feed_generation_id' => $alert->feed_generation_id,
            'level' => match ($alert->severity) {
                OpsAlert::SEVERITY_CRITICAL => 'error',
                OpsAlert::SEVERITY_WARNING => 'warning',
                default => 'info',
            },
            'event' => $event,
            'message' => $alert->message,
            'context' => [
                'alert_id' => $alert->id,
                'source' => $alert->source,
                'state' => $alert->state,
                'severity' => $alert->severity,
                'fingerprint' => $alert->fingerprint,
                'correlation_id' => $alert->correlation_id,
            ],
            'occurred_at' => now(),
        ]);
    }

    /**
     * @return Collection<int, User>
     */
    private function activeAdmins(?FeedProfile $feedProfile): Collection
    {
        if (! $feedProfile instanceof FeedProfile) {
            return collect();
        }

        $feedProfile->loadMissing('shop.users');

        return $feedProfile->shop?->users
            ? $feedProfile->shop->users->where('role', User::ROLE_ADMIN)->where('is_active', true)->values()
            : collect();
    }

    private function messageForAlert(OpsAlert $alert, string $eventType): OpsNotificationMessage
    {
        return new OpsNotificationMessage(
            eventType: $eventType,
            eventFamily: $this->eventFamilyForSource($alert->source, $alert->severity),
            severity: $alert->severity,
            title: $alert->title,
            message: $alert->message,
            context: array_merge($alert->context ?? [], [
                'alert_state' => $alert->state,
                'alert_reason' => $alert->reason,
                'alert_note' => $alert->note,
                'alert_source' => $alert->source,
            ]),
            shopId: $alert->shop_id,
            feedProfileId: $alert->feed_profile_id,
            opsAlertId: $alert->id,
            feedHypercareWindowId: $alert->feed_hypercare_window_id,
            correlationId: $alert->correlation_id,
            dedupKey: $alert->fingerprint,
            databaseNotification: new OpsAlertNotification($alert),
        );
    }

    private function eventFamilyForSource(string $source, string $severity): string
    {
        return match ($source) {
            OpsAlert::SOURCE_SOURCE_AUTH_BROKEN => 'source_auth_broken',
            OpsAlert::SOURCE_SYNC_FAILURE => 'sync_failed',
            OpsAlert::SOURCE_BUILD_FAILURE => 'build_failed',
            OpsAlert::SOURCE_PUBLISH_FAILURE => 'publish_failed',
            OpsAlert::SOURCE_SMOKE_CHECK_FAILURE => 'smoke_failed',
            OpsAlert::SOURCE_FIRST_PULL_FAILURE => 'first_pull_failed',
            OpsAlert::SOURCE_REJECTION_SPIKE => 'rejection_spike',
            OpsAlert::SOURCE_PERFORMANCE_BUDGET => 'launch_degraded',
            default => $severity === OpsAlert::SEVERITY_CRITICAL ? 'hypercare_critical_issue' : '*',
        };
    }

    private function severityMethod(string $severity): string
    {
        return match ($severity) {
            OpsAlert::SEVERITY_CRITICAL => 'error',
            OpsAlert::SEVERITY_WARNING => 'warning',
            default => 'info',
        };
    }
}
