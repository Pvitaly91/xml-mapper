<?php

namespace App\Console\Commands;

use App\Services\Ops\NotificationCenterService;
use Illuminate\Console\Command;

class OpsDeliveriesPruneCommand extends Command
{
    protected $signature = 'ops:deliveries:prune';

    protected $description = 'Prune old outbound delivery history entries.';

    public function handle(NotificationCenterService $service): int
    {
        $days = (int) config('feed_mediator.notifications.retention.delivery_days', 30);
        $deleted = $service->prune($days);

        $this->info('Pruned deliveries: '.$deleted);

        return self::SUCCESS;
    }
}
