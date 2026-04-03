<?php

namespace App\Services\Feeds;

use App\Models\FeedProfile;
use App\Models\OpsRun;
use App\Models\SyncLog;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class FeedLiveTimelineService
{
    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function events(FeedProfile $feedProfile, array $filters = []): Collection
    {
        $from = $this->parseDate($filters['from'] ?? null);
        $to = $this->parseDate($filters['to'] ?? null, true);
        $eventType = $filters['event_type'] ?? null;
        $severity = $filters['severity'] ?? null;
        $events = collect()
            ->merge($this->releaseEvents($feedProfile, $from, $to))
            ->merge($this->syncLogs($feedProfile, $from, $to))
            ->merge($this->smokeChecks($feedProfile, $from, $to))
            ->merge($this->firstPulls($feedProfile, $from, $to))
            ->merge($this->opsRuns($feedProfile, $from, $to))
            ->sortByDesc('occurred_at')
            ->values();

        if (filled($eventType)) {
            $events = $events->where('event_type', $eventType)->values();
        }

        if (filled($severity)) {
            $events = $events->where('severity', $severity)->values();
        }

        return $events;
    }

    public function csv(FeedProfile $feedProfile, array $filters = []): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['occurred_at', 'event_type', 'severity', 'title', 'message', 'actor']);

        foreach ($this->events($feedProfile, $filters) as $event) {
            fputcsv($handle, [
                $event['occurred_at'],
                $event['event_type'],
                $event['severity'],
                $event['title'],
                $event['message'],
                $event['actor'],
            ]);
        }

        rewind($handle);

        return (string) stream_get_contents($handle);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function releaseEvents(FeedProfile $feedProfile, ?Carbon $from, ?Carbon $to): Collection
    {
        return $feedProfile->releaseEvents()
            ->with(['user', 'feedGeneration'])
            ->when($from !== null, fn ($query) => $query->where('occurred_at', '>=', $from))
            ->when($to !== null, fn ($query) => $query->where('occurred_at', '<=', $to))
            ->get()
            ->map(fn ($event) => [
                'occurred_at' => optional($event->occurred_at)->toIso8601String(),
                'event_type' => 'release_event',
                'severity' => $this->severityFromReleaseEvent($event->action, $event->meta ?? []),
                'title' => str_replace('_', ' ', $event->action),
                'message' => $event->reason ?: (($event->meta['title'] ?? null) ?: 'Release event recorded.'),
                'actor' => $event->user?->email ?: 'system',
            ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function syncLogs(FeedProfile $feedProfile, ?Carbon $from, ?Carbon $to): Collection
    {
        return SyncLog::query()
            ->where('feed_profile_id', $feedProfile->id)
            ->where('event', 'not like', 'release.%')
            ->when($from !== null, fn ($query) => $query->where('occurred_at', '>=', $from))
            ->when($to !== null, fn ($query) => $query->where('occurred_at', '<=', $to))
            ->get()
            ->map(fn (SyncLog $log) => [
                'occurred_at' => optional($log->occurred_at)->toIso8601String(),
                'event_type' => 'sync_log',
                'severity' => $log->level === 'error' ? 'critical' : ($log->level === 'warning' ? 'warning' : 'info'),
                'title' => $log->event,
                'message' => $log->message,
                'actor' => data_get($log->context, 'user_email', 'system'),
            ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function smokeChecks(FeedProfile $feedProfile, ?Carbon $from, ?Carbon $to): Collection
    {
        return $feedProfile->publishedGeneration?->smokeChecks()
            ->when($from !== null, fn ($query) => $query->where('checked_at', '>=', $from))
            ->when($to !== null, fn ($query) => $query->where('checked_at', '<=', $to))
            ->get()
            ->map(fn ($check) => [
                'occurred_at' => optional($check->checked_at)->toIso8601String(),
                'event_type' => 'smoke_check',
                'severity' => match ($check->status) {
                    'failed' => 'critical',
                    'warning' => 'warning',
                    default => 'info',
                },
                'title' => 'Smoke check '.$check->status,
                'message' => $check->errors[0] ?? $check->warnings[0] ?? 'Smoke check completed.',
                'actor' => $check->user?->email ?: 'system',
            ]) ?? collect();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function firstPulls(FeedProfile $feedProfile, ?Carbon $from, ?Carbon $to): Collection
    {
        return $feedProfile->firstPullVerifications()
            ->with('user')
            ->when($from !== null, fn ($query) => $query->where('verified_at', '>=', $from))
            ->when($to !== null, fn ($query) => $query->where('verified_at', '<=', $to))
            ->get()
            ->map(fn ($verification) => [
                'occurred_at' => optional($verification->verified_at)->toIso8601String(),
                'event_type' => 'first_pull',
                'severity' => match ($verification->status) {
                    'failed' => 'critical',
                    'warning' => 'warning',
                    default => 'info',
                },
                'title' => 'First-pull verification '.$verification->status,
                'message' => $verification->errors[0] ?? $verification->warnings[0] ?? 'First-pull verification completed.',
                'actor' => $verification->user?->email ?: 'system',
            ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function opsRuns(FeedProfile $feedProfile, ?Carbon $from, ?Carbon $to): Collection
    {
        return OpsRun::query()
            ->where('feed_profile_id', $feedProfile->id)
            ->whereIn('type', [
                OpsRun::TYPE_DEPLOY,
                OpsRun::TYPE_ROLLBACK,
                OpsRun::TYPE_REHEARSAL,
                OpsRun::TYPE_RESTORE_DRILL,
                OpsRun::TYPE_SECRET_ROTATION,
                OpsRun::TYPE_LAUNCH_PACK,
            ])
            ->when($from !== null, fn ($query) => $query->where('started_at', '>=', $from))
            ->when($to !== null, fn ($query) => $query->where('started_at', '<=', $to))
            ->get()
            ->map(fn (OpsRun $run) => [
                'occurred_at' => optional($run->started_at)->toIso8601String(),
                'event_type' => 'ops_run',
                'severity' => match ($run->status) {
                    OpsRun::STATUS_FAILED => 'critical',
                    OpsRun::STATUS_WARNING => 'warning',
                    default => 'info',
                },
                'title' => 'Ops '.$run->type,
                'message' => $run->error_message ?: ($run->summary['status'] ?? 'Ops run recorded.'),
                'actor' => $run->user?->email ?: 'system',
            ]);
    }

    private function parseDate(?string $value, bool $endOfDay = false): ?Carbon
    {
        if (blank($value)) {
            return null;
        }

        $date = Carbon::parse($value);

        return $endOfDay ? $date->endOfDay() : $date->startOfDay();
    }

    private function severityFromReleaseEvent(string $action, array $meta): string
    {
        if (($meta['severity'] ?? null) === 'critical') {
            return 'critical';
        }

        if (str_contains($action, 'failed') || str_contains($action, 'aborted') || str_contains($action, 'degraded')) {
            return 'critical';
        }

        if (str_contains($action, 'warning') || str_contains($action, 'rollback') || str_contains($action, 'freeze')) {
            return 'warning';
        }

        return 'info';
    }
}
