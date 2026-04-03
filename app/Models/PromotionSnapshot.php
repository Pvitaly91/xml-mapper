<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PromotionSnapshot extends Model
{
    use HasFactory;

    public const SOURCE_GENERATED = 'generated';

    public const SOURCE_IMPORTED = 'imported';

    protected $fillable = [
        'shop_id',
        'feed_profile_id',
        'user_id',
        'environment_class',
        'environment_label',
        'source_type',
        'name',
        'checksum',
        'mapping_fingerprint',
        'settings_fingerprint',
        'source_connection_fingerprint',
        'payload',
        'summary',
        'generated_at',
        'imported_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'summary' => 'array',
            'generated_at' => 'datetime',
            'imported_at' => 'datetime',
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sourceRuns(): HasMany
    {
        return $this->hasMany(PromotionRun::class, 'source_snapshot_id');
    }

    public function targetRuns(): HasMany
    {
        return $this->hasMany(PromotionRun::class, 'target_snapshot_id');
    }

    public function resultRuns(): HasMany
    {
        return $this->hasMany(PromotionRun::class, 'result_snapshot_id');
    }

    public function isImported(): bool
    {
        return $this->source_type === self::SOURCE_IMPORTED;
    }
}
