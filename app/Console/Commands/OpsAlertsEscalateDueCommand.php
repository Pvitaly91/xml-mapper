<?php

namespace App\Console\Commands;

use App\Models\FeedProfile;
use App\Models\Shop;
use App\Services\Ops\OpsAlertService;
use Illuminate\Console\Command;

class OpsAlertsEscalateDueCommand extends Command
{
    protected $signature = 'ops:alerts:escalate-due {--shop=} {--profile=}';

    protected $description = 'Escalate due unacknowledged alerts using the configured policy.';

    public function handle(OpsAlertService $service): int
    {
        $shop = $this->option('shop') ? Shop::findOrFail((int) $this->option('shop')) : null;
        $profile = $this->option('profile') ? FeedProfile::findOrFail((int) $this->option('profile')) : null;
        $alerts = $service->escalateDue($shop, $profile);

        $this->info('Escalated alerts: '.$alerts->count());

        return self::SUCCESS;
    }
}
