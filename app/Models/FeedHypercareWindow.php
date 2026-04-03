<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FeedHypercareWindow extends Model
{
    use HasFactory;

    public const STATUS_PLANNED = 'planned';

    public const STATUS_ARMED = 'armed';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DEGRADED = 'degraded';

    public const STATUS_EXTENDED = 'extended';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_ABORTED = 'aborted';

    protected $fillable = [
        'shop_id',
        'feed_profile_id',
        'feed_generation_id',
        'initiated_by_user_id',
        'owner_user_id',
        'status',
        'escalation_level',
        'target_sla_minutes',
        'monitoring_cadence_minutes',
        'note',
        'started_at',
        'planned_end_at',
        'actual_end_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'started_at' => 'datetime',
            'planned_end_at' => 'datetime',
            'actual_end_at' => 'datetime',
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

    public function feedGeneration(): BelongsTo
    {
        return $this->belongsTo(FeedGeneration::class);
    }

    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by_user_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(OpsAlert::class, 'feed_hypercare_window_id');
    }

    public function policyResults(): HasMany
    {
        return $this->hasMany(OpsPolicyResult::class, 'feed_hypercare_window_id');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::terminalStatuses(), true);
    }

    public function isOpen(): bool
    {
        return ! $this->isTerminal();
    }

    /**
     * @return list<string>
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_PLANNED,
            self::STATUS_ARMED,
            self::STATUS_ACTIVE,
            self::STATUS_DEGRADED,
            self::STATUS_EXTENDED,
            self::STATUS_COMPLETED,
            self::STATUS_ABORTED,
        ];
    }

    /**
     * @return list<string>
     */
    public static function openStatuses(): array
    {
        return [
            self::STATUS_PLANNED,
            self::STATUS_ARMED,
            self::STATUS_ACTIVE,
            self::STATUS_DEGRADED,
            self::STATUS_EXTENDED,
        ];
    }

    /**
     * @return list<string>
     */
    public static function terminalStatuses(): array
    {
        return [
            self::STATUS_COMPLETED,
            self::STATUS_ABORTED,
        ];
    }
}
