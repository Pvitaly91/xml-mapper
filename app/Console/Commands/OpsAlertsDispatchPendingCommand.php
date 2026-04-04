<?php

namespace App\Console\Commands;

use App\Services\Ops\NotificationDeliveryService;
use Illuminate\Console\Command;

class OpsAlertsDispatchPendingCommand extends Command
{
    protected $signature = 'ops:alerts:dispatch-pending';

    protected $description = 'Dispatch or retry pending outbound alert deliveries.';

    public function handle(NotificationDeliveryService $service): int
    {
        $deliveries = $service->dispatchPending();
        $this->info('Processed deliveries: '.$deliveries->count());

        return self::SUCCESS;
    }
}
