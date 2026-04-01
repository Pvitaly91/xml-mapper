<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SourceConnection extends Model
{
    use HasFactory;

    public const DRIVER_PROM_YML = 'prom_yml';

    public const STATUS_ACTIVE = 'active';

    protected $fillable = [
        'shop_id',
        'name',
        'code',
        'driver',
        'status',
        'source_url',
        'credentials',
        'options',
        'sync_interval_minutes',
        'last_synced_at',
        'next_sync_at',
    ];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'options' => 'array',
            'last_synced_at' => 'datetime',
            'next_sync_at' => 'datetime',
        ];
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function imports(): HasMany
    {
        return $this->hasMany(SourceImport::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(SourceCategory::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(SourceProduct::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(SourceVariant::class);
    }

    public function sourceAttributes(): HasMany
    {
        return $this->hasMany(SourceAttribute::class);
    }

    public function feedProfiles(): HasMany
    {
        return $this->hasMany(FeedProfile::class);
    }
}
