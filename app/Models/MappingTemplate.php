<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MappingTemplate extends Model
{
    use HasFactory;

    public const SCOPE_GLOBAL = 'global';
    public const SCOPE_SHOP = 'shop';
    public const SCOPE_FEED_PROFILE = 'feed_profile';

    protected $fillable = [
        'shop_id',
        'feed_profile_id',
        'created_by_user_id',
        'name',
        'scope',
        'template_type',
        'version',
        'fingerprint',
        'is_active',
        'payload',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'meta' => 'array',
            'is_active' => 'boolean',
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

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
