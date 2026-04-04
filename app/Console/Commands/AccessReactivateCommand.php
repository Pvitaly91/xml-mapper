<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesGovernanceInput;
use App\Services\Auth\AdminAccountLifecycleService;
use Illuminate\Console\Command;
use Throwable;

class AccessReactivateCommand extends Command
{
    use ResolvesGovernanceInput;

    protected $signature = 'access:reactivate
        {user : User id or email}
        {--shop= : Optional shop ID for membership reactivation}
        {--reason= : Reactivation reason}
        {--by= : Acting operator email or user id}';

    protected $description = 'Reactivate a user globally or within a specific shop membership.';

    public function handle(AdminAccountLifecycleService $service): int
    {
        $subject = $this->resolveUserIdentifier((string) $this->argument('user'));
        $shop = $this->resolveShopOption($this->option('shop'));
        $actor = $this->requireActorOption($this->option('by') ? (string) $this->option('by') : null);

        try {
            $service->reactivate($subject, $actor, $shop, $this->option('reason') ? (string) $this->option('reason') : null);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Reactivated %s%s.',
            $subject->email,
            $shop ? ' for shop #'.$shop->id : ' globally'
        ));

        return self::SUCCESS;
    }
}
