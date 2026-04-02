<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KastaAttributeValue extends Model
{
    use HasFactory;

    protected $fillable = [
        'kasta_attribute_id',
        'external_id',
        'value',
        'normalized_value',
        'is_active',
        'value_hash',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
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
