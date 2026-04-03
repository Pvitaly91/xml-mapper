<?php

namespace App\Services\Feeds;

use App\Models\FeedProfile;
use App\Models\FeedbackRecord;
use App\Models\User;

class FeedbackRemediationWorkbenchService
{
    /**
     * @return array<string, mixed>
     */
    public function summarize(FeedProfile $feedProfile, array $filters = []): array
    {
        $query = FeedbackRecord::query()
            ->with(['feedItem.sourceVariant', 'feedItem.sourceProduct.sourceCategory', 'sourceVariant', 'sourceProduct', 'resolutionUser'])
            ->where('feed_profile_id', $feedProfile->id)
            ->latest('imported_at')
            ->latest('id');

        $query = $this->applyFilters($query, $filters);
        $records = $query->paginate(20)->withQueryString();
        $base = FeedbackRecord::query()->where('feed_profile_id', $feedProfile->id);
        $groupedReasons = (clone $base)
            ->selectRaw('coalesce(rejection_reason_code, ?) as reason_code, coalesce(rejection_reason_message, ?) as reason_message, count(*) as aggregate', ['n/a', 'No reason'])
            ->groupBy('reason_code', 'reason_message')
            ->orderByDesc('aggregate')
            ->get()
            ->map(fn ($row) => [
                'reason_code' => $row->reason_code,
                'reason_message' => $row->reason_message,
                'count' => (int) $row->aggregate,
            ])
            ->values();

        return [
            'records' => $records,
            'summary' => [
                'rejected' => (clone $base)->where('status', FeedbackRecord::STATUS_REJECTED)->count(),
                'warnings' => (clone $base)->where('status', FeedbackRecord::STATUS_WARNING)->count(),
                'unmatched' => (clone $base)->whereNull('feed_item_id')->count(),
                'open' => (clone $base)->where('resolution_status', FeedbackRecord::RESOLUTION_OPEN)->count(),
                'in_progress' => (clone $base)->where('resolution_status', FeedbackRecord::RESOLUTION_IN_PROGRESS)->count(),
            ],
            'grouped_reasons' => $groupedReasons,
            'filters' => $filters,
        ];
    }

    public function updateResolution(
        FeedbackRecord $record,
        string $resolutionStatus,
        ?string $note = null,
        ?User $user = null
    ): FeedbackRecord {
        $record->forceFill([
            'resolution_status' => $resolutionStatus,
            'resolution_note' => $note,
            'resolution_user_id' => $user?->id,
            'resolved_at' => in_array($resolutionStatus, [
                FeedbackRecord::RESOLUTION_FIXED,
                FeedbackRecord::RESOLUTION_WONT_FIX,
                FeedbackRecord::RESOLUTION_EXCLUDED,
            ], true) ? now() : null,
        ])->save();

        return $record->refresh();
    }

    private function applyFilters($query, array $filters)
    {
        if (($filters['problem'] ?? '') === 'unmatched_feedback') {
            $query->whereNull('feed_item_id');
        }

        if (($filters['problem'] ?? '') === 'missing_mapping') {
            $query->whereHas('feedItem', fn ($builder) => $builder->where('status', 'invalid_mapping'));
        }

        if (($filters['problem'] ?? '') === 'content_issues') {
            $query->where(function ($builder): void {
                $builder->where('rejection_reason_message', 'like', '%content%')
                    ->orWhereHas('feedItem', fn ($feedItems) => $feedItems->where('status', 'invalid_source'));
            });
        }

        if (($filters['problem'] ?? '') === 'image_issues') {
            $query->where(function ($builder): void {
                $builder->where('rejection_reason_message', 'like', '%image%')
                    ->orWhereHas('feedItem.activeValidationErrors', fn ($errors) => $errors->whereIn('code', ['invalid_image_url', 'not_enough_images']));
            });
        }

        if (($filters['problem'] ?? '') === 'pricing_issues') {
            $query->where(function ($builder): void {
                $builder->where('rejection_reason_message', 'like', '%price%')
                    ->orWhereHas('feedItem.activeValidationErrors', fn ($errors) => $errors->whereIn('code', ['invalid_price', 'price_below_threshold']));
            });
        }

        if (($filters['problem'] ?? '') === 'size_color_issues') {
            $query->where(function ($builder): void {
                $builder->where('rejection_reason_message', 'like', '%size%')
                    ->orWhere('rejection_reason_message', 'like', '%color%')
                    ->orWhereHas('feedItem.activeValidationErrors', fn ($errors) => $errors->whereIn('code', ['invalid_color', 'invalid_size']));
            });
        }

        if (($filters['status'] ?? '') !== '') {
            $query->where('status', $filters['status']);
        }

        if (($filters['resolution_status'] ?? '') !== '') {
            $query->where('resolution_status', $filters['resolution_status']);
        }

        return $query;
    }
}
