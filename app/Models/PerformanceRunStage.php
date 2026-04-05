<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PerformanceRunStage extends Model
{
    use HasFactory;

    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'performance_run_id',
        'stage',
        'status',
        'budget_status',
        'processed_products',
        'processed_variants',
        'processed_rows',
        'report_count',
        'duration_ms',
        'peak_memory_mb',
        'warnings',
        'errors',
        'meta',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'processed_products' => 'integer',
            'processed_variants' => 'integer',
            'processed_rows' => 'integer',
            'report_count' => 'integer',
            'duration_ms' => 'integer',
            'peak_memory_mb' => 'float',
            'warnings' => 'array',
            'errors' => 'array',
            'meta' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function performanceRun(): BelongsTo
    {
        return $this->belongsTo(PerformanceRun::class);
    }
}
