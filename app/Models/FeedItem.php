<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FeedItem extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_INVALID_SOURCE = 'invalid_source';

    public const STATUS_INVALID_MAPPING = 'invalid_mapping';

    public const STATUS_INVALID_CONFORMANCE = 'invalid_conformance';

    public const STATUS_READY = 'ready';

    public const STATUS_EXCLUDED = 'excluded';

    public const STATUS_PUBLISHED = 'published';

    protected $attributes = [
        'status' => self::STATUS_PENDING,
        'is_enabled' => true,
        'is_manual_override' => false,
    ];

    protected $fillable = [
        'shop_id',
        'feed_profile_id',
        'source_product_id',
        'source_variant_id',
        'last_built_generation_id',
        'status',
        'is_enabled',
        'is_manual_override',
        'excluded_reason',
        'last_validation_hash',
        'last_exported_at',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'is_manual_override' => 'boolean',
            'last_exported_at' => 'datetime',
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

    public function lastBuiltGeneration(): BelongsTo
    {
        return $this->belongsTo(FeedGeneration::class, 'last_built_generation_id');
    }

    public function validationErrors(): HasMany
    {
        return $this->hasMany(ValidationError::class);
    }

    public function activeValidationErrors(): HasMany
    {
        return $this->validationErrors()->where('is_active', true);
    }

    public function feedbackRecords(): HasMany
    {
        return $this->hasMany(FeedbackRecord::class);
    }

    public function mappingExceptions(): HasMany
    {
        return $this->hasMany(FeedItemMappingException::class);
    }

    /**
     * @return list<string>
     */
    public static function invalidStatuses(): array
    {
        return [
            self::STATUS_INVALID_SOURCE,
            self::STATUS_INVALID_MAPPING,
            self::STATUS_INVALID_CONFORMANCE,
        ];
    }

    /**
     * @return list<string>
     */
    public static function exportableStatuses(): array
    {
        return [
            self::STATUS_READY,
            self::STATUS_PUBLISHED,
        ];
    }

    /**
     * @return list<string>
     */
    public static function allStatuses(): array
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_INVALID_SOURCE,
            self::STATUS_INVALID_MAPPING,
            self::STATUS_INVALID_CONFORMANCE,
            self::STATUS_READY,
            self::STATUS_EXCLUDED,
            self::STATUS_PUBLISHED,
        ];
    }
}
