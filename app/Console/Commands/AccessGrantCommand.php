<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesGovernanceInput;
use App\Services\Governance\MembershipService;
use Illuminate\Console\Command;
use Throwable;

class AccessGrantCommand extends Command
{
    use ResolvesGovernanceInput;

    protected $signature = 'access:grant
        {user : User id or email}
        {role : Role to grant}
        {--shop= : Shop ID for shop-scoped roles}
        {--status=active : Membership status}
        {--name= : Name when creating a new user by email}
        {--password= : Password when creating a new user by email}
        {--note= : Governance note}
        {--by= : Acting operator email or user id for audit attribution}';

    protected $description = 'Grant a membership role to a user.';

    public function handle(MembershipService $service): int
    {
        $identifier = (string) $this->argument('user');
        $actor = $this->resolveOptionalActor($this->option('by') ? (string) $this->option('by') : null);
        $existingUser = is_numeric($identifier)
            ? \App\Models\User::query()->find((int) $identifier)
            : \App\Models\User::query()->where('email', $identifier)->first();

        try {
            $membership = $service->grant([
                'user_id' => $existingUser?->id,
                'email' => $existingUser?->email ?: $identifier,
                'name' => $this->option('name'),
                'password' => $this->option('password'),
                'shop_id' => $this->option('shop'),
                'role' => (string) $this->argument('role'),
                'status' => (string) $this->option('status'),
                'note' => $this->option('note'),
            ], $actor);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Membership #%d saved for %s as %s (%s).',
            $membership->id,
            $membership->user?->email ?: 'n/a',
            $membership->role,
            $membership->status
        ));

        return self::SUCCESS;
    }
}
