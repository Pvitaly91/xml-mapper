<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ValueMapping extends Model
{
    use HasFactory;

    public const STRATEGY_MANUAL = 'manual';
    public const STRATEGY_NORMALIZED_EXACT = 'normalized_exact';

    protected $fillable = [
        'shop_id',
        'feed_profile_id',
        'attribute_mapping_id',
        'source_attribute_value_id',
        'kasta_attribute_value_id',
        'source_raw_value',
        'normalized_source_value',
        'target_value',
        'mapping_strategy',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
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

    public function attributeMapping(): BelongsTo
    {
        return $this->belongsTo(AttributeMapping::class);
    }

    public function sourceAttributeValue(): BelongsTo
    {
        return $this->belongsTo(SourceAttributeValue::class);
    }

    public function kastaAttributeValue(): BelongsTo
    {
        return $this->belongsTo(KastaAttributeValue::class);
    }
}
