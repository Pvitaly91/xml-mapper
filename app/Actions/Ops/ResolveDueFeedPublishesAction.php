<?php

namespace App\Actions\Ops;

use App\Data\Ops\DueFeedPublishCandidate;
use App\Models\FeedGeneration;
use App\Models\FeedProfile;
use App\Models\Shop;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class ResolveDueFeedPublishesAction
{
    /**
     * @return Collection<int, DueFeedPublishCandidate>
     */
    public function handle(?Shop $shop = null, ?int $feedProfileId = null): Collection
    {
        $profiles = FeedProfile::query()
            ->with(['publishedGeneration', 'latestGeneration', 'generations' => fn ($query) => $query
                ->whereIn('status', [FeedGeneration::STATUS_BUILT, FeedGeneration::STATUS_PUBLISHED])
                ->orderByDesc('id')
                ->limit(3),
            ])
            ->when($shop !== null, fn ($query) => $query->where('shop_id', $shop->id))
            ->when($feedProfileId !== null, fn ($query) => $query->whereKey($feedProfileId))
            ->where('status', FeedProfile::STATUS_ACTIVE)
            ->orderBy('id')
            ->get();

        $disk = Storage::disk(config('feed_mediator.storage_disk'));

        return $profiles->map(function (FeedProfile $profile) use ($disk): ?DueFeedPublishCandidate {
            $publishableGeneration = $profile->generations
                ->first(fn (FeedGeneration $generation) => ! blank($generation->file_path) && $disk->exists($generation->file_path));

            if (! $publishableGeneration instanceof FeedGeneration) {
                return null;
            }

            $publishedGeneration = $profile->publishedGeneration;
            $publishedFileMissing = blank($profile->published_path)
                || ! $disk->exists((string) $profile->published_path);

            if ($publishedGeneration === null) {
                return new DueFeedPublishCandidate($profile, $publishableGeneration, 'not_published');
            }

            if ($publishableGeneration->id !== $publishedGeneration->id) {
                return new DueFeedPublishCandidate($profile, $publishableGeneration, 'newer_generation');
            }

            if ($publishedFileMissing) {
                return new DueFeedPublishCandidate($profile, $publishableGeneration, 'published_file_missing');
            }

            return null;
        })->filter()->values();
    }

    public function isDue(FeedProfile $feedProfile): bool
    {
        return $this->candidateForProfile($feedProfile) !== null;
    }

    public function candidateForProfile(FeedProfile $feedProfile): ?DueFeedPublishCandidate
    {
        return $this->handle(null, $feedProfile->id)->first();
    }
}
