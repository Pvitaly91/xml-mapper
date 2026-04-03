<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OpsSilenceWindow extends Model
{
    use HasFactory;

    protected $fillable = [
        'shop_id',
        'feed_profile_id',
        'user_id',
        'cleared_by_user_id',
        'active_from',
        'active_to',
        'cleared_at',
        'severity_threshold',
        'note',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'active_from' => 'datetime',
            'active_to' => 'datetime',
            'cleared_at' => 'datetime',
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

    public function clearedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cleared_by_user_id');
    }

    public function isActive(): bool
    {
        if ($this->cleared_at !== null) {
            return false;
        }

        $now = now();

        return ($this->active_from === null || $this->active_from->lte($now))
            && ($this->active_to === null || $this->active_to->gte($now));
    }

    public function silencesSeverity(string $severity): bool
    {
        return OpsAlert::severityRank($severity) < OpsAlert::severityRank($this->severity_threshold);
    }
}
