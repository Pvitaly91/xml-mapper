<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KastaAttribute extends Model
{
    use HasFactory;

    protected $fillable = [
        'kasta_category_id',
        'external_id',
        'name',
        'code',
        'data_type',
        'is_required',
        'allows_custom_value',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'allows_custom_value' => 'boolean',
        ];
    }

    public function kastaCategory(): BelongsTo
    {
        return $this->belongsTo(KastaCategory::class);
    }

    public function values(): HasMany
    {
        return $this->hasMany(KastaAttributeValue::class);
    }

    public function attributeMappings(): HasMany
    {
        return $this->hasMany(AttributeMapping::class);
    }
}
