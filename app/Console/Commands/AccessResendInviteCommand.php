<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesGovernanceInput;
use App\Models\AdminInvite;
use App\Models\ShopMembership;
use App\Services\Auth\AdminInvitationService;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

class AccessResendInviteCommand extends Command
{
    use ResolvesGovernanceInput;

    protected $signature = 'access:resend-invite
        {inviteOrMembershipId : Invite ID or membership ID}
        {--by= : Acting operator email or user id}';

    protected $description = 'Resend an existing invite or re-issue one for an invited membership.';

    public function handle(AdminInvitationService $service): int
    {
        $actor = $this->requireActorOption($this->option('by') ? (string) $this->option('by') : null);

        try {
            $result = $this->resolveAndResend($service, (int) $this->argument('inviteOrMembershipId'), $actor);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $invite = $result['invite'];

        $this->line(json_encode([
            'invite_id' => $invite->id,
            'membership_id' => $invite->shop_membership_id,
            'email' => $invite->email,
            'status' => $invite->status,
            'expires_at' => $invite->expires_at?->toIso8601String(),
            'accept_url' => $result['accept_url'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /**
     * @return array{invite:AdminInvite,accept_url:string}
     */
    private function resolveAndResend(AdminInvitationService $service, int $identifier, \App\Models\User $actor): array
    {
        $invite = AdminInvite::query()->find($identifier);

        if ($invite instanceof AdminInvite) {
            return $service->resend($invite, $actor);
        }

        $membership = ShopMembership::query()->with('user')->find($identifier);

        if (! $membership instanceof ShopMembership) {
            throw new RuntimeException('Invite or membership was not found.');
        }

        $latestInvite = AdminInvite::query()
            ->where('shop_membership_id', $membership->id)
            ->latest('id')
            ->first();

        if ($latestInvite instanceof AdminInvite) {
            return $service->resend($latestInvite, $actor);
        }

        return $service->createInvite([
            'email' => $membership->user?->email,
            'name' => $membership->user?->name,
            'role' => $membership->role,
            'shop_id' => $membership->shop_id,
            'note' => $membership->note,
            'allow_existing' => true,
        ], $actor);
    }
}
