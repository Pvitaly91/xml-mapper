<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeedFirstPullVerification extends Model
{
    use HasFactory;

    public const STATUS_OK = 'ok';
    public const STATUS_WARNING = 'warning';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'shop_id',
        'feed_profile_id',
        'feed_generation_id',
        'feed_profile_cutover_id',
        'feed_generation_smoke_check_id',
        'user_id',
        'status',
        'latency_ms',
        'response_size_bytes',
        'offers_total',
        'categories_total',
        'response_checksum',
        'expected_checksum',
        'warnings',
        'errors',
        'verified_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'warnings' => 'array',
            'errors' => 'array',
            'verified_at' => 'datetime',
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

    public function feedGeneration(): BelongsTo
    {
        return $this->belongsTo(FeedGeneration::class);
    }

    public function cutover(): BelongsTo
    {
        return $this->belongsTo(FeedProfileCutover::class, 'feed_profile_cutover_id');
    }

    public function smokeCheck(): BelongsTo
    {
        return $this->belongsTo(FeedGenerationSmokeCheck::class, 'feed_generation_smoke_check_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
