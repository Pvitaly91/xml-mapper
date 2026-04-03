<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OpsRun extends Model
{
    use HasFactory;

    public const TYPE_PREFLIGHT = 'preflight';

    public const TYPE_BACKUP_DB = 'backup_db';

    public const TYPE_BACKUP_FILES = 'backup_files';

    public const TYPE_PRUNE = 'prune';

    public const TYPE_BENCHMARK = 'benchmark';

    public const TYPE_DEPLOY = 'deploy';

    public const TYPE_ROLLBACK = 'rollback';

    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_WARNING = 'warning';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'shop_id',
        'feed_profile_id',
        'user_id',
        'type',
        'status',
        'artifact_path',
        'artifact_size_bytes',
        'summary',
        'error_message',
        'started_at',
        'finished_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'artifact_size_bytes' => 'integer',
            'summary' => 'array',
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
}
