<?php

namespace App\Services\Ops;

use App\Models\FeedProfile;
use App\Models\OpsAlert;
use App\Models\OpsSilenceWindow;
use App\Models\User;
use App\Services\Feeds\FeedReleaseAuditService;
use Carbon\Carbon;
use Carbon\CarbonInterface;

class SilenceWindowService
{
    public function __construct(
        private readonly FeedReleaseAuditService $auditService,
    ) {}

    public function current(FeedProfile $feedProfile, ?CarbonInterface $reference = null): ?OpsSilenceWindow
    {
        $reference ??= now();

        return $feedProfile->silenceWindows()
            ->where(function ($query) use ($reference): void {
                $query->whereNull('active_from')
                    ->orWhere('active_from', '<=', $reference);
            })
            ->where(function ($query) use ($reference): void {
                $query->whereNull('active_to')
                    ->orWhere('active_to', '>=', $reference);
            })
            ->whereNull('cleared_at')
            ->latest('id')
            ->first();
    }

    public function start(
        FeedProfile $feedProfile,
        ?CarbonInterface $activeFrom = null,
        ?CarbonInterface $activeTo = null,
        string $severityThreshold = OpsAlert::SEVERITY_CRITICAL,
        ?string $note = null,
        ?User $user = null
    ): OpsSilenceWindow {
        $window = $this->current($feedProfile);

        if ($window instanceof OpsSilenceWindow) {
            $window->forceFill([
                'active_from' => $activeFrom ?? $window->active_from ?? now(),
                'active_to' => $activeTo,
                'severity_threshold' => $severityThreshold,
                'note' => $note,
                'user_id' => $user?->id ?? $window->user_id,
                'cleared_at' => null,
                'cleared_by_user_id' => null,
            ])->save();
        } else {
            $window = OpsSilenceWindow::create([
                'shop_id' => $feedProfile->shop_id,
                'feed_profile_id' => $feedProfile->id,
                'user_id' => $user?->id,
                'active_from' => $activeFrom ?? now(),
                'active_to' => $activeTo,
                'severity_threshold' => $severityThreshold,
                'note' => $note,
            ]);
        }

        $this->auditService->record(
            $feedProfile,
            $feedProfile->publishedGeneration ?? $feedProfile->latestGeneration,
            'silence_window_started',
            $user,
            $note,
            [
                'silence_window_id' => $window->id,
                'severity_threshold' => $window->severity_threshold,
                'active_from' => $window->active_from?->toIso8601String(),
                'active_to' => $window->active_to?->toIso8601String(),
            ]
        );

        return $window->fresh();
    }

    public function clear(FeedProfile $feedProfile, ?User $user = null, ?string $reason = null): int
    {
        $windows = $feedProfile->silenceWindows()
            ->whereNull('cleared_at')
            ->get();

        if ($windows->isEmpty()) {
            return 0;
        }

        foreach ($windows as $window) {
            $window->forceFill([
                'cleared_at' => now(),
                'cleared_by_user_id' => $user?->id,
            ])->save();
        }

        $this->auditService->record(
            $feedProfile,
            $feedProfile->publishedGeneration ?? $feedProfile->latestGeneration,
            'silence_window_cleared',
            $user,
            $reason,
            [
                'cleared_count' => $windows->count(),
            ]
        );

        return $windows->count();
    }

    public function parse(?string $value, FeedProfile $feedProfile): ?Carbon
    {
        if (blank($value)) {
            return null;
        }

        return Carbon::parse($value, $feedProfile->publishWindowTimezone());
    }
}
