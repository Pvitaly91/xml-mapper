<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesGovernanceInput;
use App\Services\Auth\AdminSessionService;
use Illuminate\Console\Command;
use Throwable;

class AuthSessionsRevokeCommand extends Command
{
    use ResolvesGovernanceInput;

    protected $signature = 'auth:sessions:revoke
        {user : User id or email}
        {--all : Revoke every session instead of preserving the most recent one}
        {--reason= : Revoke reason}
        {--by= : Acting operator email or user id}';

    protected $description = 'Revoke persisted admin sessions for a user.';

    public function handle(AdminSessionService $service): int
    {
        $subject = $this->resolveUserIdentifier((string) $this->argument('user'));
        $actor = $this->requireActorOption($this->option('by') ? (string) $this->option('by') : null);
        $all = (bool) $this->option('all');

        try {
            $exceptSessionId = null;

            if (! $all) {
                $exceptSessionId = $service->listForUser($subject)
                    ->whereNull('revoked_at')
                    ->sortByDesc('last_seen_at')
                    ->first()
                    ?->id;
            }

            $count = $service->revokeUserSessions(
                $subject,
                $actor,
                $all,
                $exceptSessionId,
                $this->option('reason') ? (string) $this->option('reason') : null
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Revoked %d session(s) for %s%s.',
            $count,
            $subject->email,
            $all ? '' : ' while preserving the most recent active session'
        ));

        return self::SUCCESS;
    }
}
