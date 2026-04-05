<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeedItemMappingException extends Model
{
    use HasFactory;

    public const TYPE_CATEGORY = 'category';
    public const TYPE_ATTRIBUTE_VALUE = 'attribute_value';
    public const TYPE_CONTENT_FIELD = 'content_field';
    public const TYPE_CONTENT_IMAGES = 'content_images';

    protected $fillable = [
        'shop_id',
        'feed_profile_id',
        'feed_item_id',
        'source_product_id',
        'source_variant_id',
        'created_by_user_id',
        'exception_type',
        'target_key',
        'target_value',
        'target_label',
        'reason',
        'is_active',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
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

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return list<string>
     */
    public static function contentTypes(): array
    {
        return [
            self::TYPE_CONTENT_FIELD,
            self::TYPE_CONTENT_IMAGES,
        ];
    }
}
