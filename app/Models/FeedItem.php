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
    public const STATUS_READY = 'ready';
    public const STATUS_INVALID = 'invalid';
    public const STATUS_EXCLUDED = 'excluded';

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
}
