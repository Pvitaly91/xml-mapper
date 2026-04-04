<?php

namespace App\Console\Commands;

use App\Models\OpsNotificationDelivery;
use App\Services\Ops\NotificationCenterService;
use Illuminate\Console\Command;

class OpsNotifyRetryCommand extends Command
{
    protected $signature = 'ops:notify:retry {deliveryId}';

    protected $description = 'Retry a failed or pending outbound delivery.';

    public function handle(NotificationCenterService $service): int
    {
        $delivery = OpsNotificationDelivery::findOrFail((int) $this->argument('deliveryId'));
        $delivery = $service->retry($delivery);

        $this->info(sprintf(
            'Delivery #%d retried => %s (attempts: %d)',
            $delivery->id,
            $delivery->status,
            $delivery->attempts
        ));

        return self::SUCCESS;
    }
}
