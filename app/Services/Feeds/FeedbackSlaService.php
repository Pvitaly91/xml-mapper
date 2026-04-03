<?php

namespace App\Services\Feeds;

use App\Models\FeedbackImport;
use App\Models\FeedbackRecord;
use App\Models\FeedHypercareWindow;
use App\Models\FeedProfile;
use Carbon\CarbonInterface;

class FeedbackSlaService
{
    /**
     * @return array<string, mixed>
     */
    public function summarize(
        FeedProfile $feedProfile,
        ?FeedHypercareWindow $hypercare = null,
        ?CarbonInterface $from = null,
        ?CarbonInterface $to = null
    ): array {
        [$from, $to] = $this->resolveWindow($hypercare, $from, $to);
        $records = FeedbackRecord::query()
            ->where('feed_profile_id', $feedProfile->id)
            ->when($from !== null, fn ($query) => $query->where('imported_at', '>=', $from))
            ->when($to !== null, fn ($query) => $query->where('imported_at', '<=', $to))
            ->get();
        $imports = FeedbackImport::query()
            ->where('feed_profile_id', $feedProfile->id)
            ->when($from !== null, fn ($query) => $query->where('imported_at', '>=', $from))
            ->when($to !== null, fn ($query) => $query->where('imported_at', '<=', $to))
            ->get();
        $avgAckMinutes = $records->filter(fn (FeedbackRecord $record) => $record->acknowledged_at !== null)
            ->avg(fn (FeedbackRecord $record) => $record->imported_at?->diffInMinutes($record->acknowledged_at));
        $avgResolveMinutes = $records->filter(fn (FeedbackRecord $record) => $record->resolved_at !== null)
            ->avg(fn (FeedbackRecord $record) => $record->imported_at?->diffInMinutes($record->resolved_at));
        $groupedReasons = $records
            ->where('status', FeedbackRecord::STATUS_REJECTED)
            ->groupBy(fn (FeedbackRecord $record) => ($record->rejection_reason_code ?: 'n/a').'|'.($record->rejection_reason_message ?: 'No reason'))
            ->map(function ($group, string $key): array {
                [$code, $message] = explode('|', $key, 2);

                return [
                    'reason_code' => $code,
                    'reason_message' => $message,
                    'count' => $group->count(),
                ];
            })
            ->sortByDesc('count')
            ->values();
        $trends = $records
            ->where('status', FeedbackRecord::STATUS_REJECTED)
            ->groupBy(function (FeedbackRecord $record): string {
                return ($record->imported_at?->toDateString() ?? now()->toDateString())
                    .'|'.($record->rejection_reason_code ?: 'n/a');
            })
            ->map(function ($group, string $key): array {
                [$date, $code] = explode('|', $key, 2);

                return [
                    'date' => $date,
                    'reason_code' => $code,
                    'count' => $group->count(),
                ];
            })
            ->sortBy('date')
            ->values();

        return [
            'window' => [
                'from' => $from?->toIso8601String(),
                'to' => $to?->toIso8601String(),
            ],
            'imports_total' => $imports->count(),
            'unmatched_feedback_count' => $records->whereNull('feed_item_id')->count(),
            'open_rejected_items' => $records
                ->where('status', FeedbackRecord::STATUS_REJECTED)
                ->filter(fn (FeedbackRecord $record) => in_array($record->resolution_status, [
                    FeedbackRecord::RESOLUTION_OPEN,
                    FeedbackRecord::RESOLUTION_IN_PROGRESS,
                ], true))
                ->count(),
            'open_total' => $records->where('resolution_status', FeedbackRecord::RESOLUTION_OPEN)->count(),
            'in_progress' => $records->where('resolution_status', FeedbackRecord::RESOLUTION_IN_PROGRESS)->count(),
            'fixed' => $records->where('resolution_status', FeedbackRecord::RESOLUTION_FIXED)->count(),
            'wont_fix' => $records->where('resolution_status', FeedbackRecord::RESOLUTION_WONT_FIX)->count(),
            'excluded' => $records->where('resolution_status', FeedbackRecord::RESOLUTION_EXCLUDED)->count(),
            'rejected_total' => $records->where('status', FeedbackRecord::STATUS_REJECTED)->count(),
            'warning_total' => $records->where('status', FeedbackRecord::STATUS_WARNING)->count(),
            'accepted_total' => $records->where('status', FeedbackRecord::STATUS_ACCEPTED)->count(),
            'average_time_to_acknowledge_minutes' => $avgAckMinutes !== null ? round($avgAckMinutes, 2) : null,
            'average_time_to_resolve_minutes' => $avgResolveMinutes !== null ? round($avgResolveMinutes, 2) : null,
            'grouped_reasons' => $groupedReasons->all(),
            'reason_trends' => $trends->all(),
            'pending_backlog' => $records->filter(fn (FeedbackRecord $record) => ! $record->isResolved())->count(),
        ];
    }

    /**
     * @return array{0:?CarbonInterface,1:?CarbonInterface}
     */
    private function resolveWindow(
        ?FeedHypercareWindow $hypercare = null,
        ?CarbonInterface $from = null,
        ?CarbonInterface $to = null
    ): array {
        if ($from !== null || $to !== null) {
            return [$from, $to];
        }

        if ($hypercare instanceof FeedHypercareWindow) {
            return [$hypercare->started_at, $hypercare->actual_end_at ?? now()];
        }

        return [null, null];
    }
}
