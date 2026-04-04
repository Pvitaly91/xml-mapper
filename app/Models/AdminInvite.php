<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminInvite extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REVOKED = 'revoked';
    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'user_id',
        'shop_membership_id',
        'requested_by_user_id',
        'status',
        'email',
        'token_hash',
        'token_ciphertext',
        'note',
        'sent_at',
        'expires_at',
        'accepted_at',
        'revoked_at',
        'last_resent_at',
    ];

    protected function casts(): array
    {
        return [
            'token_ciphertext' => 'encrypted',
            'sent_at' => 'datetime',
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_resent_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(ShopMembership::class, 'shop_membership_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isExpired(): bool
    {
        return $this->expires_at?->isPast() ?? false;
    }
}
