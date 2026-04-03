<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeedbackRecord extends Model
{
    use HasFactory;

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_WARNING = 'warning';

    public const STATUS_UNKNOWN = 'unknown';

    public const RESOLUTION_OPEN = 'open';

    public const RESOLUTION_IN_PROGRESS = 'in_progress';

    public const RESOLUTION_FIXED = 'fixed';

    public const RESOLUTION_WONT_FIX = 'wont_fix';

    public const RESOLUTION_EXCLUDED = 'excluded';

    protected $fillable = [
        'shop_id',
        'feed_profile_id',
        'feedback_import_id',
        'feed_generation_id',
        'feed_item_id',
        'source_product_id',
        'source_variant_id',
        'resolution_user_id',
        'status',
        'resolution_status',
        'external_item_reference',
        'offer_id',
        'vendor_code',
        'article',
        'rejection_reason_code',
        'rejection_reason_message',
        'raw_payload',
        'resolution_note',
        'imported_at',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'raw_payload' => 'array',
            'imported_at' => 'datetime',
            'resolved_at' => 'datetime',
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

    public function import(): BelongsTo
    {
        return $this->belongsTo(FeedbackImport::class, 'feedback_import_id');
    }

    public function feedGeneration(): BelongsTo
    {
        return $this->belongsTo(FeedGeneration::class);
    }

    public function feedItem(): BelongsTo
    {
        return $this->belongsTo(FeedItem::class);
    }

    public function sourceProduct(): BelongsTo
    {
        return $this->belongsTo(SourceProduct::class);
    }

    public function sourceVariant(): BelongsTo
    {
        return $this->belongsTo(SourceVariant::class);
    }

    public function resolutionUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolution_user_id');
    }

    /**
     * @return list<string>
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_ACCEPTED,
            self::STATUS_REJECTED,
            self::STATUS_WARNING,
            self::STATUS_UNKNOWN,
        ];
    }

    /**
     * @return list<string>
     */
    public static function resolutionStatuses(): array
    {
        return [
            self::RESOLUTION_OPEN,
            self::RESOLUTION_IN_PROGRESS,
            self::RESOLUTION_FIXED,
            self::RESOLUTION_WONT_FIX,
            self::RESOLUTION_EXCLUDED,
        ];
    }
}
