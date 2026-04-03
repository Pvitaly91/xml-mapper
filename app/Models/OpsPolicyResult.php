<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OpsPolicyResult extends Model
{
    use HasFactory;

    public const STATUS_OK = 'ok';

    public const STATUS_WARNING = 'warning';

    public const STATUS_CRITICAL = 'critical';

    protected $fillable = [
        'shop_id',
        'feed_profile_id',
        'feed_generation_id',
        'feed_hypercare_window_id',
        'policy_key',
        'status',
        'summary',
        'due_at',
        'evaluated_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'due_at' => 'datetime',
            'evaluated_at' => 'datetime',
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

    public function hypercareWindow(): BelongsTo
    {
        return $this->belongsTo(FeedHypercareWindow::class, 'feed_hypercare_window_id');
    }
}
