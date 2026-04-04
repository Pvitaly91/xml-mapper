<?php

namespace App\Console\Commands;

use App\Models\Shop;
use App\Services\Ops\NotificationCenterService;
use Illuminate\Console\Command;

class OpsChannelsStatusCommand extends Command
{
    protected $signature = 'ops:channels:status {--shop=}';

    protected $description = 'Show operator-friendly health status for configured outbound notification channels.';

    public function handle(NotificationCenterService $service): int
    {
        $shop = $this->option('shop')
            ? Shop::findOrFail((int) $this->option('shop'))
            : Shop::query()->orderBy('id')->firstOrFail();
        $status = $service->channelStatus($shop);

        if (($status['routes'] ?? []) === []) {
            $this->line('No persisted routes configured. Fallback database/log routes remain active.');

            return self::SUCCESS;
        }

        foreach ($status['routes'] as $route) {
            $this->line(sprintf(
                '#%d %s [%s] %s => %s | last=%s | test_ok=%s | test_fail=%s',
                $route['id'],
                $route['name'],
                $route['scope'],
                $route['channel'],
                $route['target_label'] ?: 'n/a',
                $route['last_delivery_status'] ?: 'n/a',
                optional($route['last_test_succeeded_at'])->toDateTimeString() ?: 'n/a',
                optional($route['last_test_failed_at'])->toDateTimeString() ?: 'n/a',
            ));
        }

        return self::SUCCESS;
    }
}
