<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SourceAttributeValue extends Model
{
    use HasFactory;

    protected $fillable = [
        'shop_id',
        'source_connection_id',
        'source_attribute_id',
        'raw_value',
        'normalized_value',
        'value_hash',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function sourceConnection(): BelongsTo
    {
        return $this->belongsTo(SourceConnection::class);
    }

    public function sourceAttribute(): BelongsTo
    {
        return $this->belongsTo(SourceAttribute::class);
    }

    public function valueMappings(): HasMany
    {
        return $this->hasMany(ValueMapping::class);
    }
}
