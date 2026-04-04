<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MerchantLaunchObservation extends Model
{
    use HasFactory;

    public const TYPE_MERCHANT_CONFIRMATION = 'merchant_confirmation';

    public const TYPE_FIRST_PICKUP_CONFIRMED = 'first_marketplace_pickup_confirmed';

    public const TYPE_UNEXPECTED_REJECTION_PATTERN = 'unexpected_rejection_pattern';

    public const TYPE_FEED_DELAY = 'feed_delay_observed';

    public const TYPE_IMAGE_TREND = 'image_or_content_issue_trend';

    public const TYPE_MAPPING_ISSUE = 'mapping_issue_discovered';

    public const TYPE_PERFORMANCE_ISSUE = 'performance_issue';

    public const TYPE_FALSE_ALARM = 'false_alarm';

    public const SEVERITY_LOW = 'low';

    public const SEVERITY_MEDIUM = 'medium';

    public const SEVERITY_HIGH = 'high';

    public const SEVERITY_CRITICAL = 'critical';

    protected $fillable = [
        'merchant_launch_id',
        'user_id',
        'feed_generation_id',
        'feed_item_id',
        'feedback_import_id',
        'ops_alert_id',
        'type',
        'severity',
        'source',
        'note',
        'meta',
        'observed_at',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'observed_at' => 'datetime',
        ];
    }

    public function launch(): BelongsTo
    {
        return $this->belongsTo(MerchantLaunch::class, 'merchant_launch_id');
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

    public function feedbackImport(): BelongsTo
    {
        return $this->belongsTo(FeedbackImport::class);
    }

    public function alert(): BelongsTo
    {
        return $this->belongsTo(OpsAlert::class, 'ops_alert_id');
    }

    /**
     * @return list<string>
     */
    public static function types(): array
    {
        return [
            self::TYPE_MERCHANT_CONFIRMATION,
            self::TYPE_FIRST_PICKUP_CONFIRMED,
            self::TYPE_UNEXPECTED_REJECTION_PATTERN,
            self::TYPE_FEED_DELAY,
            self::TYPE_IMAGE_TREND,
            self::TYPE_MAPPING_ISSUE,
            self::TYPE_PERFORMANCE_ISSUE,
            self::TYPE_FALSE_ALARM,
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
}
