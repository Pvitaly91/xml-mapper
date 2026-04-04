<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OpsNotificationDelivery extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending_delivery';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_FAILED = 'failed';

    public const STATUS_ACKNOWLEDGED = 'acknowledged';

    public const STATUS_SUPPRESSED = 'suppressed';

    public const STATUS_ESCALATED = 'escalated';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_DROPPED = 'dropped';

    protected $fillable = [
        'ops_notification_route_id',
        'shop_id',
        'feed_profile_id',
        'ops_alert_id',
        'merchant_launch_id',
        'feed_hypercare_window_id',
        'pilot_run_id',
        'event_family',
        'event_type',
        'severity',
        'channel',
        'target_label',
        'status',
        'attempts',
        'is_test',
        'correlation_id',
        'dedup_key',
        'summary',
        'rendered_payload',
        'last_error',
        'started_at',
        'delivered_at',
        'failed_at',
        'next_retry_at',
        'response_meta',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'is_test' => 'boolean',
            'response_meta' => 'array',
            'meta' => 'array',
            'started_at' => 'datetime',
            'delivered_at' => 'datetime',
            'failed_at' => 'datetime',
            'next_retry_at' => 'datetime',
        ];
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(OpsNotificationRoute::class, 'ops_notification_route_id');
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function feedProfile(): BelongsTo
    {
        return $this->belongsTo(FeedProfile::class);
    }

    public function alert(): BelongsTo
    {
        return $this->belongsTo(OpsAlert::class, 'ops_alert_id');
    }

    public function launch(): BelongsTo
    {
        return $this->belongsTo(MerchantLaunch::class, 'merchant_launch_id');
    }

    public function hypercareWindow(): BelongsTo
    {
        return $this->belongsTo(FeedHypercareWindow::class, 'feed_hypercare_window_id');
    }

    public function pilotRun(): BelongsTo
    {
        return $this->belongsTo(PilotRun::class);
    }

    /**
     * @return list<string>
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_DELIVERED,
            self::STATUS_FAILED,
            self::STATUS_ACKNOWLEDGED,
            self::STATUS_SUPPRESSED,
            self::STATUS_ESCALATED,
            self::STATUS_RESOLVED,
            self::STATUS_DROPPED,
        ];
    }
}
