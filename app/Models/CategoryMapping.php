<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CategoryMapping extends Model
{
    use HasFactory;

    public const STRATEGY_MANUAL = 'manual';

    public const STRATEGY_RZ_ID = 'rz_id';

    protected $fillable = [
        'shop_id',
        'source_connection_id',
        'feed_profile_id',
        'source_category_id',
        'kasta_category_id',
        'rz_id',
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

    public function kastaCategory(): BelongsTo
    {
        return $this->belongsTo(KastaCategory::class);
    }
}
