<?php

namespace App\Console\Commands;

use App\Models\FeedProfile;
use App\Services\Ops\PerformanceWorkflowService;
use Illuminate\Console\Command;
use Throwable;

class OpsBenchmarkRunCommand extends Command
{
    protected $signature = 'ops:benchmark-run {feedProfileId} {--stages=sync,normalize,build,reconciliation,report_generation} {--label=}';

    protected $description = 'Execute a persisted benchmark run for the selected feed profile.';

    public function handle(PerformanceWorkflowService $service): int
    {
        $feedProfile = FeedProfile::findOrFail((int) $this->argument('feedProfileId'));
        $stages = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('stages')))));

        try {
            $run = $service->runBenchmark($feedProfile, $stages, null, $this->option('label') ? (string) $this->option('label') : null);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Benchmark finished.');
        $this->line('performance_run_id: '.$run->id);
        $this->line('status: '.$run->status);
        $this->line('budget_status: '.$run->budget_status);
        $this->line('duration_ms: '.$run->duration_ms);
        $this->line('peak_memory_mb: '.$run->peak_memory_mb);

        foreach ($run->stageRuns as $stage) {
            $this->line(sprintf(
                'stage[%s]: status=%s budget=%s duration_ms=%s',
                $stage->stage,
                $stage->status,
                $stage->budget_status,
                $stage->duration_ms ?? 'n/a'
            ));
        }

        return self::SUCCESS;
    }
}
