<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SourceImport extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_FETCHED = 'fetched';
    public const STATUS_NORMALIZED = 'normalized';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'shop_id',
        'source_connection_id',
        'status',
        'started_at',
        'finished_at',
        'fetched_at',
        'source_checksum',
        'source_url_snapshot',
        'temp_path',
        'categories_total',
        'offers_total',
        'source_size_bytes',
        'error_message',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'fetched_at' => 'datetime',
            'meta' => 'array',
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

    public function products(): HasMany
    {
        return $this->hasMany(SourceProduct::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(SourceVariant::class);
    }

    public function feedGenerations(): HasMany
    {
        return $this->hasMany(FeedGeneration::class);
    }

    public function syncLogs(): HasMany
    {
        return $this->hasMany(SyncLog::class);
    }
}
