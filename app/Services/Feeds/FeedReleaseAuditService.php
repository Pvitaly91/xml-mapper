<?php

namespace App\Services\Feeds;

use App\Models\FeedGeneration;
use App\Models\FeedProfile;
use App\Models\FeedReleaseEvent;
use App\Models\SyncLog;
use App\Models\User;
use App\Services\Ops\CorrelationContext;

class FeedReleaseAuditService
{
    public function __construct(
        private readonly CorrelationContext $correlationContext,
    ) {}

    /**
     * @param  array<string, mixed>  $meta
     */
    public function record(
        FeedProfile $feedProfile,
        ?FeedGeneration $generation,
        string $action,
        ?User $user = null,
        ?string $reason = null,
        array $meta = []
    ): FeedReleaseEvent {
        $correlationId = $this->correlationContext->ensure();
        $event = FeedReleaseEvent::create([
            'shop_id' => $feedProfile->shop_id,
            'feed_profile_id' => $feedProfile->id,
            'feed_generation_id' => $generation?->id,
            'user_id' => $user?->id,
            'action' => $action,
            'reason' => $reason,
            'meta' => array_merge($meta, [
                'correlation_id' => $correlationId,
            ]),
            'occurred_at' => now(),
        ]);

        SyncLog::create([
            'shop_id' => $feedProfile->shop_id,
            'source_connection_id' => $feedProfile->source_connection_id,
            'feed_profile_id' => $feedProfile->id,
            'feed_generation_id' => $generation?->id,
            'level' => str_contains($action, 'failed') ? 'error' : 'info',
            'event' => 'release.'.$action,
            'message' => $reason ?: ucfirst(str_replace('_', ' ', $action)).'.',
            'context' => array_merge($meta, [
                'user_id' => $user?->id,
                'user_email' => $user?->email,
                'correlation_id' => $correlationId,
            ]),
            'occurred_at' => now(),
        ]);

        return $event;
    }
}
