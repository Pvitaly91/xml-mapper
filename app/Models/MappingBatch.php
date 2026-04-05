<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MappingBatch extends Model
{
    use HasFactory;

    public const TYPE_SUGGESTION_APPLY = 'suggestion_apply';
    public const TYPE_TEMPLATE_APPLY = 'template_apply';

    public const STATUS_PLANNED = 'planned';
    public const STATUS_DRY_RUN = 'dry_run';
    public const STATUS_PENDING_APPROVAL = 'pending_approval';
    public const STATUS_APPLIED = 'applied';
    public const STATUS_ROLLED_BACK = 'rolled_back';
    public const STATUS_FAILED = 'failed';
    public const STATUS_BLOCKED = 'blocked';

    protected $fillable = [
        'shop_id',
        'feed_profile_id',
        'approval_request_id',
        'requested_by_user_id',
        'executed_by_user_id',
        'rolled_back_by_user_id',
        'batch_type',
        'mapping_type',
        'status',
        'strategy',
        'risk_level',
        'correlation_id',
        'threshold',
        'scope',
        'reason',
        'note',
        'summary',
        'warnings',
        'started_at',
        'finished_at',
        'rolled_back_at',
    ];

    protected function casts(): array
    {
        return [
            'threshold' => 'float',
            'scope' => 'array',
            'summary' => 'array',
            'warnings' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'rolled_back_at' => 'datetime',
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

    public function approvalRequest(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function executedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executed_by_user_id');
    }

    public function rolledBackBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rolled_back_by_user_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(MappingBatchEntry::class);
    }
}
