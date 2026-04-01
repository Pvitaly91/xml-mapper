<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ValidationError extends Model
{
    use HasFactory;

    public const CODE_MISSING_PRICE = 'missing_price';
    public const CODE_MISSING_PHOTO = 'missing_photo';
    public const CODE_MISSING_VENDOR = 'missing_vendor';
    public const CODE_MISSING_ARTICLE = 'missing_article';
    public const CODE_MISSING_CATEGORY_MAPPING = 'missing_category_mapping';
    public const CODE_MISSING_REQUIRED_ATTRIBUTE_MAPPING = 'missing_required_attribute_mapping';
    public const CODE_DUPLICATED_OR_UNSTABLE_OFFER_ID = 'duplicated_or_unstable_offer_id';

    protected $fillable = [
        'shop_id',
        'feed_profile_id',
        'source_product_id',
        'source_variant_id',
        'feed_item_id',
        'code',
        'severity',
        'message',
        'payload',
        'is_active',
        'detected_at',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'is_active' => 'boolean',
            'detected_at' => 'datetime',
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

    public function sourceProduct(): BelongsTo
    {
        return $this->belongsTo(SourceProduct::class);
    }

    public function sourceVariant(): BelongsTo
    {
        return $this->belongsTo(SourceVariant::class);
    }

    public function feedItem(): BelongsTo
    {
        return $this->belongsTo(FeedItem::class);
    }
}
