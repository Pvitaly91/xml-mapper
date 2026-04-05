<?php

namespace App\Console\Commands;

use App\Services\Ops\OpsMaintenanceStatusService;
use App\Services\Ops\OpsStatusService;
use App\Services\Ops\PerformanceBudgetService;
use Illuminate\Console\Command;

class OpsQueueHealthCommand extends Command
{
    protected $signature = 'ops:queue-health';

    protected $description = 'Summarize current queue lag, failed jobs, and budget policy evaluation.';

    public function handle(
        OpsStatusService $statusService,
        OpsMaintenanceStatusService $maintenanceStatusService,
        PerformanceBudgetService $budgetService,
    ): int {
        $snapshot = $statusService->snapshot();
        $maintenance = $maintenanceStatusService->summarize();
        $queueBacklog = (array) ($maintenance['queue_backlog'] ?? []);
        $totalBacklog = (int) array_sum(array_filter($queueBacklog, 'is_numeric'));
        $budget = $budgetService->evaluateStage('queue_lag', ['queue_backlog' => $totalBacklog]);

        $this->line('queue_mode: '.$snapshot['queue_mode']);
        $this->line('failed_jobs: '.(int) data_get($snapshot, 'failed_jobs.count', 0));
        $this->line('budget_status: '.$budget['budget_status']);

        foreach ($queueBacklog as $queue => $size) {
            $this->line(sprintf('%s: %s', $queue, $size ?? 'n/a'));
        }

        foreach ($budget['warnings'] as $warning) {
            $this->warn($warning);
        }

        return self::SUCCESS;
    }
}
