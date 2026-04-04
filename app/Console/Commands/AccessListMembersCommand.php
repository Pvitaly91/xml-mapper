<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesGovernanceInput;
use App\Services\Governance\MembershipService;
use Illuminate\Console\Command;

class AccessListMembersCommand extends Command
{
    use ResolvesGovernanceInput;

    protected $signature = 'access:list-members {--shop= : Shop ID}';

    protected $description = 'List shop-scoped and platform memberships.';

    public function handle(MembershipService $service): int
    {
        $shop = $this->resolveShopOption($this->option('shop'));
        $members = $service->listMembers($shop);

        $this->table(
            ['Membership', 'User', 'Email', 'Role', 'Status', 'Shop'],
            $members->map(fn ($membership) => [
                $membership->id,
                $membership->user?->name ?: 'n/a',
                $membership->user?->email ?: 'n/a',
                $membership->role,
                $membership->status,
                $membership->shop?->name ?: 'platform',
            ])->all()
        );

        $this->info('Listed '.$members->count().' membership record(s).');

        return self::SUCCESS;
    }
}
