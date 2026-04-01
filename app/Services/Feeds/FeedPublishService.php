<?php

namespace App\Services\Feeds;

use App\Contracts\Feeds\FeedPublishServiceInterface;
use App\Models\FeedGeneration;
use App\Models\FeedItem;
use App\Models\FeedProfile;
use App\Models\SourceVariant;
use App\Models\SyncLog;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class FeedPublishService implements FeedPublishServiceInterface
{
    public function publish(FeedProfile $feedProfile, ?FeedGeneration $generation = null): FeedGeneration
    {
        $generation ??= $feedProfile->generations()
            ->where('status', FeedGeneration::STATUS_BUILT)
            ->latest('id')
            ->first();

        if ($generation === null) {
            throw new RuntimeException('No built feed generation found for publication.');
        }

        if (blank($generation->file_path)) {
            throw new RuntimeException('Feed generation has no built file.');
        }

        $disk = Storage::disk(config('feed_mediator.storage_disk'));

        if (! $disk->exists($generation->file_path)) {
            throw new RuntimeException(sprintf('Feed build file [%s] does not exist.', $generation->file_path));
        }

        $publishedPath = trim(config('feed_mediator.published_directory'), '/').'/'.$feedProfile->public_token.'.xml';
        $disk->copy($generation->file_path, $publishedPath);

        $generation->update([
            'status' => FeedGeneration::STATUS_PUBLISHED,
            'published_at' => now(),
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
                'first_published_at' => $variant->first_published_at ?? now(),
                'last_published_at' => now(),
            ]);

            $feedItem->update([
                'last_exported_at' => now(),
            ]);
        }

        $this->log($feedProfile, $generation, 'info', 'feed.published', 'Feed XML published.', [
            'published_path' => $publishedPath,
        ]);

        return $generation->refresh();
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
