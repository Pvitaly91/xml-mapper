<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesGovernanceInput;
use App\Services\Auth\AdminInvitationService;
use Illuminate\Console\Command;
use Throwable;

class AccessInviteCommand extends Command
{
    use ResolvesGovernanceInput;

    protected $signature = 'access:invite
        {email : Invite email}
        {role : Role to invite}
        {--shop= : Shop ID for shop-scoped roles}
        {--name= : Display name}
        {--note= : Invite reason}
        {--by= : Acting operator email or user id}';

    protected $description = 'Create an internal admin invite with membership linkage.';

    public function handle(AdminInvitationService $service): int
    {
        $actor = $this->requireActorOption($this->option('by') ? (string) $this->option('by') : null);

        try {
            $result = $service->createInvite([
                'email' => (string) $this->argument('email'),
                'role' => (string) $this->argument('role'),
                'shop_id' => $this->option('shop'),
                'name' => $this->option('name'),
                'note' => $this->option('note'),
            ], $actor);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $invite = $result['invite'];
        $membership = $result['membership'];

        $this->line(json_encode([
            'invite_id' => $invite->id,
            'membership_id' => $membership->id,
            'email' => $invite->email,
            'role' => $membership->role,
            'shop_id' => $membership->shop_id,
            'status' => $invite->status,
            'expires_at' => $invite->expires_at?->toIso8601String(),
            'accept_url' => $result['accept_url'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
