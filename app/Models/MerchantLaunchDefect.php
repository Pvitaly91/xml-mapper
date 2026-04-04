<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MerchantLaunchDefect extends Model
{
    use HasFactory;

    public const TYPE_DATA_QUALITY = 'data_quality';

    public const TYPE_MAPPING_GAP = 'mapping_gap';

    public const TYPE_SOURCE_SYNC_ISSUE = 'source_sync_issue';

    public const TYPE_EXPORT_CONFORMANCE = 'export_conformance_issue';

    public const TYPE_FEEDBACK_MATCHING = 'feedback_matching_issue';

    public const TYPE_PERFORMANCE = 'performance_issue';

    public const TYPE_OPS = 'ops_issue';

    public const TYPE_FALSE_POSITIVE = 'false_positive';

    public const SEVERITY_LOW = 'low';

    public const SEVERITY_MEDIUM = 'medium';

    public const SEVERITY_HIGH = 'high';

    public const SEVERITY_CRITICAL = 'critical';

    public const STATUS_OPEN = 'open';

    public const STATUS_TRIAGED = 'triaged';

    public const STATUS_FIXING = 'fixing';

    public const STATUS_MITIGATED = 'mitigated';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_WONT_FIX = 'wont_fix';

    protected $fillable = [
        'merchant_launch_id',
        'merchant_launch_observation_id',
        'user_id',
        'feed_generation_id',
        'feed_item_id',
        'feedback_record_id',
        'ops_alert_id',
        'type',
        'severity',
        'status',
        'title',
        'note',
        'resolution_note',
        'meta',
        'opened_at',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'opened_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function launch(): BelongsTo
    {
        return $this->belongsTo(MerchantLaunch::class, 'merchant_launch_id');
    }

    public function observation(): BelongsTo
    {
        return $this->belongsTo(MerchantLaunchObservation::class, 'merchant_launch_observation_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function generation(): BelongsTo
    {
        return $this->belongsTo(FeedGeneration::class, 'feed_generation_id');
    }

    public function feedItem(): BelongsTo
    {
        return $this->belongsTo(FeedItem::class);
    }

    public function feedbackRecord(): BelongsTo
    {
        return $this->belongsTo(FeedbackRecord::class);
    }

    public function alert(): BelongsTo
    {
        return $this->belongsTo(OpsAlert::class, 'ops_alert_id');
    }

    public function isOpen(): bool
    {
        return ! in_array($this->status, [self::STATUS_RESOLVED, self::STATUS_WONT_FIX], true);
    }

    public function isCritical(): bool
    {
        return $this->severity === self::SEVERITY_CRITICAL && $this->isOpen();
    }

    /**
     * @return list<string>
     */
    public static function types(): array
    {
        return [
            self::TYPE_DATA_QUALITY,
            self::TYPE_MAPPING_GAP,
            self::TYPE_SOURCE_SYNC_ISSUE,
            self::TYPE_EXPORT_CONFORMANCE,
            self::TYPE_FEEDBACK_MATCHING,
            self::TYPE_PERFORMANCE,
            self::TYPE_OPS,
            self::TYPE_FALSE_POSITIVE,
        ];
    }

    /**
     * @return list<string>
     */
    public static function severities(): array
    {
        return [
            self::SEVERITY_LOW,
            self::SEVERITY_MEDIUM,
            self::SEVERITY_HIGH,
            self::SEVERITY_CRITICAL,
        ];
    }

    /**
     * @return list<string>
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_OPEN,
            self::STATUS_TRIAGED,
            self::STATUS_FIXING,
            self::STATUS_MITIGATED,
            self::STATUS_RESOLVED,
            self::STATUS_WONT_FIX,
        ];
    }
}
