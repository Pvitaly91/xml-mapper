<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FeedGeneration extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_BUILDING = 'building';
    public const STATUS_BUILT = 'built';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_FAILED = 'failed';

    public const RELEASE_STATUS_BUILT = 'built';
    public const RELEASE_STATUS_CANDIDATE = 'candidate';
    public const RELEASE_STATUS_APPROVED = 'approved';
    public const RELEASE_STATUS_PUBLISHED = 'published';
    public const RELEASE_STATUS_SUPERSEDED = 'superseded';
    public const RELEASE_STATUS_ROLLED_BACK = 'rolled_back';
    public const RELEASE_STATUS_PUBLISH_FAILED = 'publish_failed';

    protected $attributes = [
        'status' => self::STATUS_PENDING,
        'release_status' => self::RELEASE_STATUS_BUILT,
        'items_total' => 0,
        'valid_items_total' => 0,
        'invalid_items_total' => 0,
    ];

    protected $fillable = [
        'shop_id',
        'feed_profile_id',
        'source_import_id',
        'status',
        'release_status',
        'items_total',
        'valid_items_total',
        'invalid_items_total',
        'file_path',
        'published_path',
        'checksum',
        'built_at',
        'published_at',
        'release_candidate_at',
        'approved_at',
        'approved_by_user_id',
        'last_smoke_check_status',
        'last_smoke_check_at',
        'error_message',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'built_at' => 'datetime',
            'published_at' => 'datetime',
            'release_candidate_at' => 'datetime',
            'approved_at' => 'datetime',
            'last_smoke_check_at' => 'datetime',
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

    public function sourceImport(): BelongsTo
    {
        return $this->belongsTo(SourceImport::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function feedItems(): HasMany
    {
        return $this->hasMany(FeedItem::class, 'last_built_generation_id');
    }

    public function syncLogs(): HasMany
    {
        return $this->hasMany(SyncLog::class);
    }

    public function smokeChecks(): HasMany
    {
        return $this->hasMany(FeedGenerationSmokeCheck::class);
    }

    public function releaseEvents(): HasMany
    {
        return $this->hasMany(FeedReleaseEvent::class);
    }

    /**
     * @return list<string>
     */
    public static function releaseStatuses(): array
    {
        return [
            self::RELEASE_STATUS_BUILT,
            self::RELEASE_STATUS_CANDIDATE,
            self::RELEASE_STATUS_APPROVED,
            self::RELEASE_STATUS_PUBLISHED,
            self::RELEASE_STATUS_SUPERSEDED,
            self::RELEASE_STATUS_ROLLED_BACK,
            self::RELEASE_STATUS_PUBLISH_FAILED,
        ];
    }
}
