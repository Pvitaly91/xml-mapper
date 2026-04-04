<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ApprovalRequest extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_EXECUTED = 'executed';

    public const CLASS_STANDARD = 'standard';
    public const CLASS_SENSITIVE = 'sensitive';
    public const CLASS_HIGH_RISK = 'high_risk';

    protected $fillable = [
        'shop_id',
        'requested_by_user_id',
        'approved_by_user_id',
        'action',
        'classification',
        'environment_class',
        'environment_label',
        'status',
        'requires_four_eyes',
        'platform_admin_only',
        'target_type',
        'target_id',
        'target_label',
        'correlation_id',
        'reason',
        'note',
        'payload_summary',
        'payload',
        'result_summary',
        'requested_at',
        'expires_at',
        'approved_at',
        'rejected_at',
        'executed_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'requires_four_eyes' => 'boolean',
            'platform_admin_only' => 'boolean',
            'payload_summary' => 'array',
            'payload' => 'encrypted:array',
            'result_summary' => 'array',
            'requested_at' => 'datetime',
            'expires_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'executed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function target(): MorphTo
    {
        return $this->morphTo();
    }

    public function audits(): HasMany
    {
        return $this->hasMany(GovernanceAudit::class);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isExpired(): bool
    {
        return $this->expires_at?->isPast() ?? false;
    }

    /**
     * @return list<string>
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_APPROVED,
            self::STATUS_REJECTED,
            self::STATUS_EXPIRED,
            self::STATUS_CANCELLED,
            self::STATUS_EXECUTED,
        ];
    }
}
