<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeedGenerationSmokeCheck extends Model
{
    use HasFactory;

    public const STATUS_OK = 'ok';
    public const STATUS_WARNING = 'warning';
    public const STATUS_FAILED = 'failed';

    public const TRIGGER_AUTOMATIC = 'automatic';
    public const TRIGGER_MANUAL = 'manual';
    public const TRIGGER_COMMAND = 'command';

    protected $fillable = [
        'shop_id',
        'feed_profile_id',
        'feed_generation_id',
        'user_id',
        'trigger_source',
        'status',
        'http_status',
        'content_type',
        'latency_ms',
        'offers_total',
        'categories_total',
        'response_size_bytes',
        'response_checksum',
        'expected_checksum',
        'warnings',
        'errors',
        'checked_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'warnings' => 'array',
            'errors' => 'array',
            'meta' => 'array',
            'checked_at' => 'datetime',
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
