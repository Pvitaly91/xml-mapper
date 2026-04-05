<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PerformanceRun extends Model
{
    use HasFactory;

    public const TYPE_LOAD_BOOTSTRAP = 'load_bootstrap';
    public const TYPE_BENCHMARK = 'benchmark';

    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_WARNING = 'warning';
    public const STATUS_FAILED = 'failed';

    public const BUDGET_WITHIN = 'within_budget';
    public const BUDGET_WARNING = 'warning';
    public const BUDGET_CRITICAL = 'critical';

    protected $fillable = [
        'shop_id',
        'feed_profile_id',
        'user_id',
        'run_type',
        'status',
        'budget_status',
        'environment_label',
        'label',
        'dataset_products',
        'dataset_variants',
        'dataset_images',
        'processed_products',
        'processed_variants',
        'processed_rows',
        'duration_ms',
        'peak_memory_mb',
        'stages',
        'report_counts',
        'summary',
        'warnings',
        'errors',
        'note',
        'meta',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'dataset_products' => 'integer',
            'dataset_variants' => 'integer',
            'dataset_images' => 'integer',
            'processed_products' => 'integer',
            'processed_variants' => 'integer',
            'processed_rows' => 'integer',
            'duration_ms' => 'integer',
            'peak_memory_mb' => 'float',
            'stages' => 'array',
            'report_counts' => 'array',
            'summary' => 'array',
            'warnings' => 'array',
            'errors' => 'array',
            'meta' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
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

    public function stageRuns(): HasMany
    {
        return $this->hasMany(PerformanceRunStage::class);
    }

    /**
     * @return list<string>
     */
    public static function runTypes(): array
    {
        return [
            self::TYPE_LOAD_BOOTSTRAP,
            self::TYPE_BENCHMARK,
        ];
    }
}
