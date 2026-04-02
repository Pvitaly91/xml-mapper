<?php

namespace App\Actions\Ops;

use App\Models\Shop;
use App\Models\SourceConnection;
use Illuminate\Database\Eloquent\Collection;

class ResolveDueSourceConnectionsAction
{
    /**
     * @return Collection<int, SourceConnection>
     */
    public function handle(?Shop $shop = null, ?int $sourceConnectionId = null): Collection
    {
        return SourceConnection::query()
            ->when($shop !== null, fn ($query) => $query->where('shop_id', $shop->id))
            ->when($sourceConnectionId !== null, fn ($query) => $query->whereKey($sourceConnectionId))
            ->where('status', SourceConnection::STATUS_ACTIVE)
            ->where(function ($query): void {
                $query->whereNull('next_sync_at')
                    ->orWhere('next_sync_at', '<=', now());
            })
            ->orderBy('id')
            ->get();
    }
}
