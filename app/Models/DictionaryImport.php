<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DictionaryImport extends Model
{
    use HasFactory;

    public const TYPE_KASTA_CATEGORIES = 'kasta_categories';
    public const TYPE_KASTA_ATTRIBUTES = 'kasta_attributes';
    public const TYPE_KASTA_ATTRIBUTE_VALUES = 'kasta_attribute_values';
    public const TYPE_SIZE_GRIDS = 'size_grids';

    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'type',
        'source_path',
        'original_filename',
        'source_format',
        'checksum',
        'rows_total',
        'created_count',
        'updated_count',
        'skipped_count',
        'deactivated_count',
        'dry_run',
        'status',
        'started_at',
        'finished_at',
        'error_summary',
        'metadata',
        'initiated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'dry_run' => 'boolean',
            'metadata' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by_user_id');
    }
}
