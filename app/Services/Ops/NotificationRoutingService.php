<?php

namespace App\Services\Ops;

use App\Data\Ops\OpsNotificationMessage;
use App\Models\OpsAlert;
use App\Models\OpsNotificationRoute;
use App\Models\User;
use App\Support\SensitiveDataRedactor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class NotificationRoutingService
{
    public function __construct(
        private readonly SensitiveDataRedactor $redactor,
    ) {}

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function routesFor(OpsNotificationMessage $message): Collection
    {
        $routes = OpsNotificationRoute::query()
            ->when(
                $message->shopId !== null,
                fn ($query) => $query->where(function ($nested) use ($message): void {
                    $nested->whereNull('shop_id')->orWhere('shop_id', $message->shopId);
                }),
                fn ($query) => $query->whereNull('shop_id')
            )
            ->when(
                $message->feedProfileId !== null,
                fn ($query) => $query->where(function ($nested) use ($message): void {
                    $nested->whereNull('feed_profile_id')->orWhere('feed_profile_id', $message->feedProfileId);
                }),
                fn ($query) => $query->whereNull('feed_profile_id')
            )
            ->where('enabled', true)
            ->orderByRaw("case scope when 'feed_profile' then 1 when 'shop' then 2 else 3 end")
            ->orderBy('id')
            ->get()
            ->map(fn (OpsNotificationRoute $route) => $this->normalizeRoute($route))
            ->filter(fn (array $route) => $this->matchesEvent($route, $message))
            ->filter(fn (array $route) => $this->matchesSeverity($route, $message))
            ->values();

        if ($routes->isEmpty()) {
            return collect($this->fallbackRoutes($message));
        }

        return $routes;
    }

    /**
     * @param  array<string, mixed>  $route
     */
    public function suppressionReason(array $route): ?string
    {
        if (($route['muted_until'] ?? null) instanceof \Carbon\CarbonInterface && $route['muted_until']->isFuture()) {
            return 'Route is muted until '.$route['muted_until']->toIso8601String().'.';
        }

        $start = $route['quiet_hours_start'] ?? null;
        $end = $route['quiet_hours_end'] ?? null;

        if (! is_string($start) || ! is_string($end) || $start === '' || $end === '') {
            return null;
        }

        $timezone = (string) ($route['quiet_hours_timezone'] ?: config('feed_mediator.notifications.routing.default_quiet_hours_timezone', config('app.timezone')));
        $now = CarbonImmutable::now($timezone);
        $current = $now->format('H:i');

        if ($start <= $end) {
            return $current >= $start && $current <= $end
                ? 'Route is in quiet hours.'
                : null;
        }

        return ($current >= $start || $current <= $end)
            ? 'Route is in quiet hours.'
            : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fallbackRoutes(OpsNotificationMessage $message): array
    {
        $routes = [];

        if ((bool) config('feed_mediator.notifications.defaults.database_enabled', true)) {
            $target = ['mode' => 'shop_admins'];
            $routes[] = [
                'route_id' => null,
                'name' => 'Default database route',
                'scope' => OpsNotificationRoute::SCOPE_SHOP,
                'channel' => OpsNotificationRoute::CHANNEL_DATABASE,
                'target' => $target,
                'target_label' => $this->redactor->targetLabel(OpsNotificationRoute::CHANNEL_DATABASE, $target),
                'policy' => $this->defaultPolicy(OpsNotificationRoute::CHANNEL_DATABASE),
            ];
        }

        if ((bool) config('feed_mediator.notifications.defaults.log_enabled', true)) {
            $target = ['channel' => config('feed_mediator.notifications.defaults.log_channel', config('logging.default'))];
            $routes[] = [
                'route_id' => null,
                'name' => 'Default log route',
                'scope' => OpsNotificationRoute::SCOPE_GLOBAL,
                'channel' => OpsNotificationRoute::CHANNEL_LOG,
                'target' => $target,
                'target_label' => $this->redactor->targetLabel(OpsNotificationRoute::CHANNEL_LOG, $target),
                'policy' => $this->defaultPolicy(OpsNotificationRoute::CHANNEL_LOG),
            ];
        }

        $emails = array_values(array_filter((array) config('feed_mediator.notifications.defaults.mail_to', [])));

        if ($emails === [] && $message->shopId !== null && (bool) config('feed_mediator.notifications.defaults.mail_enabled', false)) {
            $emails = User::query()
                ->where('shop_id', $message->shopId)
                ->where('role', User::ROLE_ADMIN)
                ->where('is_active', true)
                ->pluck('email')
                ->filter()
                ->values()
                ->all();
        }

        if ($emails !== [] && (bool) config('feed_mediator.notifications.defaults.mail_enabled', false)) {
            $target = ['emails' => $emails];
            $routes[] = [
                'route_id' => null,
                'name' => 'Default mail route',
                'scope' => OpsNotificationRoute::SCOPE_SHOP,
                'channel' => OpsNotificationRoute::CHANNEL_EMAIL,
                'target' => $target,
                'target_label' => $this->redactor->targetLabel(OpsNotificationRoute::CHANNEL_EMAIL, $target),
                'policy' => $this->defaultPolicy(OpsNotificationRoute::CHANNEL_EMAIL),
            ];
        }

        return $routes;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeRoute(OpsNotificationRoute $route): array
    {
        return [
            'route_id' => $route->id,
            'name' => $route->name,
            'scope' => $route->scope,
            'channel' => $route->channel,
            'event_family' => $route->event_family,
            'event_type' => $route->event_type,
            'minimum_severity' => $route->minimum_severity,
            'muted_until' => $route->muted_until,
            'quiet_hours_start' => $route->quiet_hours_start,
            'quiet_hours_end' => $route->quiet_hours_end,
            'quiet_hours_timezone' => $route->quiet_hours_timezone,
            'target' => (array) ($route->target ?? []),
            'target_label' => $route->target_label ?: $this->redactor->targetLabel($route->channel, (array) ($route->target ?? [])),
            'policy' => array_merge($this->defaultPolicy($route->channel), (array) ($route->policy ?? [])),
        ];
    }

    /**
     * @param  array<string, mixed>  $route
     */
    private function matchesEvent(array $route, OpsNotificationMessage $message): bool
    {
        $family = (string) ($route['event_family'] ?? '*');
        $type = (string) ($route['event_type'] ?? '*');

        return ($family === '' || $family === '*' || $family === $message->eventFamily)
            && ($type === '' || $type === '*' || $type === $message->eventType);
    }

    /**
     * @param  array<string, mixed>  $route
     */
    private function matchesSeverity(array $route, OpsNotificationMessage $message): bool
    {
        return $this->severityRank($message->severity) >= $this->severityRank((string) ($route['minimum_severity'] ?? 'info'));
    }

    private function severityRank(string $severity): int
    {
        return match ($severity) {
            OpsAlert::SEVERITY_CRITICAL, 'critical', 'error', 'high' => 4,
            OpsAlert::SEVERITY_WARNING, 'warning', 'medium' => 3,
            'low' => 2,
            default => 1,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultPolicy(string $channel): array
    {
        $backoff = array_values(array_filter(array_map(
            static fn ($value) => (int) trim((string) $value),
            explode(',', (string) config('feed_mediator.notifications.webhook.backoff_seconds_csv', '60,300,900'))
        )));

        return [
            'suppression_window_minutes' => (int) config('feed_mediator.notifications.routing.suppression_window_minutes', 15),
            'repeat_interval_minutes' => (int) config('feed_mediator.notifications.routing.repeat_interval_minutes', 30),
            'escalate_after_minutes' => (int) config('feed_mediator.notifications.routing.escalate_after_minutes', 15),
            'timeout_seconds' => (int) config('feed_mediator.notifications.webhook.timeout_seconds', 5),
            'max_attempts' => $channel === OpsNotificationRoute::CHANNEL_WEBHOOK
                ? (int) config('feed_mediator.notifications.webhook.max_attempts', 3)
                : 1,
            'backoff_seconds' => $backoff !== [] ? $backoff : [60, 300, 900],
        ];
    }
}
