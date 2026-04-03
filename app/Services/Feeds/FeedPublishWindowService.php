<?php

namespace App\Services\Feeds;

use App\Models\FeedProfile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeZone;

class FeedPublishWindowService
{
    private const DAY_MAP = [
        1 => 'mon',
        2 => 'tue',
        3 => 'wed',
        4 => 'thu',
        5 => 'fri',
        6 => 'sat',
        7 => 'sun',
    ];

    public function __construct(
        private readonly FeedReleaseAuditService $auditService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function evaluate(FeedProfile $feedProfile, ?CarbonInterface $reference = null): array
    {
        $timezone = $this->resolveTimezone($feedProfile->publishWindowTimezone());
        $now = CarbonImmutable::instance($reference?->copy() ?? now())->setTimezone($timezone);
        $freeze = $feedProfile->freezeModeActive();
        $enabled = $feedProfile->publishWindowEnabled();
        $days = collect($feedProfile->publishWindowDays())
            ->map(static fn ($day) => mb_strtolower((string) $day))
            ->filter(static fn ($day) => in_array($day, array_values(self::DAY_MAP), true))
            ->values()
            ->all();
        $start = $feedProfile->publishWindowStart();
        $end = $feedProfile->publishWindowEnd();
        $dayAllowed = in_array(self::DAY_MAP[$now->dayOfWeekIso] ?? '', $days, true);
        $windowOpen = ! $enabled || ($dayAllowed && $this->isWithinWindow($now, $start, $end));
        $reasons = [];

        if ($freeze) {
            $reasons[] = 'Freeze mode is active.';
        }

        if ($enabled && ! $dayAllowed) {
            $reasons[] = sprintf('Publishing is not allowed on %s.', $now->isoFormat('dddd'));
        }

        if ($enabled && $dayAllowed && ! $this->isWithinWindow($now, $start, $end)) {
            $reasons[] = sprintf('Publishing is allowed only between %s and %s (%s).', $start, $end, $timezone);
        }

        return [
            'enabled' => $enabled,
            'freeze_active' => $freeze,
            'allowed_now' => ! $freeze && $windowOpen,
            'allowed_for_auto' => ! $freeze && $windowOpen,
            'timezone' => $timezone,
            'current_time' => $now->toIso8601String(),
            'window' => [
                'days' => $days,
                'start' => $start,
                'end' => $end,
            ],
            'next_allowed_at' => $this->nextAllowedAt($now, $enabled, $days, $start, $end)?->toIso8601String(),
            'reasons' => $reasons,
        ];
    }

    public function autoPublishAllowed(FeedProfile $feedProfile, ?CarbonInterface $reference = null): bool
    {
        return (bool) $this->evaluate($feedProfile, $reference)['allowed_for_auto'];
    }

    public function setFreezeMode(FeedProfile $feedProfile, bool $freeze, string $reason, ?User $user = null): FeedProfile
    {
        $settings = $feedProfile->exportSettings();
        $settings['freeze_mode'] = $freeze;

        $feedProfile->forceFill(['settings' => $settings])->save();

        $this->auditService->record(
            $feedProfile,
            $feedProfile->latestGeneration,
            $freeze ? 'freeze_enabled' : 'freeze_disabled',
            $user,
            $reason,
            ['freeze_mode' => $freeze]
        );

        return $feedProfile->refresh();
    }

    private function isWithinWindow(CarbonImmutable $reference, string $start, string $end): bool
    {
        [$startHour, $startMinute] = $this->parseTime($start);
        [$endHour, $endMinute] = $this->parseTime($end);

        $windowStart = $reference->setTime($startHour, $startMinute);
        $windowEnd = $reference->setTime($endHour, $endMinute);

        return $reference->greaterThanOrEqualTo($windowStart)
            && $reference->lessThanOrEqualTo($windowEnd);
    }

    private function nextAllowedAt(
        CarbonImmutable $reference,
        bool $enabled,
        array $days,
        string $start,
        string $end,
    ): ?CarbonImmutable {
        if (! $enabled) {
            return $reference;
        }

        if ($days === []) {
            return null;
        }

        [$startHour, $startMinute] = $this->parseTime($start);
        [$endHour, $endMinute] = $this->parseTime($end);

        for ($offset = 0; $offset <= 7; $offset++) {
            $candidateDay = $reference->startOfDay()->addDays($offset);
            $dayKey = self::DAY_MAP[$candidateDay->dayOfWeekIso] ?? null;

            if (! in_array($dayKey, $days, true)) {
                continue;
            }

            $windowStart = $candidateDay->setTime($startHour, $startMinute);
            $windowEnd = $candidateDay->setTime($endHour, $endMinute);

            if ($offset === 0 && $reference->lessThanOrEqualTo($windowStart)) {
                return $windowStart;
            }

            if ($offset === 0 && $reference->betweenIncluded($windowStart, $windowEnd)) {
                return $reference;
            }

            if ($offset > 0) {
                return $windowStart;
            }
        }

        return null;
    }

    /**
     * @return array{0:int,1:int}
     */
    private function parseTime(string $value): array
    {
        [$hour, $minute] = array_pad(explode(':', $value, 2), 2, '0');

        return [
            max(0, min(23, (int) $hour)),
            max(0, min(59, (int) $minute)),
        ];
    }

    private function resolveTimezone(string $timezone): string
    {
        try {
            new DateTimeZone($timezone);

            return $timezone;
        } catch (\Throwable) {
            return (string) config('app.timezone');
        }
    }
}
