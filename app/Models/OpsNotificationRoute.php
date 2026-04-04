<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OpsNotificationRoute extends Model
{
    use HasFactory;

    public const SCOPE_GLOBAL = 'global';

    public const SCOPE_SHOP = 'shop';

    public const SCOPE_FEED_PROFILE = 'feed_profile';

    public const CHANNEL_DATABASE = 'database';

    public const CHANNEL_LOG = 'log';

    public const CHANNEL_EMAIL = 'email';

    public const CHANNEL_WEBHOOK = 'webhook';

    protected $fillable = [
        'shop_id',
        'feed_profile_id',
        'user_id',
        'name',
        'scope',
        'channel',
        'event_family',
        'event_type',
        'minimum_severity',
        'enabled',
        'muted_until',
        'quiet_hours_start',
        'quiet_hours_end',
        'quiet_hours_timezone',
        'target_label',
        'target',
        'policy',
        'last_delivery_at',
        'last_delivery_status',
        'last_test_succeeded_at',
        'last_test_failed_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'muted_until' => 'datetime',
            'target' => 'encrypted:array',
            'policy' => 'array',
            'last_delivery_at' => 'datetime',
            'last_test_succeeded_at' => 'datetime',
            'last_test_failed_at' => 'datetime',
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(OpsNotificationDelivery::class);
    }

    public function isMuted(): bool
    {
        return $this->muted_until?->isFuture() ?? false;
    }

    /**
     * @return list<string>
     */
    public static function scopes(): array
    {
        return [
            self::SCOPE_GLOBAL,
            self::SCOPE_SHOP,
            self::SCOPE_FEED_PROFILE,
        ];
    }

    /**
     * @return list<string>
     */
    public static function channels(): array
    {
        return [
            self::CHANNEL_DATABASE,
            self::CHANNEL_LOG,
            self::CHANNEL_EMAIL,
            self::CHANNEL_WEBHOOK,
        ];
    }

    /**
     * @return list<string>
     */
    public static function eventFamilies(): array
    {
        return [
            '*',
            'source_auth_broken',
            'sync_failed',
            'build_failed',
            'publish_failed',
            'smoke_failed',
            'first_pull_failed',
            'promotion_blocked',
            'signoff_blocked',
            'hypercare_critical_issue',
            'rejection_spike',
            'launch_degraded',
            'rollback_executed',
            'test',
        ];
    }
}
