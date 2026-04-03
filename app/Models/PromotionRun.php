<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PromotionRun extends Model
{
    use HasFactory;

    public const MODE_COMPARE = 'compare';

    public const MODE_DRY_RUN = 'dry_run';

    public const MODE_APPLY = 'apply';

    public const MODE_ROLLBACK = 'rollback';

    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_WARNING = 'warning';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_FAILED = 'failed';

    public const STRATEGY_SAFE_MERGE = 'safe_merge';

    public const STRATEGY_OVERWRITE_TARGET = 'overwrite_target';

    public const STRATEGY_SKIP_EXISTING_CONFLICTS = 'skip_existing_conflicts';

    protected $fillable = [
        'shop_id',
        'source_feed_profile_id',
        'target_feed_profile_id',
        'source_snapshot_id',
        'target_snapshot_id',
        'result_snapshot_id',
        'rollback_of_promotion_run_id',
        'user_id',
        'source_environment',
        'target_environment',
        'mode',
        'strategy',
        'status',
        'reason',
        'summary',
        'warnings',
        'errors',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'summary' => 'array',
            'warnings' => 'array',
            'errors' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function sourceFeedProfile(): BelongsTo
    {
        return $this->belongsTo(FeedProfile::class, 'source_feed_profile_id');
    }

    public function targetFeedProfile(): BelongsTo
    {
        return $this->belongsTo(FeedProfile::class, 'target_feed_profile_id');
    }

    public function sourceSnapshot(): BelongsTo
    {
        return $this->belongsTo(PromotionSnapshot::class, 'source_snapshot_id');
    }

    public function targetSnapshot(): BelongsTo
    {
        return $this->belongsTo(PromotionSnapshot::class, 'target_snapshot_id');
    }

    public function resultSnapshot(): BelongsTo
    {
        return $this->belongsTo(PromotionSnapshot::class, 'result_snapshot_id');
    }

    public function rollbackOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'rollback_of_promotion_run_id');
    }

    public function rollbacks(): HasMany
    {
        return $this->hasMany(self::class, 'rollback_of_promotion_run_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function canRollback(): bool
    {
        return $this->mode === self::MODE_APPLY
            && $this->status === self::STATUS_SUCCEEDED
            && $this->target_snapshot_id !== null;
    }

    /**
     * @return list<string>
     */
    public static function strategies(): array
    {
        return [
            self::STRATEGY_SAFE_MERGE,
            self::STRATEGY_OVERWRITE_TARGET,
            self::STRATEGY_SKIP_EXISTING_CONFLICTS,
        ];
    }
}
