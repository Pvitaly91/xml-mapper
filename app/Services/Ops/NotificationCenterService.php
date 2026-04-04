<?php

namespace App\Services\Ops;

use App\Data\Ops\OpsNotificationMessage;
use App\Models\FeedProfile;
use App\Models\MerchantLaunch;
use App\Models\OpsNotificationDelivery;
use App\Models\OpsNotificationRoute;
use App\Models\Shop;
use App\Models\User;
use App\Support\SensitiveDataRedactor;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use RuntimeException;

class NotificationCenterService
{
    public function __construct(
        private readonly NotificationDeliveryService $deliveryService,
        private readonly SensitiveDataRedactor $redactor,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function deliveries(Shop $shop, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        return OpsNotificationDelivery::query()
            ->with(['route', 'alert.feedProfile', 'feedProfile', 'launch', 'hypercareWindow'])
            ->where('shop_id', $shop->id)
            ->when(filled($filters['channel'] ?? null), fn ($query) => $query->where('channel', $filters['channel']))
            ->when(filled($filters['status'] ?? null), fn ($query) => $query->where('status', $filters['status']))
            ->when(filled($filters['severity'] ?? null), fn ($query) => $query->where('severity', $filters['severity']))
            ->when(filled($filters['event_type'] ?? null), fn ($query) => $query->where('event_type', $filters['event_type']))
            ->when(filled($filters['feed_profile_id'] ?? null), fn ($query) => $query->where('feed_profile_id', (int) $filters['feed_profile_id']))
            ->when(filled($filters['from'] ?? null), fn ($query) => $query->whereDate('created_at', '>=', $filters['from']))
            ->when(filled($filters['to'] ?? null), fn ($query) => $query->whereDate('created_at', '<=', $filters['to']))
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @return Collection<int, OpsNotificationRoute>
     */
    public function routes(Shop $shop): Collection
    {
        return OpsNotificationRoute::query()
            ->with(['feedProfile', 'user'])
            ->where(function ($query) use ($shop): void {
                $query->whereNull('shop_id')->orWhere('shop_id', $shop->id);
            })
            ->latest('id')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function saveRoute(Shop $shop, array $payload, ?OpsNotificationRoute $route = null, ?User $user = null): OpsNotificationRoute
    {
        $target = $this->normalizeTarget((string) $payload['channel'], (string) ($payload['target_value'] ?? ''), $shop);
        $targetLabel = $this->redactor->targetLabel((string) $payload['channel'], $target);
        $scope = (string) $payload['scope'];
        $feedProfileId = $scope === OpsNotificationRoute::SCOPE_FEED_PROFILE ? (int) ($payload['feed_profile_id'] ?? 0) : null;

        $route ??= new OpsNotificationRoute();
        $route->fill([
            'shop_id' => $scope === OpsNotificationRoute::SCOPE_GLOBAL ? null : $shop->id,
            'feed_profile_id' => $feedProfileId ?: null,
            'user_id' => $user?->id,
            'name' => $payload['name'],
            'scope' => $scope,
            'channel' => $payload['channel'],
            'event_family' => $payload['event_family'] ?? '*',
            'event_type' => $payload['event_type'] ?? '*',
            'minimum_severity' => $payload['minimum_severity'] ?? 'info',
            'enabled' => (bool) ($payload['enabled'] ?? true),
            'muted_until' => $payload['muted_until'] ?? null,
            'quiet_hours_start' => $payload['quiet_hours_start'] ?? null,
            'quiet_hours_end' => $payload['quiet_hours_end'] ?? null,
            'quiet_hours_timezone' => $payload['quiet_hours_timezone'] ?? $shop->timezone,
            'target_label' => $targetLabel,
            'target' => $target,
            'policy' => [
                'suppression_window_minutes' => (int) ($payload['suppression_window_minutes'] ?? config('feed_mediator.notifications.routing.suppression_window_minutes', 15)),
                'repeat_interval_minutes' => (int) ($payload['repeat_interval_minutes'] ?? config('feed_mediator.notifications.routing.repeat_interval_minutes', 30)),
                'escalate_after_minutes' => (int) ($payload['escalate_after_minutes'] ?? config('feed_mediator.notifications.routing.escalate_after_minutes', 15)),
                'max_attempts' => (int) ($payload['max_attempts'] ?? config('feed_mediator.notifications.webhook.max_attempts', 3)),
                'timeout_seconds' => (int) ($payload['timeout_seconds'] ?? config('feed_mediator.notifications.webhook.timeout_seconds', 5)),
            ],
        ])->save();

        return $route->fresh(['feedProfile', 'user']);
    }

    public function muteRoute(OpsNotificationRoute $route, ?string $until = null): OpsNotificationRoute
    {
        $route->forceFill([
            'muted_until' => $until ? now()->parse($until) : now()->addHour(),
        ])->save();

        return $route->fresh();
    }

    public function testRoute(OpsNotificationRoute $route): OpsNotificationDelivery
    {
        return $this->deliveryService->dispatchWithRoutes(new OpsNotificationMessage(
            eventType: 'ops.notification.test',
            eventFamily: 'test',
            severity: 'info',
            title: 'Test notification',
            message: 'Operator-triggered channel test.',
            context: [
                'route_id' => $route->id,
                'route_name' => $route->name,
                'channel' => $route->channel,
            ],
            shopId: $route->shop_id,
            feedProfileId: $route->feed_profile_id,
            isTest: true,
        ), [[
            'route_id' => $route->id,
            'name' => $route->name,
            'scope' => $route->scope,
            'channel' => $route->channel,
            'event_family' => $route->event_family,
            'event_type' => $route->event_type,
            'minimum_severity' => $route->minimum_severity,
            'muted_until' => null,
            'quiet_hours_start' => null,
            'quiet_hours_end' => null,
            'quiet_hours_timezone' => $route->quiet_hours_timezone,
            'target' => (array) ($route->target ?? []),
            'target_label' => $route->target_label,
            'policy' => array_merge([
                'max_attempts' => 1,
            ], (array) ($route->policy ?? [])),
        ]])->last();
    }

    public function testChannel(string $channel, Shop $shop, ?string $targetValue = null): OpsNotificationDelivery
    {
        $target = $this->normalizeTarget($channel, $targetValue ?? '', $shop);
        $route = [
            'route_id' => null,
            'name' => 'Ad-hoc channel test',
            'scope' => OpsNotificationRoute::SCOPE_SHOP,
            'channel' => $channel,
            'target' => $target,
            'target_label' => $this->redactor->targetLabel($channel, $target),
            'policy' => [
                'max_attempts' => 1,
                'timeout_seconds' => (int) config('feed_mediator.notifications.webhook.timeout_seconds', 5),
                'backoff_seconds' => [60],
            ],
        ];

        return $this->deliveryService->dispatchWithRoutes(new OpsNotificationMessage(
            eventType: 'ops.notification.test',
            eventFamily: 'test',
            severity: 'info',
            title: 'Test notification',
            message: 'Operator-triggered channel test.',
            context: [
                'channel' => $channel,
                'target_label' => $route['target_label'],
            ],
            shopId: $shop->id,
            isTest: true,
        ), [$route])->last();
    }

    public function retry(OpsNotificationDelivery $delivery): OpsNotificationDelivery
    {
        return $this->deliveryService->retry($delivery);
    }

    /**
     * @return array<string, mixed>
     */
    public function channelStatus(Shop $shop): array
    {
        $routes = $this->routes($shop);

        return [
            'routes' => $routes->map(fn (OpsNotificationRoute $route) => [
                'id' => $route->id,
                'name' => $route->name,
                'channel' => $route->channel,
                'scope' => $route->scope,
                'target_label' => $route->target_label,
                'enabled' => $route->enabled,
                'last_delivery_status' => $route->last_delivery_status,
                'last_delivery_at' => $route->last_delivery_at,
                'last_test_succeeded_at' => $route->last_test_succeeded_at,
                'last_test_failed_at' => $route->last_test_failed_at,
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function deliverySummaryForFeedProfile(FeedProfile $feedProfile): array
    {
        $recent = OpsNotificationDelivery::query()
            ->where('feed_profile_id', $feedProfile->id)
            ->latest('id')
            ->limit(10)
            ->get();

        return [
            'recent' => $recent,
            'failed_count' => $recent->where('status', OpsNotificationDelivery::STATUS_FAILED)->count(),
            'suppressed_count' => $recent->where('status', OpsNotificationDelivery::STATUS_SUPPRESSED)->count(),
            'escalated_count' => $recent->where('status', OpsNotificationDelivery::STATUS_ESCALATED)->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function deliverySummaryForLaunch(MerchantLaunch $launch): array
    {
        $recent = OpsNotificationDelivery::query()
            ->where('merchant_launch_id', $launch->id)
            ->latest('id')
            ->limit(10)
            ->get();

        return [
            'recent' => $recent,
            'failed_count' => $recent->where('status', OpsNotificationDelivery::STATUS_FAILED)->count(),
            'suppressed_count' => $recent->where('status', OpsNotificationDelivery::STATUS_SUPPRESSED)->count(),
            'escalated_count' => $recent->where('status', OpsNotificationDelivery::STATUS_ESCALATED)->count(),
        ];
    }

    public function prune(int $days): int
    {
        return OpsNotificationDelivery::query()
            ->where('created_at', '<=', now()->subDays($days))
            ->delete();
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeTarget(string $channel, string $targetValue, Shop $shop): array
    {
        $targetValue = trim($targetValue);

        $validator = Validator::make([
            'channel' => $channel,
            'target_value' => $targetValue,
        ], [
            'channel' => ['required', Rule::in(OpsNotificationRoute::channels())],
            'target_value' => ['nullable', 'string'],
        ]);
        $validator->validate();

        return match ($channel) {
            OpsNotificationRoute::CHANNEL_EMAIL => $this->normalizeEmailTarget($targetValue, $shop),
            OpsNotificationRoute::CHANNEL_WEBHOOK => $this->normalizeWebhookTarget($targetValue),
            OpsNotificationRoute::CHANNEL_LOG => ['channel' => $targetValue !== '' ? $targetValue : config('logging.default')],
            default => ['mode' => 'shop_admins'],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeEmailTarget(string $targetValue, Shop $shop): array
    {
        $emails = collect(explode(',', $targetValue))
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->values();

        if ($emails->isEmpty()) {
            $emails = User::query()
                ->where('shop_id', $shop->id)
                ->where('role', User::ROLE_ADMIN)
                ->where('is_active', true)
                ->pluck('email');
        }

        foreach ($emails as $email) {
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Invalid email target ['.$email.'].');
            }
        }

        return ['emails' => $emails->values()->all()];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeWebhookTarget(string $targetValue): array
    {
        if (! filter_var($targetValue, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('Webhook target must be a valid URL.');
        }

        return ['url' => $targetValue];
    }
}
