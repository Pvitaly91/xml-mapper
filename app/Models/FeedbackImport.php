<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FeedbackImport extends Model
{
    use HasFactory;

    public const STATUS_IMPORTED = 'imported';

    public const STATUS_DRY_RUN = 'dry_run';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'shop_id',
        'feed_profile_id',
        'feed_generation_id',
        'user_id',
        'format',
        'status',
        'original_filename',
        'source_path',
        'checksum',
        'matched_total',
        'unmatched_total',
        'accepted_total',
        'rejected_total',
        'warnings_total',
        'meta',
        'imported_at',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
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

    public function feedGeneration(): BelongsTo
    {
        return $this->belongsTo(FeedGeneration::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function records(): HasMany
    {
        return $this->hasMany(FeedbackRecord::class);
    }
}
