<?php

namespace App\Console\Commands;

use App\Models\Shop;
use App\Services\Ops\NotificationCenterService;
use Illuminate\Console\Command;

class OpsNotifyTestCommand extends Command
{
    protected $signature = 'ops:notify:test {channel} {--target=} {--shop=}';

    protected $description = 'Send a test outbound notification through the selected channel.';

    public function handle(NotificationCenterService $service): int
    {
        $shop = $this->option('shop')
            ? Shop::findOrFail((int) $this->option('shop'))
            : Shop::query()->orderBy('id')->firstOrFail();
        $delivery = $service->testChannel(
            (string) $this->argument('channel'),
            $shop,
            $this->option('target') ? (string) $this->option('target') : null
        );

        $this->info(sprintf(
            'Test delivery #%d via %s => %s',
            $delivery->id,
            $delivery->channel,
            $delivery->status
        ));

        return self::SUCCESS;
    }
}
