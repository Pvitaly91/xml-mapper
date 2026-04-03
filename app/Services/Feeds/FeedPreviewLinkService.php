<?php

namespace App\Services\Feeds;

use App\Models\FeedGeneration;
use App\Models\FeedGenerationPreviewLink;
use App\Models\User;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class FeedPreviewLinkService
{
    public function __construct(
        private readonly FeedReleaseAuditService $auditService,
        private readonly PilotNotificationService $notificationService,
    ) {
    }

    public function create(FeedGeneration $generation, int $ttlMinutes = 1440, ?User $user = null, ?string $reason = null): FeedGenerationPreviewLink
    {
        if (! in_array($generation->status, [FeedGeneration::STATUS_BUILT, FeedGeneration::STATUS_PUBLISHED], true)) {
            throw new RuntimeException('Only built generations can be shared as preview URLs.');
        }

        if (blank($generation->file_path) || ! Storage::disk(config('feed_mediator.storage_disk'))->exists($generation->file_path)) {
            throw new RuntimeException('Generation XML file is missing.');
        }

        $ttlMinutes = max(5, min(10080, $ttlMinutes));
        $previewLink = FeedGenerationPreviewLink::create([
            'shop_id' => $generation->shop_id,
            'feed_profile_id' => $generation->feed_profile_id,
            'feed_generation_id' => $generation->id,
            'user_id' => $user?->id,
            'token' => Str::random(64),
            'expires_at' => now()->addMinutes($ttlMinutes),
            'note' => $reason,
            'meta' => [
                'ttl_minutes' => $ttlMinutes,
            ],
        ]);

        $this->auditService->record(
            $generation->feedProfile,
            $generation,
            'preview_link_created',
            $user,
            $reason,
            [
                'preview_link_id' => $previewLink->id,
                'expires_at' => $previewLink->expires_at?->toIso8601String(),
                'ttl_minutes' => $ttlMinutes,
            ]
        );

        $this->notificationService->notifyFeedProfileAdmins(
            $generation->feedProfile,
            'feed.preview_link_created',
            'Candidate preview link created',
            'A signed preview link was generated for a candidate generation.',
            [
                'generation_id' => $generation->id,
                'preview_link_id' => $previewLink->id,
                'expires_at' => $previewLink->expires_at?->toIso8601String(),
            ],
            'info',
            $generation
        );

        return $previewLink->refresh();
    }

    public function revoke(FeedGenerationPreviewLink $previewLink, ?User $user = null, ?string $reason = null): FeedGenerationPreviewLink
    {
        if ($previewLink->revoked_at === null) {
            $previewLink->update(['revoked_at' => now()]);

            $this->auditService->record(
                $previewLink->feedProfile,
                $previewLink->feedGeneration,
                'preview_link_revoked',
                $user,
                $reason,
                ['preview_link_id' => $previewLink->id]
            );
        }

        return $previewLink->refresh();
    }

    public function urlFor(FeedGenerationPreviewLink $previewLink): string
    {
        return URL::temporarySignedRoute(
            'feeds.preview',
            $previewLink->expires_at ?? now()->addMinutes(5),
            [
                'preview_link' => $previewLink->id,
                'token' => $previewLink->token,
            ]
        );
    }

    public function markAccessed(FeedGenerationPreviewLink $previewLink): void
    {
        $previewLink->forceFill(['last_accessed_at' => now()])->save();
    }
}
