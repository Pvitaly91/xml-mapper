<?php

namespace App\Services\Launch;

use App\Models\MerchantLaunch;
use App\Models\Shop;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class MerchantLaunchCenterService
{
    public function __construct(
        private readonly MerchantLaunchService $launchService,
    ) {}

    public function list(Shop $shop, int $perPage = 20): LengthAwarePaginator
    {
        return MerchantLaunch::query()
            ->with(['feedProfile', 'pilotRun', 'promotionRun', 'publishedGeneration', 'owner', 'initiatedBy'])
            ->where('shop_id', $shop->id)
            ->latest('id')
            ->paginate($perPage);
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(MerchantLaunch $launch): array
    {
        return $this->launchService->snapshot($launch);
    }
}
