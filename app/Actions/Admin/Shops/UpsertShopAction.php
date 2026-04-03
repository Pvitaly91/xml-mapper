<?php

namespace App\Actions\Admin\Shops;

use App\Models\Shop;
use App\Models\User;
use App\Services\Shops\ShopOnboardingStateService;
use Illuminate\Support\Str;

class UpsertShopAction
{
    public function __construct(
        private readonly ShopOnboardingStateService $onboardingStateService,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(User $user, array $payload, ?Shop $shop = null): Shop
    {
        $shop ??= $user->shop;
        $shop ??= new Shop;

        $shop->fill([
            'name' => $payload['name'],
            'slug' => $payload['slug'] ?: Str::slug((string) $payload['name']),
            'currency' => $payload['currency'],
            'locale' => $payload['locale'],
            'timezone' => $payload['timezone'],
            'is_active' => (bool) ($payload['is_active'] ?? true),
        ]);
        $shop->save();

        if ((int) ($user->shop_id ?? 0) !== (int) $shop->id) {
            $user->update(['shop_id' => $shop->id]);
        }

        $this->onboardingStateService->markStepCompleted($user->fresh(), 'shop');

        return $shop->refresh();
    }
}
