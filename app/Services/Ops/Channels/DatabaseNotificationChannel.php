<?php

namespace App\Services\Ops\Channels;

use App\Contracts\Ops\NotificationChannelDriver;
use App\Data\Ops\OpsNotificationChannelResult;
use App\Data\Ops\OpsNotificationMessage;
use App\Models\OpsNotificationDelivery;
use App\Models\Shop;
use App\Models\User;
use App\Notifications\OpsDatabaseNotification;
use App\Services\Access\AdminAccessService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

class DatabaseNotificationChannel implements NotificationChannelDriver
{
    public function __construct(
        private readonly AdminAccessService $accessService,
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
        $notifiables = $this->resolveNotifiables($message, $route);

        if ($notifiables->isEmpty()) {
            return new OpsNotificationChannelResult(
                status: OpsNotificationDelivery::STATUS_DROPPED,
                summary: 'No database recipients matched the route.',
            );
        }

        Notification::send(
            $notifiables,
            $message->databaseNotification ?? new OpsDatabaseNotification((array) $rendered['database'])
        );

        return new OpsNotificationChannelResult(
            status: OpsNotificationDelivery::STATUS_DELIVERED,
            summary: 'Database notification sent to '.$notifiables->count().' recipient(s).',
            responseMeta: [
                'recipients' => $notifiables->pluck('email')->values()->all(),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $route
     * @return Collection<int, User>
     */
    private function resolveNotifiables(OpsNotificationMessage $message, array $route): Collection
    {
        $target = (array) ($route['target'] ?? []);
        $emails = array_values(array_filter((array) ($target['emails'] ?? [])));
        $shop = $message->shopId !== null ? Shop::query()->find($message->shopId) : null;
        $users = $this->accessService->activeAdminUsers($shop);

        if ($emails === []) {
            return $users;
        }

        return $users->whereIn('email', $emails)->values();
    }
}
