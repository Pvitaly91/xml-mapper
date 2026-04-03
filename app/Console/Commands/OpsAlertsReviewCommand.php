<?php

namespace App\Console\Commands;

use App\Models\FeedHypercareWindow;
use App\Models\FeedProfile;
use App\Models\Shop;
use App\Services\Ops\HypercarePolicyService;
use App\Services\Ops\OpsAlertService;
use Illuminate\Console\Command;

class OpsAlertsReviewCommand extends Command
{
    protected $signature = 'ops:alerts:review {--shop= : Shop ID filter} {--profile= : Feed profile ID filter}';

    protected $description = 'Evaluate active hypercare policies and escalate overdue alerts.';

    public function handle(HypercarePolicyService $policyService, OpsAlertService $alertService): int
    {
        $shop = $this->option('shop') ? Shop::findOrFail((int) $this->option('shop')) : null;
        $profile = $this->option('profile') ? FeedProfile::findOrFail((int) $this->option('profile')) : null;
        $profiles = FeedProfile::query()
            ->when($shop !== null, fn ($query) => $query->where('shop_id', $shop->id))
            ->when($profile !== null, fn ($query) => $query->whereKey($profile->id))
            ->whereHas('hypercareWindows', fn ($query) => $query->whereIn('status', FeedHypercareWindow::openStatuses()))
            ->with('currentHypercareWindow')
            ->get();

        foreach ($profiles as $feedProfile) {
            $policyService->review($feedProfile, $feedProfile->currentHypercareWindow);
            $this->line('Reviewed alerts for feed profile #'.$feedProfile->id.'.');
        }

        $escalated = $alertService->escalateDue($shop, $profile);
        $this->info('Escalated alerts: '.$escalated->count());

        return self::SUCCESS;
    }
}
