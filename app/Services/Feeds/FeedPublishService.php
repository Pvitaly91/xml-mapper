<?php

namespace App\Services\Feeds;

use App\Contracts\Feeds\FeedPublishServiceInterface;
use App\Models\FeedGeneration;
use App\Models\FeedItem;
use App\Models\FeedProfile;
use App\Models\SourceVariant;
use App\Models\SyncLog;
use App\Services\Ops\ProcessLockService;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class FeedPublishService implements FeedPublishServiceInterface
{
    public function __construct(
        private readonly ProcessLockService $lockService,
    ) {
    }

    public function publish(FeedProfile $feedProfile, ?FeedGeneration $generation = null): FeedGeneration
    {
        $generation ??= $this->resolveGeneration($feedProfile);

        if ($generation === null) {
            throw new RuntimeException('No built feed generation found for publication.');
        }

        if ($generation->feed_profile_id !== $feedProfile->id) {
            throw new RuntimeException('Feed generation does not belong to the selected profile.');
        }

        return $this->lockService->runExclusive(
            $this->lockService->feedPublishProfileKey($feedProfile->id),
            (int) config('feed_mediator.locks.feed_publish_ttl_seconds'),
            'Feed publish is already in progress for this profile.',
            fn (): FeedGeneration => $this->lockService->runExclusive(
                $this->lockService->feedPublishGenerationKey($generation->id),
                (int) config('feed_mediator.locks.feed_publish_ttl_seconds'),
                'Feed publish is already in progress for this generation.',
                fn (): FeedGeneration => $this->publishGeneration($feedProfile->fresh(), $generation->fresh())
            )
        );
    }

    private function publishGeneration(FeedProfile $feedProfile, FeedGeneration $generation): FeedGeneration
    {
        $disk = Storage::disk(config('feed_mediator.storage_disk'));

        if (
            $feedProfile->published_generation_id === $generation->id
            && ! blank($feedProfile->published_path)
            && $disk->exists((string) $feedProfile->published_path)
        ) {
            return $generation->refresh();
        }

        if (blank($generation->file_path)) {
            throw new RuntimeException('Feed generation has no built file.');
        }

        if (! $disk->exists($generation->file_path)) {
            throw new RuntimeException(sprintf('Feed build file [%s] does not exist.', $generation->file_path));
        }

        $publishedPath = trim(config('feed_mediator.published_directory'), '/').'/'.$feedProfile->public_token.'.xml';
        $disk->copy($generation->file_path, $publishedPath);
        $publishedAt = now();

        $generation->update([
            'status' => FeedGeneration::STATUS_PUBLISHED,
            'published_at' => $publishedAt,
            'published_path' => $publishedPath,
        ]);

        $feedProfile->update([
            'published_generation_id' => $generation->id,
            'published_path' => $publishedPath,
        ]);

        $feedItems = FeedItem::query()
            ->with('sourceVariant')
            ->where('feed_profile_id', $feedProfile->id)
            ->where('last_built_generation_id', $generation->id)
            ->where('status', FeedItem::STATUS_READY)
            ->get();

        foreach ($feedItems as $feedItem) {
            /** @var SourceVariant|null $variant */
            $variant = $feedItem->sourceVariant;

            if ($variant === null) {
                continue;
            }

            $variant->update([
                'published_export_key_hash' => $variant->published_export_key_hash ?? $variant->export_key_hash,
                'first_published_at' => $variant->first_published_at ?? $publishedAt,
                'last_published_at' => $publishedAt,
            ]);

            $feedItem->update([
                'last_exported_at' => $publishedAt,
            ]);
        }

        $this->log($feedProfile, $generation, 'info', 'feed.published', 'Feed XML published.', [
            'published_path' => $publishedPath,
        ]);

        return $generation->refresh();
    }

    private function resolveGeneration(FeedProfile $feedProfile): ?FeedGeneration
    {
        return $feedProfile->generations()
            ->whereIn('status', [FeedGeneration::STATUS_BUILT, FeedGeneration::STATUS_PUBLISHED])
            ->latest('id')
            ->first();
    }

    private function log(FeedProfile $feedProfile, FeedGeneration $generation, string $level, string $event, string $message, array $context = []): void
    {
        SyncLog::create([
            'shop_id' => $feedProfile->shop_id,
            'source_connection_id' => $feedProfile->source_connection_id,
            'feed_profile_id' => $feedProfile->id,
            'feed_generation_id' => $generation->id,
            'level' => $level,
            'event' => $event,
            'message' => $message,
            'context' => $context,
            'occurred_at' => now(),
        ]);
    }
}
