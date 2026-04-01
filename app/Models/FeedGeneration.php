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

    protected $attributes = [
        'status' => self::STATUS_PENDING,
        'items_total' => 0,
        'valid_items_total' => 0,
        'invalid_items_total' => 0,
    ];

    protected $fillable = [
        'shop_id',
        'feed_profile_id',
        'source_import_id',
        'status',
        'items_total',
        'valid_items_total',
        'invalid_items_total',
        'file_path',
        'published_path',
        'checksum',
        'built_at',
        'published_at',
        'error_message',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'built_at' => 'datetime',
            'published_at' => 'datetime',
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

    public function feedItems(): HasMany
    {
        return $this->hasMany(FeedItem::class, 'last_built_generation_id');
    }

    public function syncLogs(): HasMany
    {
        return $this->hasMany(SyncLog::class);
    }
}
