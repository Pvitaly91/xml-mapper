<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MerchantLaunch extends Model
{
    use HasFactory;

    public const STATE_PLANNED = 'planned';

    public const STATE_EXECUTING = 'executing';

    public const STATE_PUBLISHED = 'published';

    public const STATE_VALIDATING = 'validating';

    public const STATE_DEGRADED = 'degraded';

    public const STATE_STABILIZED = 'stabilized';

    public const STATE_ROLLED_BACK = 'rolled_back';

    public const STATE_FAILED = 'failed';

    public const STATE_CLOSED = 'closed';

    public const HANDOVER_READY = 'ready_to_handover';

    public const HANDOVER_BLOCKED = 'handover_blocked';

    public const HANDOVER_DONE = 'handed_over';

    protected $fillable = [
        'shop_id',
        'feed_profile_id',
        'pilot_run_id',
        'promotion_run_id',
        'published_generation_id',
        'initiated_by_user_id',
        'owner_user_id',
        'environment_class',
        'environment_label',
        'state',
        'handover_state',
        'planned_start_at',
        'started_at',
        'actual_published_at',
        'actual_go_live_confirmed_at',
        'closed_at',
        'expected_ready_items',
        'expected_published_count',
        'expected_first_pull_latency_ms',
        'expected_feedback_total',
        'expected_rejection_total',
        'expected_sync_freshness_minutes',
        'outcome',
        'note',
        'summary',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'summary' => 'array',
            'meta' => 'array',
            'planned_start_at' => 'datetime',
            'started_at' => 'datetime',
            'actual_published_at' => 'datetime',
            'actual_go_live_confirmed_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function feedProfile(): BelongsTo
    {
        return $this->belongsTo(FeedProfile::class);
    }

    public function pilotRun(): BelongsTo
    {
        return $this->belongsTo(PilotRun::class);
    }

    public function promotionRun(): BelongsTo
    {
        return $this->belongsTo(PromotionRun::class);
    }

    public function publishedGeneration(): BelongsTo
    {
        return $this->belongsTo(FeedGeneration::class, 'published_generation_id');
    }

    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by_user_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function observations(): HasMany
    {
        return $this->hasMany(MerchantLaunchObservation::class);
    }

    public function defects(): HasMany
    {
        return $this->hasMany(MerchantLaunchDefect::class);
    }

    public function tuningActions(): HasMany
    {
        return $this->hasMany(MerchantLaunchTuningAction::class);
    }

    public function isClosed(): bool
    {
        return $this->state === self::STATE_CLOSED;
    }

    public function isOpen(): bool
    {
        return ! $this->isClosed();
    }

    /**
     * @return list<string>
     */
    public static function states(): array
    {
        return [
            self::STATE_PLANNED,
            self::STATE_EXECUTING,
            self::STATE_PUBLISHED,
            self::STATE_VALIDATING,
            self::STATE_DEGRADED,
            self::STATE_STABILIZED,
            self::STATE_ROLLED_BACK,
            self::STATE_FAILED,
            self::STATE_CLOSED,
        ];
    }

    /**
     * @return list<string>
     */
    public static function handoverStates(): array
    {
        return [
            self::HANDOVER_READY,
            self::HANDOVER_BLOCKED,
            self::HANDOVER_DONE,
        ];
    }
}
