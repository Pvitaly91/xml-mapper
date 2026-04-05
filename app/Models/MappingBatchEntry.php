<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MappingBatchEntry extends Model
{
    use HasFactory;

    public const STATUS_PLANNED = 'planned';
    public const STATUS_DRY_RUN = 'dry_run';
    public const STATUS_CREATED = 'created';
    public const STATUS_UPDATED = 'updated';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_CONFLICTED = 'conflicted';
    public const STATUS_ROLLED_BACK = 'rolled_back';

    protected $fillable = [
        'mapping_batch_id',
        'shop_id',
        'feed_profile_id',
        'mapping_type',
        'source_key',
        'target_key',
        'status',
        'is_manual_conflict',
        'model_type',
        'model_id',
        'before_state',
        'after_state',
        'suggestion',
        'warning',
    ];

    protected function casts(): array
    {
        return [
            'is_manual_conflict' => 'boolean',
            'before_state' => 'array',
            'after_state' => 'array',
            'suggestion' => 'array',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(MappingBatch::class, 'mapping_batch_id');
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function feedProfile(): BelongsTo
    {
        return $this->belongsTo(FeedProfile::class);
    }
}
