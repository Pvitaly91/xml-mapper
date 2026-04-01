<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class FeedProfile extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';

    protected $attributes = [
        'status' => self::STATUS_DRAFT,
        'currency' => 'UAH',
        'language' => 'uk',
        'include_unavailable' => false,
        'auto_sync' => true,
        'auto_build' => true,
        'build_interval_minutes' => 60,
    ];

    protected $fillable = [
        'shop_id',
        'source_connection_id',
        'user_id',
        'published_generation_id',
        'name',
        'code',
        'public_token',
        'status',
        'currency',
        'language',
        'include_unavailable',
        'auto_sync',
        'auto_build',
        'build_interval_minutes',
        'last_built_at',
        'next_build_at',
        'published_path',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'include_unavailable' => 'boolean',
            'auto_sync' => 'boolean',
            'auto_build' => 'boolean',
            'last_built_at' => 'datetime',
            'next_build_at' => 'datetime',
            'settings' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $profile): void {
            if (blank($profile->public_token)) {
                $profile->public_token = Str::random(40);
            }
        });
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function sourceConnection(): BelongsTo
    {
        return $this->belongsTo(SourceConnection::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function publishedGeneration(): BelongsTo
    {
        return $this->belongsTo(FeedGeneration::class, 'published_generation_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(FeedItem::class);
    }

    public function generations(): HasMany
    {
        return $this->hasMany(FeedGeneration::class);
    }

    public function categoryMappings(): HasMany
    {
        return $this->hasMany(CategoryMapping::class);
    }

    public function attributeMappings(): HasMany
    {
        return $this->hasMany(AttributeMapping::class);
    }

    public function valueMappings(): HasMany
    {
        return $this->hasMany(ValueMapping::class);
    }

    public function validationErrors(): HasMany
    {
        return $this->hasMany(ValidationError::class);
    }
}
