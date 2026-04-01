<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttributeMapping extends Model
{
    use HasFactory;

    protected $fillable = [
        'shop_id',
        'source_connection_id',
        'feed_profile_id',
        'source_category_id',
        'source_attribute_id',
        'kasta_category_id',
        'kasta_attribute_id',
        'mapping_strategy',
        'is_required',
        'default_value',
        'use_variant_value',
    ];

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'use_variant_value' => 'boolean',
        ];
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function sourceConnection(): BelongsTo
    {
        return $this->belongsTo(SourceConnection::class);
    }

    public function feedProfile(): BelongsTo
    {
        return $this->belongsTo(FeedProfile::class);
    }

    public function sourceCategory(): BelongsTo
    {
        return $this->belongsTo(SourceCategory::class);
    }

    public function sourceAttribute(): BelongsTo
    {
        return $this->belongsTo(SourceAttribute::class);
    }

    public function kastaCategory(): BelongsTo
    {
        return $this->belongsTo(KastaCategory::class);
    }

    public function kastaAttribute(): BelongsTo
    {
        return $this->belongsTo(KastaAttribute::class);
    }

    public function valueMappings(): HasMany
    {
        return $this->hasMany(ValueMapping::class);
    }
}
