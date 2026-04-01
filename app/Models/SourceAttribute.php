<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SourceAttribute extends Model
{
    use HasFactory;

    protected $fillable = [
        'shop_id',
        'source_connection_id',
        'name',
        'code',
        'data_type',
        'usage_scope',
        'is_variant_axis',
    ];

    protected function casts(): array
    {
        return [
            'is_variant_axis' => 'boolean',
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

    public function values(): HasMany
    {
        return $this->hasMany(SourceAttributeValue::class);
    }

    public function attributeMappings(): HasMany
    {
        return $this->hasMany(AttributeMapping::class);
    }
}
