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

    public const CODE_PRICE_BELOW_THRESHOLD = 'price_below_threshold';

    public const CODE_MISSING_CATEGORY_MAPPING = 'missing_category_mapping';

    public const CODE_MISSING_REQUIRED_ATTRIBUTE_MAPPING = 'missing_required_attribute_mapping';

    public const CODE_MISSING_REQUIRED_ATTRIBUTE_VALUE = 'missing_required_attribute_value';

    public const CODE_MISSING_VALUE_MAPPING = 'missing_value_mapping';

    public const CODE_INVALID_VENDOR = 'invalid_vendor';

    public const CODE_INVALID_ARTICLE = 'invalid_article';

    public const CODE_INVALID_COLOR = 'invalid_color';

    public const CODE_INVALID_SIZE = 'invalid_size';

    public const CODE_INVALID_IMAGE_URL = 'invalid_image_url';

    public const CODE_DUPLICATED_OR_UNSTABLE_OFFER_ID = 'duplicated_or_unstable_offer_id';

    public const CODE_INVALID_TITLE = 'invalid_title';

    public const CODE_INVALID_DESCRIPTION = 'invalid_description';

    public const CODE_INSUFFICIENT_IMAGES = 'insufficient_images';

    public const CODE_INVALID_SIZE_GRID = 'invalid_size_grid';

    public const CODE_VARIANT_GROUPING_ISSUE = 'variant_grouping_issue';

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

    /**
     * @return list<string>
     */
    public static function sourceCodes(): array
    {
        return [
            self::CODE_MISSING_PRICE,
            self::CODE_MISSING_PHOTO,
            self::CODE_MISSING_VENDOR,
            self::CODE_MISSING_ARTICLE,
            self::CODE_PRICE_BELOW_THRESHOLD,
            self::CODE_MISSING_REQUIRED_ATTRIBUTE_VALUE,
        ];
    }

    /**
     * @return list<string>
     */
    public static function mappingCodes(): array
    {
        return [
            self::CODE_MISSING_CATEGORY_MAPPING,
            self::CODE_MISSING_REQUIRED_ATTRIBUTE_MAPPING,
            self::CODE_MISSING_VALUE_MAPPING,
        ];
    }

    /**
     * @return list<string>
     */
    public static function conformanceCodes(): array
    {
        return [
            self::CODE_INVALID_VENDOR,
            self::CODE_INVALID_ARTICLE,
            self::CODE_INVALID_COLOR,
            self::CODE_INVALID_SIZE,
            self::CODE_INVALID_IMAGE_URL,
            self::CODE_DUPLICATED_OR_UNSTABLE_OFFER_ID,
            self::CODE_INVALID_TITLE,
            self::CODE_INVALID_DESCRIPTION,
            self::CODE_INSUFFICIENT_IMAGES,
            self::CODE_INVALID_SIZE_GRID,
            self::CODE_VARIANT_GROUPING_ISSUE,
        ];
    }

    /**
     * @return list<string>
     */
    public static function criticalConformanceCodes(): array
    {
        return [
            self::CODE_INVALID_VENDOR,
            self::CODE_INVALID_ARTICLE,
            self::CODE_INVALID_COLOR,
            self::CODE_INVALID_SIZE,
            self::CODE_DUPLICATED_OR_UNSTABLE_OFFER_ID,
            self::CODE_INVALID_TITLE,
            self::CODE_INVALID_DESCRIPTION,
            self::CODE_INSUFFICIENT_IMAGES,
            self::CODE_INVALID_SIZE_GRID,
            self::CODE_VARIANT_GROUPING_ISSUE,
        ];
    }

    public static function scopeForCode(string $code): string
    {
        if (in_array($code, self::mappingCodes(), true)) {
            return 'mapping';
        }

        if (in_array($code, self::conformanceCodes(), true)) {
            return 'conformance';
        }

        return 'source';
    }
}
