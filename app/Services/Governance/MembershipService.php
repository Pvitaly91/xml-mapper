<?php

namespace App\Services\Governance;

use App\Models\Shop;
use App\Models\ShopMembership;
use App\Models\User;
use App\Services\Access\AdminAccessService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class MembershipService
{
    public function __construct(
        private readonly AdminAccessService $accessService,
        private readonly GovernanceAuditService $auditService,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function membersForShop(Shop $shop, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        return ShopMembership::query()
            ->with(['user', 'shop', 'invitedBy', 'updatedBy'])
            ->where('shop_id', $shop->id)
            ->when(filled($filters['status'] ?? null), fn ($query) => $query->where('status', $filters['status']))
            ->when(filled($filters['role'] ?? null), fn ($query) => $query->where('role', $filters['role']))
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @return Collection<int, ShopMembership>
     */
    public function listMembers(?Shop $shop = null): Collection
    {
        return ShopMembership::query()
            ->with(['user', 'shop'])
            ->when($shop instanceof Shop, fn ($query) => $query->where('shop_id', $shop->id))
            ->orderBy('shop_id')
            ->orderBy('user_id')
            ->get();
    }

    /**
     * @return Collection<int, ShopMembership>
     */
    public function membershipsForUser(User $user): Collection
    {
        return $user->memberships()->with('shop')->latest('id')->get();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function grant(array $payload, ?User $actor = null): ShopMembership
    {
        $role = (string) $payload['role'];
        $shop = isset($payload['shop_id']) && $payload['shop_id'] !== null
            ? Shop::query()->findOrFail((int) $payload['shop_id'])
            : null;

        if ($role !== ShopMembership::ROLE_PLATFORM_ADMIN && ! $shop instanceof Shop) {
            throw ValidationException::withMessages([
                'shop_id' => 'Shop is required for shop-scoped roles.',
            ]);
        }

        if ($actor instanceof User && ! $this->accessService->canAssignRole($actor, $role, $shop)) {
            throw ValidationException::withMessages([
                'role' => 'You are not allowed to assign this role.',
            ]);
        }

        $user = $this->resolveUser($payload);

        /** @var ShopMembership $membership */
        $membership = DB::transaction(function () use ($payload, $actor, $user, $shop, $role): ShopMembership {
            $membership = ShopMembership::query()->firstOrNew([
                'user_id' => $user->id,
                'shop_id' => $role === ShopMembership::ROLE_PLATFORM_ADMIN ? null : $shop?->id,
            ]);

            $membership->fill([
                'role' => $role,
                'status' => $payload['status'] ?? ShopMembership::STATUS_ACTIVE,
                'invited_by_user_id' => $membership->exists ? $membership->invited_by_user_id : $actor?->id,
                'updated_by_user_id' => $actor?->id,
                'note' => $payload['note'] ?? null,
            ])->save();

            if ($membership->status === ShopMembership::STATUS_ACTIVE && $membership->shop_id !== null) {
                $user->forceFill([
                    'shop_id' => $membership->shop_id,
                    'role' => User::ROLE_ADMIN,
                    'is_active' => true,
                ])->save();
            }

            return $membership->fresh(['user', 'shop']);
        });

        $this->auditService->record(
            'access',
            'membership_granted',
            sprintf('Role %s granted to %s.', $role, $membership->user?->email ?: 'user'),
            $actor,
            $shop,
            $membership,
            severity: 'warning',
            context: [
                'membership_id' => $membership->id,
                'role' => $role,
                'status' => $membership->status,
                'target_user_id' => $membership->user_id,
            ],
            targetLabel: $membership->user?->email
        );

        return $membership;
    }

    public function revoke(ShopMembership $membership, ?User $actor = null, ?string $note = null): ShopMembership
    {
        $shop = $membership->shop;

        if ($actor instanceof User && ! $this->accessService->canAssignRole($actor, $membership->role, $shop)) {
            throw ValidationException::withMessages([
                'membership' => 'You are not allowed to revoke this role.',
            ]);
        }

        $membership->forceFill([
            'status' => ShopMembership::STATUS_REVOKED,
            'updated_by_user_id' => $actor?->id,
            'note' => $note ?: $membership->note,
        ])->save();

        $this->auditService->record(
            'access',
            'membership_revoked',
            sprintf('Role %s revoked from %s.', $membership->role, $membership->user?->email ?: 'user'),
            $actor,
            $shop,
            $membership,
            severity: 'warning',
            context: [
                'membership_id' => $membership->id,
                'role' => $membership->role,
                'target_user_id' => $membership->user_id,
            ],
            targetLabel: $membership->user?->email
        );

        return $membership->fresh(['user', 'shop']);
    }

    public function updateStatus(ShopMembership $membership, string $status, ?User $actor = null, ?string $note = null): ShopMembership
    {
        if (! in_array($status, ShopMembership::statuses(), true)) {
            throw ValidationException::withMessages([
                'status' => 'Invalid membership status.',
            ]);
        }

        $shop = $membership->shop;

        if ($actor instanceof User && ! $this->accessService->canAssignRole($actor, $membership->role, $shop)) {
            throw ValidationException::withMessages([
                'membership' => 'You are not allowed to update this membership.',
            ]);
        }

        $membership->forceFill([
            'status' => $status,
            'updated_by_user_id' => $actor?->id,
            'note' => $note ?: $membership->note,
        ])->save();

        $eventType = match ($status) {
            ShopMembership::STATUS_ACTIVE => 'membership_activated',
            ShopMembership::STATUS_SUSPENDED => 'membership_suspended',
            ShopMembership::STATUS_INVITED => 'membership_invited',
            default => 'membership_updated',
        };

        $this->auditService->record(
            'access',
            $eventType,
            sprintf('Membership for %s moved to %s.', $membership->user?->email ?: 'user', $status),
            $actor,
            $shop,
            $membership,
            severity: $status === ShopMembership::STATUS_SUSPENDED ? 'warning' : 'info',
            context: [
                'membership_id' => $membership->id,
                'status' => $status,
                'target_user_id' => $membership->user_id,
            ],
            targetLabel: $membership->user?->email
        );

        return $membership->fresh(['user', 'shop']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveUser(array $payload): User
    {
        if (filled($payload['user_id'] ?? null)) {
            return User::query()->findOrFail((int) $payload['user_id']);
        }

        $email = trim((string) ($payload['email'] ?? ''));

        if ($email === '') {
            throw ValidationException::withMessages([
                'email' => 'User email is required.',
            ]);
        }

        $user = User::query()->where('email', $email)->first();

        if ($user instanceof User) {
            return $user;
        }

        if (blank($payload['name'] ?? null) || blank($payload['password'] ?? null)) {
            throw ValidationException::withMessages([
                'user' => 'Name and password are required to create a new internal user.',
            ]);
        }

        return User::query()->create([
            'name' => (string) $payload['name'],
            'email' => $email,
            'password' => Hash::make((string) $payload['password']),
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
    }
}
