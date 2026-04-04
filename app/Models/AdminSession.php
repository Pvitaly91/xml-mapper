<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminSession extends Model
{
    protected $table = 'sessions';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'id',
        'user_id',
        'ip_address',
        'user_agent',
        'payload',
        'last_activity',
        'created_at',
        'last_seen_at',
        'device_label',
        'mfa_verified_at',
        'revoked_at',
        'revoked_by_user_id',
        'break_glass_reason',
        'break_glass_started_at',
        'break_glass_expires_at',
        'break_glass_ended_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'mfa_verified_at' => 'datetime',
            'revoked_at' => 'datetime',
            'break_glass_started_at' => 'datetime',
            'break_glass_expires_at' => 'datetime',
            'break_glass_ended_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by_user_id');
    }
}
