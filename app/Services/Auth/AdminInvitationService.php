<?php

namespace App\Services\Auth;

use App\Models\AdminInvite;
use App\Models\Shop;
use App\Models\ShopMembership;
use App\Models\User;
use App\Services\Access\AdminAccessService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdminInvitationService
{
    public function __construct(
        private readonly AdminAccessService $accessService,
        private readonly AdminAuthPolicyService $policyService,
        private readonly AdminAuthAuditService $auditService,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{invite:AdminInvite,membership:ShopMembership,accept_url:string}
     */
    public function createInvite(array $payload, User $actor): array
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

        if (! $this->accessService->canAssignRole($actor, $role, $shop)) {
            throw ValidationException::withMessages([
                'role' => 'You are not allowed to invite this role.',
            ]);
        }

        $email = mb_strtolower(trim((string) ($payload['email'] ?? '')));

        if ($email === '') {
            throw ValidationException::withMessages([
                'email' => 'Invite email is required.',
            ]);
        }

        return DB::transaction(function () use ($payload, $actor, $role, $shop, $email): array {
            $user = User::query()->firstOrCreate(
                ['email' => $email],
                [
                    'name' => (string) ($payload['name'] ?? 'Invited admin'),
                    'password' => Hash::make(Str::random(40)),
                    'role' => User::ROLE_ADMIN,
                    'is_active' => true,
                    'account_state' => User::STATE_INVITED,
                ]
            );

            if ($user->account_state === User::STATE_ACTIVE && blank($payload['allow_existing'] ?? null)) {
                throw ValidationException::withMessages([
                    'email' => 'This user already has an active account. Use direct membership assignment instead of invite.',
                ]);
            }

            $membership = ShopMembership::query()->updateOrCreate(
                [
                    'user_id' => $user->id,
                    'shop_id' => $role === ShopMembership::ROLE_PLATFORM_ADMIN ? null : $shop?->id,
                ],
                [
                    'role' => $role,
                    'status' => ShopMembership::STATUS_INVITED,
                    'invited_by_user_id' => $actor->id,
                    'updated_by_user_id' => $actor->id,
                    'note' => $payload['note'] ?? null,
                ]
            );

            $issued = $this->issueInviteToken($user, $membership, $actor, $payload['note'] ?? null);

            $this->auditService->record(
                'invite_created',
                'Internal admin invite created.',
                $actor,
                $shop,
                $membership,
                severity: 'warning',
                context: [
                    'invite_id' => $issued['invite']->id,
                    'membership_id' => $membership->id,
                    'role' => $role,
                    'email' => $email,
                    'expires_at' => $issued['invite']->expires_at?->toIso8601String(),
                ],
                targetLabel: $email
            );

            return [
                'invite' => $issued['invite'],
                'membership' => $membership,
                'accept_url' => $issued['accept_url'],
            ];
        });
    }

    /**
     * @return array{invite:AdminInvite,accept_url:string}
     */
    public function resend(AdminInvite $invite, User $actor): array
    {
        if ($invite->status === AdminInvite::STATUS_REVOKED) {
            throw ValidationException::withMessages([
                'invite' => 'Revoked invites cannot be resent.',
            ]);
        }

        if ($invite->membership?->status === ShopMembership::STATUS_REVOKED) {
            throw ValidationException::withMessages([
                'invite' => 'Membership is already revoked.',
            ]);
        }

        $issued = $this->issueInviteToken(
            $invite->user,
            $invite->membership,
            $actor,
            $invite->note,
            $invite
        );

        $this->auditService->record(
            'invite_resent',
            'Internal admin invite resent.',
            $actor,
            $invite->membership?->shop,
            $invite->membership,
            severity: 'warning',
            context: [
                'invite_id' => $invite->id,
                'membership_id' => $invite->shop_membership_id,
                'email' => $invite->email,
                'expires_at' => $issued['invite']->expires_at?->toIso8601String(),
            ],
            targetLabel: $invite->email
        );

        return $issued;
    }

    public function revoke(AdminInvite $invite, User $actor, ?string $note = null): AdminInvite
    {
        $membership = $invite->membership;

        $invite->forceFill([
            'status' => AdminInvite::STATUS_REVOKED,
            'revoked_at' => now(),
            'note' => $note ?: $invite->note,
            'token_ciphertext' => null,
        ])->save();

        if ($membership instanceof ShopMembership && $membership->status === ShopMembership::STATUS_INVITED) {
            $membership->forceFill([
                'status' => ShopMembership::STATUS_REVOKED,
                'updated_by_user_id' => $actor->id,
                'note' => $note ?: $membership->note,
            ])->save();
        }

        $this->auditService->record(
            'invite_revoked',
            'Internal admin invite revoked.',
            $actor,
            $membership?->shop,
            $membership,
            severity: 'warning',
            context: [
                'invite_id' => $invite->id,
                'membership_id' => $invite->shop_membership_id,
                'email' => $invite->email,
            ],
            targetLabel: $invite->email
        );

        return $invite->fresh(['membership', 'user', 'requestedBy']);
    }

    public function findByToken(string $token): ?AdminInvite
    {
        return AdminInvite::query()
            ->with(['user', 'membership.shop', 'requestedBy'])
            ->where('token_hash', hash('sha256', $token))
            ->first();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function accept(AdminInvite $invite, array $payload): User
    {
        if ($invite->status === AdminInvite::STATUS_REVOKED || $invite->revoked_at !== null) {
            throw ValidationException::withMessages([
                'invite' => 'This invite was revoked.',
            ]);
        }

        if ($invite->isExpired()) {
            $invite->forceFill(['status' => AdminInvite::STATUS_EXPIRED])->save();

            throw ValidationException::withMessages([
                'invite' => 'This invite expired.',
            ]);
        }

        $user = $invite->user;
        $membership = $invite->membership;
        $requiresPassword = $user->account_state === User::STATE_INVITED || blank($user->password);

        if ($requiresPassword && blank($payload['password'] ?? null)) {
            throw ValidationException::withMessages([
                'password' => 'Password is required to activate this invite.',
            ]);
        }

        DB::transaction(function () use ($invite, $payload, $user, $membership, $requiresPassword): void {
            $updates = [
                'name' => (string) ($payload['name'] ?: $user->name),
                'account_state' => User::STATE_ACTIVE,
                'is_active' => true,
                'invite_accepted_at' => now(),
                'password_reset_required_at' => null,
            ];

            if ($requiresPassword || filled($payload['password'] ?? null)) {
                $updates['password'] = Hash::make((string) $payload['password']);
                $updates['password_changed_at'] = now();
            }

            $user->forceFill($updates)->save();

            $membership?->forceFill([
                'status' => ShopMembership::STATUS_ACTIVE,
            ])->save();

            $invite->forceFill([
                'status' => AdminInvite::STATUS_ACCEPTED,
                'accepted_at' => now(),
                'token_ciphertext' => null,
            ])->save();
        });

        $this->auditService->record(
            'invite_accepted',
            'Internal admin invite accepted.',
            $user,
            $membership?->shop,
            $membership,
            severity: 'warning',
            context: [
                'invite_id' => $invite->id,
                'membership_id' => $membership?->id,
                'email' => $user->email,
            ],
            targetLabel: $user->email
        );

        return $user->fresh();
    }

    /**
     * @return array{invite:AdminInvite,accept_url:string}
     */
    private function issueInviteToken(
        User $user,
        ShopMembership $membership,
        User $actor,
        ?string $note = null,
        ?AdminInvite $invite = null,
    ): array {
        $plainToken = Str::random(64);
        $invite ??= new AdminInvite();
        $invite->fill([
            'user_id' => $user->id,
            'shop_membership_id' => $membership->id,
            'requested_by_user_id' => $actor->id,
            'status' => AdminInvite::STATUS_PENDING,
            'email' => $user->email,
            'token_hash' => hash('sha256', $plainToken),
            'token_ciphertext' => $plainToken,
            'note' => $note,
            'sent_at' => now(),
            'expires_at' => now()->addHours($this->policyService->inviteExpiryHours()),
            'last_resent_at' => $invite->exists ? now() : null,
            'revoked_at' => null,
            'accepted_at' => null,
        ])->save();

        return [
            'invite' => $invite->fresh(['membership.shop', 'user', 'requestedBy']),
            'accept_url' => route('admin.invites.show', ['token' => $plainToken]),
        ];
    }
}
