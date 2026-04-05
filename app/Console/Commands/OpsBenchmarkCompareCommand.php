<?php

namespace App\Console\Commands;

use App\Models\FeedProfile;
use App\Models\Shop;
use App\Services\Ops\PerformanceRunService;
use Illuminate\Console\Command;

class OpsBenchmarkCompareCommand extends Command
{
    protected $signature = 'ops:benchmark-compare {--shop=} {--profile=} {--type=benchmark}';

    protected $description = 'Compare the latest persisted performance runs and highlight regressions.';

    public function handle(PerformanceRunService $service): int
    {
        $shop = $this->option('shop') ? Shop::findOrFail((int) $this->option('shop')) : null;
        $profile = $this->option('profile') ? FeedProfile::findOrFail((int) $this->option('profile')) : null;
        $comparison = $service->compareRecent($shop, $profile, (string) $this->option('type'));

        if ($comparison['current'] === null) {
            $this->warn('No performance runs found for the selected scope.');

            return self::SUCCESS;
        }

        $this->line('current_run_id: '.$comparison['current']->id);
        $this->line('previous_run_id: '.($comparison['previous']?->id ?? 'n/a'));
        $this->line('overall_status: '.$comparison['overall']['status']);
        $this->line('overall_message: '.$comparison['overall']['message']);

        foreach ($comparison['stages'] as $stage => $stageComparison) {
            $this->line(sprintf(
                '%s: %s (%s)',
                $stage,
                $stageComparison['status'],
                $stageComparison['message']
            ));
        }

        return self::SUCCESS;
    }
}
