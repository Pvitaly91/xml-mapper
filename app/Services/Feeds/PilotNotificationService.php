<?php

namespace App\Services\Feeds;

use App\Models\FeedGeneration;
use App\Models\FeedProfile;
use App\Models\Shop;
use App\Models\SourceConnection;
use App\Models\SyncLog;
use App\Models\User;
use App\Notifications\PilotEventNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

class PilotNotificationService
{
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
        $admins = $this->activeAdmins($feedProfile->shop);

        if ($admins->isNotEmpty()) {
            Notification::send($admins, new PilotEventNotification($event, $title, $message, $context, $severity));
        }

        SyncLog::create([
            'shop_id' => $feedProfile->shop_id,
            'source_connection_id' => $feedProfile->source_connection_id,
            'feed_profile_id' => $feedProfile->id,
            'feed_generation_id' => $generation?->id,
            'level' => $severity === 'error' ? 'error' : 'warning',
            'event' => $event,
            'message' => $message,
            'context' => $context,
            'occurred_at' => now(),
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
        $admins = $this->activeAdmins($connection->shop);

        if ($admins->isNotEmpty()) {
            Notification::send($admins, new PilotEventNotification($event, $title, $message, $context, $severity));
        }

        SyncLog::create([
            'shop_id' => $connection->shop_id,
            'source_connection_id' => $connection->id,
            'level' => $severity === 'error' ? 'error' : 'warning',
            'event' => $event,
            'message' => $message,
            'context' => $context,
            'occurred_at' => now(),
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
}
