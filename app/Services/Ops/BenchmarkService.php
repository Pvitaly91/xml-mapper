<?php

namespace App\Services\Ops;

use App\Models\FeedProfile;
use App\Models\OpsRun;
use App\Models\User;
use App\Services\Feeds\FeedbackRemediationWorkbenchService;
use App\Services\Feeds\FeedOperationsService;
use App\Services\Feeds\FeedReconciliationService;
use App\Services\Shops\UnresolvedMappingsWorkbenchService;
use Throwable;

class BenchmarkService
{
    public function __construct(
        private readonly OpsRunService $opsRunService,
        private readonly FeedReconciliationService $reconciliationService,
        private readonly FeedbackRemediationWorkbenchService $feedbackWorkbenchService,
        private readonly UnresolvedMappingsWorkbenchService $unresolvedWorkbenchService,
        private readonly FeedOperationsService $operationsService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function run(FeedProfile $feedProfile, ?User $user = null): array
    {
        $run = $this->opsRunService->start(OpsRun::TYPE_BENCHMARK, $feedProfile->shop, $feedProfile, $user);

        try {
            $feedProfile->loadMissing(['sourceConnection.latestImport', 'latestGeneration', 'publishedGeneration']);

            $probes = [
                'reconciliation' => $this->probe(fn () => $this->reconciliationService->summarize($feedProfile)),
                'feedback_workbench' => $this->probe(fn () => $this->feedbackWorkbenchService->summarize($feedProfile)),
                'unresolved_workbench' => $this->probe(fn () => $this->unresolvedWorkbenchService->summarize($feedProfile)),
                'operations_summary' => $this->probe(fn () => $this->operationsService->summarize($feedProfile)),
            ];
            $latestSync = $feedProfile->sourceConnection?->latestImport()->latest('finished_at')->first();
            $latestGeneration = $feedProfile->latestGeneration;
            $latestPublished = $feedProfile->publishedGeneration;
            $latestSmoke = $latestPublished?->smokeChecks()->latest('checked_at')->first();
            $summary = [
                'latest_sync_duration_ms' => (int) ($latestSync?->meta['metrics']['duration_ms'] ?? 0),
                'latest_build_duration_ms' => (int) ($latestGeneration?->meta['metrics']['build_duration_ms'] ?? 0),
                'latest_publish_duration_ms' => (int) ($latestPublished?->meta['publish_metrics']['duration_ms'] ?? 0),
                'latest_smoke_check_duration_ms' => (int) ($latestSmoke?->meta['duration_ms'] ?? 0),
                'peak_memory_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
            ];
            $meta = [
                'probes' => $probes,
                'latest' => [
                    'sync_import_id' => $latestSync?->id,
                    'generation_id' => $latestGeneration?->id,
                    'published_generation_id' => $latestPublished?->id,
                ],
            ];

            $run = $this->opsRunService->finish($run, OpsRun::STATUS_SUCCEEDED, $summary, $meta);

            return [
                'run' => $run,
                'summary' => $summary,
                'probes' => $probes,
            ];
        } catch (Throwable $exception) {
            $this->opsRunService->fail($run, $exception);

            throw $exception;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function probe(callable $callback): array
    {
        gc_collect_cycles();
        $startedAt = microtime(true);
        $memoryBefore = memory_get_usage(true);
        $callback();
        $peak = memory_get_peak_usage(true);

        return [
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'memory_delta_mb' => round((memory_get_usage(true) - $memoryBefore) / 1024 / 1024, 2),
            'peak_memory_mb' => round($peak / 1024 / 1024, 2),
        ];
    }
}
