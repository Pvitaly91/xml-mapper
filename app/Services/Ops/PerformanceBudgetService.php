<?php

namespace App\Services\Ops;

use App\Models\PerformanceRun;
use App\Models\PerformanceRunStage;

class PerformanceBudgetService
{
    /**
     * @param  array<string, mixed>  $metrics
     * @return array<string, mixed>
     */
    public function evaluateStage(string $stage, array $metrics): array
    {
        $budget = (array) config('feed_mediator.performance.budgets.'.$stage, []);
        $warnings = [];
        $status = PerformanceRun::BUDGET_WITHIN;

        $status = $this->applyThreshold(
            $status,
            (int) ($metrics['duration_ms'] ?? 0),
            (int) ($budget['warning_ms'] ?? 0),
            (int) ($budget['critical_ms'] ?? 0),
            sprintf('%s duration', $stage),
            'ms',
            $warnings,
        );

        $status = $this->applyThreshold(
            $status,
            (float) ($metrics['peak_memory_mb'] ?? 0),
            (float) ($budget['warning_memory_mb'] ?? 0),
            (float) ($budget['critical_memory_mb'] ?? 0),
            sprintf('%s peak memory', $stage),
            'MB',
            $warnings,
        );

        $status = $this->applyThreshold(
            $status,
            (int) ($metrics['queue_backlog'] ?? 0),
            (int) ($budget['warning_backlog'] ?? 0),
            (int) ($budget['critical_backlog'] ?? 0),
            sprintf('%s backlog', $stage),
            '',
            $warnings,
        );

        return [
            'budget_status' => $status,
            'warnings' => $warnings,
            'thresholds' => $budget,
        ];
    }

    /**
     * @param  array<int, PerformanceRunStage>|iterable<int, PerformanceRunStage>  $stages
     * @return array<string, mixed>
     */
    public function evaluateRun(iterable $stages): array
    {
        $status = PerformanceRun::BUDGET_WITHIN;
        $warnings = [];

        foreach ($stages as $stage) {
            $status = $this->maxStatus($status, (string) $stage->budget_status);

            foreach ((array) ($stage->warnings ?? []) as $warning) {
                $warnings[] = $warning;
            }
        }

        return [
            'budget_status' => $status,
            'warnings' => array_values(array_unique(array_filter($warnings, 'is_string'))),
        ];
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>|null  $previous
     * @return array<string, mixed>
     */
    public function evaluateRegression(array $current, ?array $previous): array
    {
        if ($previous === null || (int) ($previous['duration_ms'] ?? 0) <= 0) {
            return [
                'status' => PerformanceRun::BUDGET_WITHIN,
                'delta_pct' => null,
                'message' => 'No previous run available for regression comparison.',
            ];
        }

        $previousDuration = max(1, (int) ($previous['duration_ms'] ?? 0));
        $currentDuration = (int) ($current['duration_ms'] ?? 0);
        $deltaPct = round((($currentDuration - $previousDuration) / $previousDuration) * 100, 2);
        $warningPct = (float) config('feed_mediator.performance.compare.warning_regression_pct', 25);
        $criticalPct = (float) config('feed_mediator.performance.compare.critical_regression_pct', 50);

        $status = match (true) {
            $deltaPct >= $criticalPct => PerformanceRun::BUDGET_CRITICAL,
            $deltaPct >= $warningPct => PerformanceRun::BUDGET_WARNING,
            default => PerformanceRun::BUDGET_WITHIN,
        };

        return [
            'status' => $status,
            'delta_pct' => $deltaPct,
            'message' => sprintf(
                'Duration changed by %.2f%% versus the previous run (%d ms -> %d ms).',
                $deltaPct,
                $previousDuration,
                $currentDuration,
            ),
        ];
    }

    private function maxStatus(string $current, string $next): string
    {
        $rank = [
            PerformanceRun::BUDGET_WITHIN => 0,
            PerformanceRun::BUDGET_WARNING => 1,
            PerformanceRun::BUDGET_CRITICAL => 2,
        ];

        return ($rank[$next] ?? 0) > ($rank[$current] ?? 0) ? $next : $current;
    }

    /**
     * @param  array<int, string>  $warnings
     */
    private function applyThreshold(
        string $status,
        int|float $value,
        int|float $warningThreshold,
        int|float $criticalThreshold,
        string $label,
        string $unit,
        array &$warnings,
    ): string {
        if ($criticalThreshold > 0 && $value >= $criticalThreshold) {
            $warnings[] = sprintf('%s reached %s%s, which is above the critical threshold.', $label, $value, $unit !== '' ? ' '.$unit : '');

            return PerformanceRun::BUDGET_CRITICAL;
        }

        if ($warningThreshold > 0 && $value >= $warningThreshold) {
            $warnings[] = sprintf('%s reached %s%s, which is above the warning threshold.', $label, $value, $unit !== '' ? ' '.$unit : '');

            return $status === PerformanceRun::BUDGET_CRITICAL ? $status : PerformanceRun::BUDGET_WARNING;
        }

        return $status;
    }
}
