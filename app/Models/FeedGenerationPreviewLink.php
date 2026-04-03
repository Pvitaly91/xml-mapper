<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeedGenerationPreviewLink extends Model
{
    use HasFactory;

    protected $fillable = [
        'shop_id',
        'feed_profile_id',
        'feed_generation_id',
        'user_id',
        'token',
        'last_smoke_check_status',
        'last_smoke_check_at',
        'expires_at',
        'revoked_at',
        'last_accessed_at',
        'note',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'last_smoke_check_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_accessed_at' => 'datetime',
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
