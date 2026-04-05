<?php

namespace App\Console\Commands;

use App\Services\Ops\PerformanceRunService;
use Illuminate\Console\Command;

class OpsPrunePerformanceRunsCommand extends Command
{
    protected $signature = 'ops:prune-performance-runs {--days=}';

    protected $description = 'Prune old persisted performance runs.';

    public function handle(PerformanceRunService $service): int
    {
        $days = $this->option('days')
            ? max(1, (int) $this->option('days'))
            : (int) config('feed_mediator.performance.retention_days', 30);
        $deleted = $service->prune($days);

        $this->info(sprintf('Pruned %d performance runs older than %d days.', $deleted, $days));

        return self::SUCCESS;
    }
}
