<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesGovernanceInput;
use App\Services\Auth\AdminAccountLifecycleService;
use Illuminate\Console\Command;
use Throwable;

class AuthMfaResetCommand extends Command
{
    use ResolvesGovernanceInput;

    protected $signature = 'auth:mfa:reset
        {user : User id or email}
        {--shop= : Optional shop ID for operator context}
        {--reason= : Reset reason}
        {--by= : Acting operator email or user id}';

    protected $description = 'Reset MFA enrollment and recovery material for an internal admin user.';

    public function handle(AdminAccountLifecycleService $service): int
    {
        $subject = $this->resolveUserIdentifier((string) $this->argument('user'));
        $actor = $this->requireActorOption($this->option('by') ? (string) $this->option('by') : null);
        $shop = $this->resolveShopOption($this->option('shop'));

        try {
            if ($shop !== null && ! $subject->memberships()->where('shop_id', $shop->id)->exists()) {
                throw new \RuntimeException('User does not have membership in the specified shop.');
            }

            $service->resetMfa($subject, $actor, $this->option('reason') ? (string) $this->option('reason') : null);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('MFA reset completed for '.$subject->email.'.');

        return self::SUCCESS;
    }
}
