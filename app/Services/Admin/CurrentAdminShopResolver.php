<?php

namespace App\Services\Admin;

use App\Models\Shop;
use App\Models\User;
use App\Services\Access\AdminAccessService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CurrentAdminShopResolver
{
    public function __construct(
        private readonly AdminAccessService $accessService,
    ) {}

    public function resolve(Request|User|null $subject): ?Shop
    {
        $user = $subject instanceof Request ? $subject->user() : $subject;

        if (! $user instanceof User || ! $this->accessService->canAccessAdmin($user)) {
            return null;
        }

        if ($subject instanceof Request) {
            $selectedShopId = $this->selectedShopId($subject, $user);
            $selectedShop = $this->accessService->accessibleShop($user, $selectedShopId);

            if ($selectedShop instanceof Shop) {
                $this->persistSelection($subject, $user, $selectedShop);

                return $selectedShop;
            }
        }

        $currentShop = $this->accessService->accessibleShop($user, $user->shop_id);

        if ($currentShop instanceof Shop) {
            return $currentShop;
        }

        $fallback = $this->accessService->availableShops($user)->first();

        if ($fallback instanceof Shop && $subject instanceof Request) {
            $this->persistSelection($subject, $user, $fallback);
        }

        return $fallback;
    }

    public function require(Request|User|null $subject): Shop
    {
        $shop = $this->resolve($subject);

        if ($shop === null) {
            throw new HttpException(403, 'Admin user is not assigned to a shop. Open the onboarding wizard or run php artisan admin:bootstrap.');
        }

        return $shop;
    }

    public function ensureOwns(Request|User|null $subject, Model $model): void
    {
        $user = $subject instanceof Request ? $subject->user() : $subject;

        if ($user instanceof User && $this->accessService->isPlatformAdmin($user)) {
            if ($model instanceof Shop) {
                return;
            }

            $shopId = $model->getAttribute('shop_id');

            abort_unless($shopId !== null, 404);

            return;
        }

        $shop = $this->require($subject);

        if ($model instanceof Shop) {
            abort_unless((int) $model->getKey() === (int) $shop->getKey(), 404);

            return;
        }

        abort_unless((int) $model->getAttribute('shop_id') === (int) $shop->getKey(), 404);
    }

    public function ensureAllOwned(Request|User|null $subject, Model ...$models): void
    {
        foreach ($models as $model) {
            $this->ensureOwns($subject, $model);
        }
    }

    private function selectedShopId(Request $request, User $user): int|string|null
    {
        $input = $request->routeIs('admin.access.switch-shop')
            ? $request->input('shop_id')
            : null;

        if (filled($input)) {
            return $input;
        }

        if ($request->session()->has('admin_shop_id')) {
            return $request->session()->get('admin_shop_id');
        }

        return $user->shop_id;
    }

    private function persistSelection(Request $request, User $user, Shop $shop): void
    {
        $request->session()->put('admin_shop_id', $shop->id);

        if ((int) $user->shop_id !== (int) $shop->id) {
            $user->forceFill(['shop_id' => $shop->id])->save();
        }

        $user->setRelation('shop', $shop);
    }
}
