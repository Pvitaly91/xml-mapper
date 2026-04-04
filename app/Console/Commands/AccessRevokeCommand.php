<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesGovernanceInput;
use App\Models\ShopMembership;
use App\Services\Governance\MembershipService;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

class AccessRevokeCommand extends Command
{
    use ResolvesGovernanceInput;

    protected $signature = 'access:revoke
        {user : User id or email}
        {role : Role to revoke}
        {--shop= : Shop ID for shop-scoped roles}
        {--note= : Governance note}
        {--by= : Acting operator email or user id for audit attribution}';

    protected $description = 'Revoke a membership role from a user.';

    public function handle(MembershipService $service): int
    {
        $user = $this->resolveUserIdentifier((string) $this->argument('user'));
        $shop = $this->resolveShopOption($this->option('shop'));
        $actor = $this->resolveOptionalActor($this->option('by') ? (string) $this->option('by') : null);
        $query = ShopMembership::query()
            ->where('user_id', $user->id)
            ->where('role', (string) $this->argument('role'));

        if ($shop) {
            $query->where('shop_id', $shop->id);
        } elseif ((string) $this->argument('role') === ShopMembership::ROLE_PLATFORM_ADMIN) {
            $query->whereNull('shop_id');
        }

        try {
            $memberships = $query->get();

            if ($memberships->count() !== 1) {
                throw new RuntimeException('Membership lookup is ambiguous. Pass --shop=<id> for shop-scoped roles.');
            }

            $membership = $memberships->first();
            $membership = $service->revoke($membership, $actor, $this->option('note') ? (string) $this->option('note') : null);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Membership #%d revoked for %s.', $membership->id, $membership->user?->email ?: 'n/a'));

        return self::SUCCESS;
    }
}
