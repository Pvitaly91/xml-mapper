<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PilotRunEvent extends Model
{
    use HasFactory;

    public const TYPE_TRANSITION = 'transition';

    public const TYPE_NOTE = 'note';

    public const TYPE_INCIDENT = 'incident';

    public const TYPE_OVERRIDE = 'override';

    public const TYPE_REPORT = 'report';

    public const STATUS_INFO = 'info';

    public const STATUS_OK = 'ok';

    public const STATUS_WARNING = 'warning';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'pilot_run_id',
        'user_id',
        'event_type',
        'step',
        'from_state',
        'to_state',
        'status',
        'title',
        'message',
        'blocking_reason',
        'meta',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function pilotRun(): BelongsTo
    {
        return $this->belongsTo(PilotRun::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
