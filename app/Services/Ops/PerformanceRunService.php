<?php

namespace App\Services\Ops;

use App\Models\FeedProfile;
use App\Models\PerformanceRun;
use App\Models\PerformanceRunStage;
use App\Models\Shop;
use App\Models\User;
use Throwable;

class PerformanceRunService
{
    public function __construct(
        private readonly PerformanceBudgetService $budgetService,
    ) {}

    /**
     * @param  array<string, mixed>  $dataset
     * @param  list<string>  $stages
     * @param  array<string, mixed>  $meta
     */
    public function start(
        string $runType,
        ?Shop $shop = null,
        ?FeedProfile $feedProfile = null,
        ?User $user = null,
        array $dataset = [],
        array $stages = [],
        ?string $label = null,
        ?string $note = null,
        array $meta = [],
    ): PerformanceRun {
        return PerformanceRun::create([
            'shop_id' => $shop?->id ?? $feedProfile?->shop_id,
            'feed_profile_id' => $feedProfile?->id,
            'user_id' => $user?->id,
            'run_type' => $runType,
            'status' => PerformanceRun::STATUS_RUNNING,
            'budget_status' => PerformanceRun::BUDGET_WITHIN,
            'environment_label' => (string) (config('feed_mediator.environment.label') ?: config('feed_mediator.environment.class')),
            'label' => $label,
            'dataset_products' => (int) ($dataset['products'] ?? 0),
            'dataset_variants' => (int) ($dataset['variants'] ?? 0),
            'dataset_images' => (int) ($dataset['images'] ?? 0),
            'stages' => $stages,
            'note' => $note,
            'meta' => $meta,
            'started_at' => now(),
        ]);
    }

    /**
     * @param  callable():array<string,mixed>  $callback
     * @return array{stage:PerformanceRunStage,result:array<string,mixed>,evaluation:array<string,mixed>}
     */
    public function measureStage(PerformanceRun $run, string $stage, callable $callback): array
    {
        $stageRecord = PerformanceRunStage::updateOrCreate(
            [
                'performance_run_id' => $run->id,
                'stage' => $stage,
            ],
            [
                'status' => PerformanceRunStage::STATUS_RUNNING,
                'budget_status' => PerformanceRun::BUDGET_WITHIN,
                'started_at' => now(),
                'warnings' => [],
                'errors' => [],
            ]
        );

        $startedAt = microtime(true);

        try {
            $result = $callback();
            $metrics = array_merge([
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'peak_memory_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
            ], $result);
            $evaluation = $this->budgetService->evaluateStage($stage, $metrics);

            $stageRecord->forceFill([
                'status' => PerformanceRunStage::STATUS_SUCCEEDED,
                'budget_status' => $evaluation['budget_status'],
                'processed_products' => (int) ($metrics['processed_products'] ?? $metrics['products'] ?? 0),
                'processed_variants' => (int) ($metrics['processed_variants'] ?? $metrics['variants'] ?? 0),
                'processed_rows' => (int) ($metrics['processed_rows'] ?? $metrics['rows'] ?? 0),
                'report_count' => (int) ($metrics['report_count'] ?? 0),
                'duration_ms' => (int) ($metrics['duration_ms'] ?? 0),
                'peak_memory_mb' => (float) ($metrics['peak_memory_mb'] ?? 0),
                'warnings' => $evaluation['warnings'],
                'meta' => $metrics,
                'finished_at' => now(),
            ])->save();

            return [
                'stage' => $stageRecord->fresh(),
                'result' => $metrics,
                'evaluation' => $evaluation,
            ];
        } catch (Throwable $exception) {
            $stageRecord->forceFill([
                'status' => PerformanceRunStage::STATUS_FAILED,
                'budget_status' => PerformanceRun::BUDGET_CRITICAL,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'peak_memory_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
                'errors' => [$exception->getMessage()],
                'finished_at' => now(),
            ])->save();

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  array<string, mixed>  $meta
     */
    public function finish(PerformanceRun $run, array $summary = [], array $meta = []): PerformanceRun
    {
        $run->loadMissing('stageRuns');
        $evaluation = $this->budgetService->evaluateRun($run->stageRuns);
        $durationMs = $run->started_at !== null ? $run->started_at->diffInMilliseconds(now()) : 0;
        $peakMemory = (float) $run->stageRuns->max('peak_memory_mb');

        $run->forceFill([
            'status' => $run->stageRuns->contains('status', PerformanceRunStage::STATUS_FAILED)
                ? PerformanceRun::STATUS_FAILED
                : ($evaluation['budget_status'] === PerformanceRun::BUDGET_WITHIN ? PerformanceRun::STATUS_SUCCEEDED : PerformanceRun::STATUS_WARNING),
            'budget_status' => $evaluation['budget_status'],
            'processed_products' => (int) $run->stageRuns->max('processed_products'),
            'processed_variants' => (int) $run->stageRuns->max('processed_variants'),
            'processed_rows' => (int) $run->stageRuns->max('processed_rows'),
            'duration_ms' => $durationMs,
            'peak_memory_mb' => $peakMemory > 0 ? $peakMemory : null,
            'report_counts' => [
                'stages' => $run->stageRuns->count(),
                'warning_stages' => $run->stageRuns->where('budget_status', PerformanceRun::BUDGET_WARNING)->count(),
                'critical_stages' => $run->stageRuns->where('budget_status', PerformanceRun::BUDGET_CRITICAL)->count(),
            ],
            'summary' => $summary,
            'warnings' => $evaluation['warnings'],
            'errors' => $run->stageRuns
                ->flatMap(fn (PerformanceRunStage $stage) => (array) ($stage->errors ?? []))
                ->values()
                ->all(),
            'meta' => array_merge($run->meta ?? [], $meta),
            'finished_at' => now(),
        ])->save();

        return $run->fresh('stageRuns');
    }

    public function fail(PerformanceRun $run, Throwable|string $error, array $summary = [], array $meta = []): PerformanceRun
    {
        $errorMessage = $error instanceof Throwable ? $error->getMessage() : $error;

        $run->forceFill([
            'status' => PerformanceRun::STATUS_FAILED,
            'budget_status' => PerformanceRun::BUDGET_CRITICAL,
            'summary' => $summary,
            'errors' => array_values(array_filter(array_merge((array) ($run->errors ?? []), [$errorMessage]))),
            'meta' => array_merge($run->meta ?? [], $meta),
            'duration_ms' => $run->started_at !== null ? $run->started_at->diffInMilliseconds(now()) : 0,
            'peak_memory_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
            'finished_at' => now(),
        ])->save();

        return $run->fresh('stageRuns');
    }

    public function latest(?Shop $shop = null, ?FeedProfile $feedProfile = null, ?string $type = null): ?PerformanceRun
    {
        return PerformanceRun::query()
            ->when($shop !== null, fn ($query) => $query->where('shop_id', $shop->id))
            ->when($feedProfile !== null, fn ($query) => $query->where('feed_profile_id', $feedProfile->id))
            ->when($type !== null, fn ($query) => $query->where('run_type', $type))
            ->latest('started_at')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function compareRecent(?Shop $shop = null, ?FeedProfile $feedProfile = null, ?string $type = null): array
    {
        $runs = PerformanceRun::query()
            ->with('stageRuns')
            ->when($shop !== null, fn ($query) => $query->where('shop_id', $shop->id))
            ->when($feedProfile !== null, fn ($query) => $query->where('feed_profile_id', $feedProfile->id))
            ->when($type !== null, fn ($query) => $query->where('run_type', $type))
            ->latest('started_at')
            ->limit(2)
            ->get();

        $current = $runs->first();
        $previous = $runs->skip(1)->first();

        if (! $current instanceof PerformanceRun) {
            return [
                'current' => null,
                'previous' => null,
                'overall' => $this->budgetService->evaluateRegression([], null),
                'stages' => [],
            ];
        }

        $stageComparisons = [];

        foreach ($current->stageRuns as $stage) {
            $previousStage = $previous?->stageRuns?->firstWhere('stage', $stage->stage);
            $stageComparisons[$stage->stage] = $this->budgetService->evaluateRegression(
                ['duration_ms' => $stage->duration_ms],
                $previousStage ? ['duration_ms' => $previousStage->duration_ms] : null,
            );
        }

        return [
            'current' => $current,
            'previous' => $previous,
            'overall' => $this->budgetService->evaluateRegression(
                ['duration_ms' => $current->duration_ms],
                $previous ? ['duration_ms' => $previous->duration_ms] : null,
            ),
            'stages' => $stageComparisons,
        ];
    }

    public function prune(int $days): int
    {
        return PerformanceRun::query()
            ->where('started_at', '<=', now()->subDays($days))
            ->delete();
    }
}
