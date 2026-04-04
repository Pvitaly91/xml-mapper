<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class GovernanceAudit extends Model
{
    use HasFactory;

    public const CATEGORY_ACCESS = 'access';
    public const CATEGORY_APPROVAL = 'approval';
    public const CATEGORY_SECRET = 'secret';
    public const CATEGORY_DANGEROUS_ACTION = 'dangerous_action';
    public const CATEGORY_COMPLIANCE = 'compliance';
    public const CATEGORY_AUTH = 'auth';

    protected $fillable = [
        'shop_id',
        'user_id',
        'approval_request_id',
        'category',
        'event_type',
        'severity',
        'summary',
        'target_type',
        'target_id',
        'target_label',
        'correlation_id',
        'context',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function approvalRequest(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class);
    }

    public function target(): MorphTo
    {
        return $this->morphTo();
    }
}
