<?php

namespace App\Actions\Ops;

use App\Models\FeedProfile;
use App\Models\Shop;
use Illuminate\Database\Eloquent\Collection;

class ResolveDueFeedBuildsAction
{
    /**
     * @return Collection<int, FeedProfile>
     */
    public function handle(?Shop $shop = null, ?int $feedProfileId = null): Collection
    {
        return FeedProfile::query()
            ->when($shop !== null, fn ($query) => $query->where('shop_id', $shop->id))
            ->when($feedProfileId !== null, fn ($query) => $query->whereKey($feedProfileId))
            ->where('status', FeedProfile::STATUS_ACTIVE)
            ->where('auto_build', true)
            ->where(function ($query): void {
                $query->whereNull('next_build_at')
                    ->orWhere('next_build_at', '<=', now());
            })
            ->orderBy('id')
            ->get();
    }
}
