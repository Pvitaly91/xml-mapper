<?php

namespace App\Services\Ops;

use App\Contracts\Feeds\FeedBuildServiceInterface;
use App\Contracts\Feeds\FeedPublishServiceInterface;
use App\Contracts\Source\SourceSyncWorkflowServiceInterface;
use App\Models\FeedGeneration;
use App\Models\FeedGenerationSmokeCheck;
use App\Models\FeedProfile;
use App\Models\OpsAlert;
use App\Models\PerformanceRun;
use App\Models\SourceImport;
use App\Models\User;
use App\Services\Feeds\FeedbackImportService;
use App\Services\Feeds\FeedReconciliationService;
use App\Services\Feeds\FeedReleaseReportService;
use Throwable;

class PerformanceBenchmarkService
{
    public function __construct(
        private readonly PerformanceRunService $performanceRunService,
        private readonly SourceSyncWorkflowServiceInterface $sourceSyncWorkflow,
        private readonly FeedBuildServiceInterface $buildService,
        private readonly FeedPublishServiceInterface $publishService,
        private readonly \App\Services\Feeds\FeedSmokeCheckService $smokeCheckService,
        private readonly FeedReconciliationService $reconciliationService,
        private readonly FeedReleaseReportService $reportService,
        private readonly FeedbackImportService $feedbackImportService,
        private readonly OpsStatusService $opsStatusService,
        private readonly OpsMaintenanceStatusService $opsMaintenanceStatusService,
        private readonly OpsAlertService $opsAlertService,
    ) {}

    /**
     * @param  list<string>  $stages
     */
    public function run(FeedProfile $feedProfile, array $stages, ?User $user = null, ?string $label = null): PerformanceRun
    {
        $feedProfile->loadMissing(['shop', 'sourceConnection', 'latestGeneration', 'publishedGeneration']);
        $dataset = [
            'products' => $feedProfile->sourceConnection?->products()->count() ?? 0,
            'variants' => $feedProfile->sourceConnection?->variants()->count() ?? 0,
            'images' => ($feedProfile->sourceConnection?->variants()->count() ?? 0) * 2,
        ];
        $run = $this->performanceRunService->start(
            PerformanceRun::TYPE_BENCHMARK,
            $feedProfile->shop,
            $feedProfile,
            $user,
            $dataset,
            $stages,
            $label,
        );
        $import = null;
        $generation = $feedProfile->latestGeneration;
        $published = $feedProfile->publishedGeneration;

        try {
            foreach ($stages as $stage) {
                switch ($stage) {
                    case 'sync':
                        $result = $this->performanceRunService->measureStage($run, 'sync', function () use ($feedProfile, &$import): array {
                            $import = $this->sourceSyncWorkflow->prepare($feedProfile->sourceConnection);

                            return [
                                'processed_rows' => (int) ($import->source_size_bytes ?? 0),
                                'rows' => (int) ($import->source_size_bytes ?? 0),
                                'meta' => ['source_import_id' => $import->id],
                            ];
                        });
                        break;

                    case 'normalize':
                        $result = $this->performanceRunService->measureStage($run, 'normalize', function () use ($feedProfile, &$import): array {
                            if (! $import instanceof SourceImport) {
                                $import = $this->sourceSyncWorkflow->prepare($feedProfile->sourceConnection);
                            }

                            $import = $this->sourceSyncWorkflow->normalize($import, false);
                            $summary = (array) ($feedProfile->sourceConnection?->last_sync_summary ?? []);

                            return [
                                'processed_products' => (int) ($summary['products'] ?? $import->products()->count()),
                                'processed_variants' => (int) ($summary['variants'] ?? $import->variants()->count()),
                                'processed_rows' => (int) ($summary['variants'] ?? $import->offers_total ?? 0),
                                'duration_ms' => (int) data_get($import->meta, 'metrics.duration_ms', 0),
                                'peak_memory_mb' => (float) data_get($import->meta, 'metrics.peak_memory_mb', 0),
                                'meta' => ['source_import_id' => $import->id],
                            ];
                        });
                        break;

                    case 'build':
                        $result = $this->performanceRunService->measureStage($run, 'build', function () use ($feedProfile, &$generation, &$import): array {
                            $generation = $this->buildService->build(
                                $feedProfile->fresh(),
                                $import instanceof SourceImport ? $import->id : null,
                            );

                            return [
                                'processed_products' => (int) $feedProfile->sourceConnection?->products()->count(),
                                'processed_variants' => (int) $generation->items_total,
                                'processed_rows' => (int) $generation->items_total,
                                'duration_ms' => (int) data_get($generation->meta, 'metrics.build_duration_ms', 0),
                                'peak_memory_mb' => (float) data_get($generation->meta, 'metrics.peak_memory_mb', 0),
                                'meta' => ['generation_id' => $generation->id],
                            ];
                        });
                        break;

                    case 'publish':
                        $result = $this->performanceRunService->measureStage($run, 'publish', function () use ($feedProfile, &$generation, &$published): array {
                            $generation ??= $feedProfile->fresh()->latestGeneration;
                            $published = $this->publishService->publish($feedProfile->fresh(), $generation);

                            return [
                                'processed_variants' => (int) $published->valid_items_total,
                                'processed_rows' => (int) $published->valid_items_total,
                                'duration_ms' => (int) data_get($published->meta, 'publish_metrics.duration_ms', 0),
                                'peak_memory_mb' => (float) data_get($published->meta, 'publish_metrics.peak_memory_mb', 0),
                                'meta' => ['generation_id' => $published->id],
                            ];
                        });
                        break;

                    case 'smoke':
                        $result = $this->performanceRunService->measureStage($run, 'smoke', function () use ($feedProfile, &$published): array {
                            $published ??= $feedProfile->fresh()->publishedGeneration ?? $feedProfile->fresh()->latestGeneration;
                            $smoke = $this->smokeCheckService->run(
                                $feedProfile->fresh(),
                                $published,
                                FeedGenerationSmokeCheck::TRIGGER_COMMAND,
                            );

                            return [
                                'processed_rows' => (int) ($smoke->offers_total ?? 0),
                                'duration_ms' => (int) data_get($smoke->meta, 'duration_ms', $smoke->latency_ms),
                                'peak_memory_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
                                'meta' => ['smoke_check_id' => $smoke->id],
                            ];
                        });
                        break;

                    case 'reconciliation':
                        $result = $this->performanceRunService->measureStage($run, 'reconciliation', function () use ($feedProfile): array {
                            $summary = $this->reconciliationService->summarize($feedProfile->fresh());

                            return [
                                'processed_rows' => (int) ($summary['counts']['published'] ?? 0),
                                'report_count' => count((array) ($summary['breakdown'] ?? [])),
                            ];
                        });
                        break;

                    case 'report_generation':
                        $result = $this->performanceRunService->measureStage($run, 'report_generation', function () use ($feedProfile, &$generation): array {
                            $generation ??= $feedProfile->fresh()->latestGeneration;
                            $report = $this->reportService->invalidItemsReport($feedProfile->fresh(), $generation);
                            $csv = $this->reportService->invalidItemsCsv($feedProfile->fresh(), $generation);

                            return [
                                'processed_rows' => (int) data_get($report, 'totals.invalid_items', 0),
                                'report_count' => count((array) ($report['rows'] ?? [])),
                                'meta' => ['csv_bytes' => strlen($csv)],
                            ];
                        });
                        break;

                    case 'feedback_import':
                        $result = $this->performanceRunService->measureStage($run, 'feedback_import', function () use ($feedProfile, &$published): array {
                            $fixture = (array) data_get($feedProfile->shop?->settings, 'scale_fixture', []);
                            $feedbackCsvPath = (string) ($fixture['feedback_csv_path'] ?? '');

                            if ($feedbackCsvPath === '' || ! is_file($feedbackCsvPath)) {
                                throw new \RuntimeException('Scale feedback CSV fixture is missing. Run ops:load-bootstrap first.');
                            }

                            $published ??= $feedProfile->fresh()->publishedGeneration ?? $feedProfile->fresh()->latestGeneration;
                            $result = $this->feedbackImportService->importContent(
                                $feedProfile->fresh(),
                                'csv',
                                (string) file_get_contents($feedbackCsvPath),
                                basename($feedbackCsvPath),
                                false,
                                null,
                                $published,
                            );

                            return [
                                'processed_rows' => count((array) ($result['rows'] ?? [])),
                                'report_count' => (int) data_get($result, 'summary.matched', 0),
                                'meta' => ['feedback_import_id' => data_get($result, 'import.id')],
                            ];
                        });
                        break;

                    default:
                        $result = $this->performanceRunService->measureStage($run, $stage, fn (): array => ['warnings' => ['Unknown stage skipped.']]);
                        break;
                }

                unset($result);
            }

            $queueHealth = $this->queueHealthSummary($feedProfile);
            $completed = $this->performanceRunService->finish($run, [
                'queue_health' => $queueHealth,
                'latest_generation_id' => $generation?->id,
                'published_generation_id' => $published?->id,
            ]);

            if ($completed->budget_status === PerformanceRun::BUDGET_CRITICAL) {
                $this->opsAlertService->raiseForProfile(
                    $feedProfile->fresh(),
                    OpsAlert::SOURCE_PERFORMANCE_BUDGET,
                    OpsAlert::SEVERITY_CRITICAL,
                    'Performance budget exceeded',
                    'Latest benchmark exceeded configured critical performance thresholds.',
                    [
                        'performance_run_id' => $completed->id,
                        'budget_status' => $completed->budget_status,
                    ],
                    $published instanceof FeedGeneration ? $published : $generation,
                );
            } else {
                $this->opsAlertService->resolveFingerprint($feedProfile->fresh(), OpsAlert::SOURCE_PERFORMANCE_BUDGET, 'Performance budgets are within configured thresholds.');
            }

            return $completed;
        } catch (Throwable $exception) {
            $failed = $this->performanceRunService->fail($run, $exception);
            $this->opsAlertService->raiseForProfile(
                $feedProfile->fresh(),
                OpsAlert::SOURCE_PERFORMANCE_BUDGET,
                OpsAlert::SEVERITY_WARNING,
                'Performance benchmark failed',
                $exception->getMessage(),
                [
                    'performance_run_id' => $failed->id,
                    'run_type' => $failed->run_type,
                ],
                $published instanceof FeedGeneration ? $published : $generation,
            );

            throw $exception;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function queueHealthSummary(FeedProfile $feedProfile): array
    {
        $snapshot = $this->opsStatusService->snapshot($feedProfile->shop);
        $maintenance = $this->opsMaintenanceStatusService->summarize($feedProfile->shop, $feedProfile);
        $backlog = (array) ($maintenance['queue_backlog'] ?? []);
        $sizes = (array) data_get($snapshot, 'failed_jobs', []);
        $totalBacklog = (int) array_sum(array_filter($backlog, 'is_numeric'));
        $budget = app(PerformanceBudgetService::class)->evaluateStage('queue_lag', [
            'queue_backlog' => $totalBacklog,
        ]);

        return [
            'queue_mode' => $snapshot['queue_mode'] ?? 'unknown',
            'queues' => $backlog,
            'failed_jobs' => (int) ($sizes['count'] ?? 0),
            'total_backlog' => $totalBacklog,
            'budget_status' => $budget['budget_status'],
            'warnings' => $budget['warnings'],
        ];
    }
}
