<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OpsAlert extends Model
{
    use HasFactory;

    public const STATE_RAISED = 'raised';

    public const STATE_ACKNOWLEDGED = 'acknowledged';

    public const STATE_SILENCED = 'silenced';

    public const STATE_ESCALATED = 'escalated';

    public const STATE_RESOLVED = 'resolved';

    public const STATE_FALSE_POSITIVE = 'false_positive';

    public const SEVERITY_INFO = 'info';

    public const SEVERITY_WARNING = 'warning';

    public const SEVERITY_CRITICAL = 'critical';

    public const SOURCE_SOURCE_AUTH_BROKEN = 'source_auth_broken';

    public const SOURCE_SYNC_FAILURE = 'sync_failure';

    public const SOURCE_BUILD_FAILURE = 'build_failure';

    public const SOURCE_PUBLISH_FAILURE = 'publish_failure';

    public const SOURCE_SMOKE_CHECK_FAILURE = 'smoke_check_failure';

    public const SOURCE_FIRST_PULL_FAILURE = 'first_pull_verification_failure';

    public const SOURCE_REJECTION_SPIKE = 'feedback_rejection_spike';

    public const SOURCE_PUBLISH_DELTA_ANOMALY = 'published_count_delta_anomaly';

    public const SOURCE_READY_ITEMS_COLLAPSE = 'ready_items_collapse';

    public const SOURCE_QUEUE_BACKLOG = 'queue_backlog_issue';

    protected $fillable = [
        'shop_id',
        'feed_profile_id',
        'feed_generation_id',
        'source_connection_id',
        'feed_hypercare_window_id',
        'acknowledged_by_user_id',
        'resolved_by_user_id',
        'silenced_by_user_id',
        'source',
        'state',
        'severity',
        'fingerprint',
        'title',
        'message',
        'reason',
        'note',
        'escalation_level',
        'first_raised_at',
        'last_raised_at',
        'acknowledged_at',
        'silenced_at',
        'escalated_at',
        'resolved_at',
        'false_positive_at',
        'last_reviewed_at',
        'context',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'first_raised_at' => 'datetime',
            'last_raised_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'silenced_at' => 'datetime',
            'escalated_at' => 'datetime',
            'resolved_at' => 'datetime',
            'false_positive_at' => 'datetime',
            'last_reviewed_at' => 'datetime',
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

    public function sourceConnection(): BelongsTo
    {
        return $this->belongsTo(SourceConnection::class);
    }

    public function hypercareWindow(): BelongsTo
    {
        return $this->belongsTo(FeedHypercareWindow::class, 'feed_hypercare_window_id');
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by_user_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    public function silencedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'silenced_by_user_id');
    }

    public function isOpen(): bool
    {
        return ! in_array($this->state, [self::STATE_RESOLVED, self::STATE_FALSE_POSITIVE], true);
    }

    public function isCritical(): bool
    {
        return $this->severity === self::SEVERITY_CRITICAL && $this->isOpen();
    }

    /**
     * @return list<string>
     */
    public static function states(): array
    {
        return [
            self::STATE_RAISED,
            self::STATE_ACKNOWLEDGED,
            self::STATE_SILENCED,
            self::STATE_ESCALATED,
            self::STATE_RESOLVED,
            self::STATE_FALSE_POSITIVE,
        ];
    }

    /**
     * @return list<string>
     */
    public static function severities(): array
    {
        return [
            self::SEVERITY_INFO,
            self::SEVERITY_WARNING,
            self::SEVERITY_CRITICAL,
        ];
    }

    public static function severityRank(string $severity): int
    {
        return match ($severity) {
            self::SEVERITY_CRITICAL => 3,
            self::SEVERITY_WARNING => 2,
            default => 1,
        };
    }
}
