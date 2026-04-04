<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesGovernanceInput;
use App\Services\Auth\AdminAccountLifecycleService;
use Illuminate\Console\Command;
use Throwable;

class AuthForcePasswordResetCommand extends Command
{
    use ResolvesGovernanceInput;

    protected $signature = 'auth:force-password-reset
        {user : User id or email}
        {--reason= : Reset reason}
        {--by= : Acting operator email or user id}';

    protected $description = 'Mark an internal admin account as password-reset-required.';

    public function handle(AdminAccountLifecycleService $service): int
    {
        $subject = $this->resolveUserIdentifier((string) $this->argument('user'));
        $actor = $this->requireActorOption($this->option('by') ? (string) $this->option('by') : null);

        try {
            $service->forcePasswordReset($subject, $actor, $this->option('reason') ? (string) $this->option('reason') : null);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Password reset requirement applied to '.$subject->email.'.');

        return self::SUCCESS;
    }
}
