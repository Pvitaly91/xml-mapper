<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FeedProfileCutover extends Model
{
    use HasFactory;

    public const STATUS_ONBOARDING_COMPLETE = 'onboarding_complete';

    public const STATUS_SYNC_VERIFIED = 'sync_verified';

    public const STATUS_MAPPINGS_RECONCILED = 'mappings_reconciled';

    public const STATUS_CANDIDATE_READY = 'candidate_ready';

    public const STATUS_SIGNOFF_COMPLETE = 'signoff_complete';

    public const STATUS_CUTOVER_SCHEDULED = 'cutover_scheduled';

    public const STATUS_CUTOVER_PUBLISHED = 'cutover_published';

    public const STATUS_FIRST_PULL_VERIFIED = 'first_pull_verified';

    public const STATUS_ACCEPTANCE_IN_PROGRESS = 'acceptance_in_progress';

    public const STATUS_PILOT_STABLE = 'pilot_stable';

    public const STATUS_CUTOVER_BLOCKED = 'cutover_blocked';

    protected $fillable = [
        'shop_id',
        'feed_profile_id',
        'target_generation_id',
        'published_generation_id',
        'initiated_by_user_id',
        'status',
        'is_current',
        'note',
        'planned_window_starts_at',
        'planned_window_ends_at',
        'actual_published_at',
        'first_verified_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'is_current' => 'boolean',
            'planned_window_starts_at' => 'datetime',
            'planned_window_ends_at' => 'datetime',
            'actual_published_at' => 'datetime',
            'first_verified_at' => 'datetime',
            'meta' => 'array',
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

    public function targetGeneration(): BelongsTo
    {
        return $this->belongsTo(FeedGeneration::class, 'target_generation_id');
    }

    public function publishedGeneration(): BelongsTo
    {
        return $this->belongsTo(FeedGeneration::class, 'published_generation_id');
    }

    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by_user_id');
    }

    public function firstPullVerifications(): HasMany
    {
        return $this->hasMany(FeedFirstPullVerification::class);
    }

    /**
     * @return list<string>
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_ONBOARDING_COMPLETE,
            self::STATUS_SYNC_VERIFIED,
            self::STATUS_MAPPINGS_RECONCILED,
            self::STATUS_CANDIDATE_READY,
            self::STATUS_SIGNOFF_COMPLETE,
            self::STATUS_CUTOVER_SCHEDULED,
            self::STATUS_CUTOVER_PUBLISHED,
            self::STATUS_FIRST_PULL_VERIFIED,
            self::STATUS_ACCEPTANCE_IN_PROGRESS,
            self::STATUS_PILOT_STABLE,
            self::STATUS_CUTOVER_BLOCKED,
        ];
    }
}
