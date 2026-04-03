<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeedGenerationSignoff extends Model
{
    use HasFactory;

    public const STATUS_PENDING_REVIEW = 'pending_review';
    public const STATUS_INTERNAL_APPROVED = 'internal_approved';
    public const STATUS_CLIENT_REVIEW = 'client_review';
    public const STATUS_CLIENT_APPROVED = 'client_approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_SUPERSEDED = 'superseded';

    protected $fillable = [
        'shop_id',
        'feed_profile_id',
        'feed_generation_id',
        'user_id',
        'reviewer_name',
        'status',
        'is_current',
        'note',
        'reason',
        'reviewed_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'is_current' => 'boolean',
            'reviewed_at' => 'datetime',
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

    /**
     * @return list<string>
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_PENDING_REVIEW,
            self::STATUS_INTERNAL_APPROVED,
            self::STATUS_CLIENT_REVIEW,
            self::STATUS_CLIENT_APPROVED,
            self::STATUS_REJECTED,
            self::STATUS_SUPERSEDED,
        ];
    }
}
