<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesGovernanceInput;
use App\Services\Auth\AdminSessionService;
use Illuminate\Console\Command;

class AuthSessionsListCommand extends Command
{
    use ResolvesGovernanceInput;

    protected $signature = 'auth:sessions:list {user : User id or email}';

    protected $description = 'List persisted admin sessions for a user.';

    public function handle(AdminSessionService $service): int
    {
        $subject = $this->resolveUserIdentifier((string) $this->argument('user'));
        $sessions = $service->listForUser($subject);

        $this->table(
            ['Session', 'IP', 'Last Seen', 'MFA', 'Break-Glass', 'Revoked', 'Device'],
            $sessions->map(fn ($session) => [
                $session->id,
                $session->ip_address ?: 'n/a',
                optional($session->last_seen_at)->format('Y-m-d H:i:s') ?: 'n/a',
                optional($session->mfa_verified_at)->format('Y-m-d H:i:s') ?: 'no',
                optional($session->break_glass_expires_at)->format('Y-m-d H:i:s') ?: 'inactive',
                optional($session->revoked_at)->format('Y-m-d H:i:s') ?: 'active',
                $session->device_label ?: 'Unknown device',
            ])->all()
        );

        $this->info('Listed '.$sessions->count().' session(s) for '.$subject->email.'.');

        return self::SUCCESS;
    }
}
