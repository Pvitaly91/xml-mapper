<?php

namespace App\Services\Access;

use App\Models\Shop;
use App\Models\ShopMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class AdminAccessService
{
    /**
     * @var array<string, list<string>>
     */
    private const ROLE_PERMISSIONS = [
        ShopMembership::ROLE_PLATFORM_ADMIN => ['*'],
        ShopMembership::ROLE_SHOP_ADMIN => [
            'dashboard.view',
            'onboarding.manage',
            'dictionaries.manage',
            'source.view',
            'source.manage',
            'secrets.manage',
            'mappings.view',
            'mappings.manage',
            'feed_items.view',
            'feed_items.manage',
            'feed_profiles.view',
            'feed_profiles.manage',
            'release.view',
            'release.manage',
            'release.review',
            'promotion.view',
            'promotion.manage',
            'pilot.view',
            'pilot.manage',
            'launch.view',
            'launch.manage',
            'hypercare.view',
            'hypercare.manage',
            'ops.view',
            'ops.manage',
            'notifications.view',
            'notifications.manage',
            'approvals.review',
            'access.view',
            'access.manage',
            'compliance.view',
        ],
        ShopMembership::ROLE_OPERATOR => [
            'dashboard.view',
            'dictionaries.manage',
            'source.view',
            'source.manage',
            'mappings.view',
            'mappings.manage',
            'feed_items.view',
            'feed_items.manage',
            'feed_profiles.view',
            'feed_profiles.manage',
            'release.view',
            'release.manage',
            'promotion.view',
            'promotion.manage',
            'pilot.view',
            'pilot.manage',
            'launch.view',
            'launch.manage',
            'hypercare.view',
            'hypercare.manage',
            'ops.view',
            'ops.manage',
            'notifications.view',
            'notifications.manage',
        ],
        ShopMembership::ROLE_REVIEWER => [
            'dashboard.view',
            'access.view',
            'dictionaries.view',
            'source.view',
            'mappings.view',
            'feed_items.view',
            'feed_profiles.view',
            'release.view',
            'release.review',
            'promotion.view',
            'pilot.view',
            'launch.view',
            'hypercare.view',
            'ops.view',
            'notifications.view',
            'approvals.review',
            'compliance.view',
        ],
        ShopMembership::ROLE_OBSERVER => [
            'dashboard.view',
            'dictionaries.view',
            'source.view',
            'mappings.view',
            'feed_items.view',
            'feed_profiles.view',
            'release.view',
            'promotion.view',
            'pilot.view',
            'launch.view',
            'hypercare.view',
            'ops.view',
            'notifications.view',
        ],
    ];

    /**
     * @return Collection<int, ShopMembership>
     */
    public function memberships(User $user, bool $activeOnly = true): Collection
    {
        $hasMembershipRecords = $user->memberships()->exists();
        $query = $user->memberships()->with('shop');

        if ($activeOnly) {
            $query->where('status', ShopMembership::STATUS_ACTIVE);
        }

        $memberships = $query->get();

        if (! $hasMembershipRecords && $memberships->isEmpty() && $user->is_active && $user->role === User::ROLE_ADMIN) {
            $legacy = new ShopMembership([
                'user_id' => $user->id,
                'shop_id' => $user->shop_id,
                'role' => $user->shop_id === null
                    ? ShopMembership::ROLE_PLATFORM_ADMIN
                    : ShopMembership::ROLE_SHOP_ADMIN,
                'status' => ShopMembership::STATUS_ACTIVE,
            ]);

            if ($user->shop_id !== null) {
                $legacy->setRelation('shop', $user->shop);
            }

            return collect([$legacy]);
        }

        return $memberships;
    }

    public function canAccessAdmin(User $user): bool
    {
        return $user->is_active && $this->memberships($user)->isNotEmpty();
    }

    public function isPlatformAdmin(User $user): bool
    {
        return $this->memberships($user)
            ->contains(fn (ShopMembership $membership) => $membership->role === ShopMembership::ROLE_PLATFORM_ADMIN);
    }

    public function roleFor(User $user, ?Shop $shop = null): ?string
    {
        if (! $this->canAccessAdmin($user)) {
            return null;
        }

        if ($this->isPlatformAdmin($user)) {
            return ShopMembership::ROLE_PLATFORM_ADMIN;
        }

        if (! $shop instanceof Shop) {
            $membership = $this->memberships($user)->first();

            return $membership?->role;
        }

        return $this->memberships($user)
            ->first(fn (ShopMembership $membership) => (int) $membership->shop_id === (int) $shop->id)
            ?->role;
    }

    public function accessibleShop(User $user, int|string|null $shopId): ?Shop
    {
        if ($shopId === null || $shopId === '') {
            return null;
        }

        $shop = Shop::query()->find((int) $shopId);

        if (! $shop instanceof Shop) {
            return null;
        }

        if ($this->isPlatformAdmin($user)) {
            return $shop;
        }

        return $this->memberships($user)
            ->first(fn (ShopMembership $membership) => (int) $membership->shop_id === (int) $shop->id)
            ?->shop;
    }

    /**
     * @return Collection<int, Shop>
     */
    public function availableShops(User $user): Collection
    {
        if ($this->isPlatformAdmin($user)) {
            return Shop::query()->where('is_active', true)->orderBy('name')->get();
        }

        return $this->memberships($user)
            ->pluck('shop')
            ->filter()
            ->values();
    }

    /**
     * @return Collection<int, User>
     */
    public function activeAdminUsers(?Shop $shop = null, array $roles = []): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->where(function (Builder $query) use ($shop, $roles): void {
                $query->whereHas('memberships', function (Builder $membershipQuery) use ($shop, $roles): void {
                    $membershipQuery->where('status', ShopMembership::STATUS_ACTIVE)
                        ->when($roles !== [], fn (Builder $inner) => $inner->whereIn('role', $roles))
                        ->when($shop instanceof Shop, function (Builder $inner) use ($shop): void {
                            $inner->where(function (Builder $scope) use ($shop): void {
                                $scope->whereNull('shop_id')
                                    ->orWhere('shop_id', $shop->id);
                            });
                        });
                });

                $query->orWhere(function (Builder $legacy) use ($shop): void {
                    $legacy->where('role', User::ROLE_ADMIN)
                        ->when($shop instanceof Shop, function (Builder $inner) use ($shop): void {
                            $inner->where(function (Builder $scope) use ($shop): void {
                                $scope->whereNull('shop_id')
                                    ->orWhere('shop_id', $shop->id);
                            });
                        });
                });
            })
            ->orderBy('name')
            ->get()
            ->unique('id')
            ->values();
    }

    /**
     * @return list<string>
     */
    public function activeAdminEmailsForShop(Shop $shop, array $roles = []): array
    {
        return $this->activeAdminUsers($shop, $roles)
            ->pluck('email')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function can(User $user, string $permission, Shop|Model|null $subject = null): bool
    {
        if (! $this->canAccessAdmin($user)) {
            return false;
        }

        $shop = $this->shopFromSubject($subject);
        $role = $this->roleFor($user, $shop);

        if ($role === null) {
            return false;
        }

        $permissions = self::ROLE_PERMISSIONS[$role] ?? [];

        if (in_array('*', $permissions, true)) {
            return true;
        }

        if (in_array($permission, $permissions, true)) {
            return true;
        }

        if (str_contains($permission, '.view')) {
            $managePermission = str_replace('.view', '.manage', $permission);

            if (in_array($managePermission, $permissions, true)) {
                return true;
            }
        }

        if (str_contains($permission, '.manage')) {
            $viewPermission = str_replace('.manage', '.view', $permission);

            return in_array($viewPermission, $permissions, true);
        }

        return false;
    }

    public function canAssignRole(User $actor, string $role, ?Shop $shop = null): bool
    {
        if ($role === ShopMembership::ROLE_PLATFORM_ADMIN) {
            return $this->isPlatformAdmin($actor);
        }

        return $this->can($actor, 'access.manage', $shop);
    }

    public function canReviewApprovals(User $actor, ?Shop $shop = null): bool
    {
        return $this->can($actor, 'approvals.review', $shop);
    }

    public function canManageSecrets(User $actor, ?Shop $shop = null): bool
    {
        return $this->can($actor, 'secrets.manage', $shop);
    }

    public function labelForRole(string $role): string
    {
        return match ($role) {
            ShopMembership::ROLE_PLATFORM_ADMIN => 'Platform admin',
            ShopMembership::ROLE_SHOP_ADMIN => 'Shop admin',
            ShopMembership::ROLE_OPERATOR => 'Operator',
            ShopMembership::ROLE_REVIEWER => 'Reviewer',
            ShopMembership::ROLE_OBSERVER => 'Observer',
            default => $role,
        };
    }

    private function shopFromSubject(Shop|Model|null $subject): ?Shop
    {
        if ($subject instanceof Shop) {
            return $subject;
        }

        if ($subject instanceof Model) {
            $shopId = $subject->getAttribute('shop_id');

            return $shopId ? Shop::query()->find((int) $shopId) : null;
        }

        return null;
    }
}
