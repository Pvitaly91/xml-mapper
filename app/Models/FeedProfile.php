<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class FeedProfile extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

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

    public function latestGeneration(): HasOne
    {
        return $this->hasOne(FeedGeneration::class)->latestOfMany();
    }

    /**
     * @return array<string, mixed>
     */
    public function exportSettings(): array
    {
        return array_merge([
            'publish_guard_enabled' => false,
            'minimum_ready_items' => 0,
            'maximum_invalid_ratio' => 1,
            'block_publish_on_critical_conformance' => true,
            'minimum_pictures' => 1,
        ], $this->settings ?? []);
    }

    public function publishGuardEnabled(): bool
    {
        return (bool) ($this->exportSettings()['publish_guard_enabled'] ?? false);
    }

    public function minimumReadyItems(): int
    {
        return max(0, (int) ($this->exportSettings()['minimum_ready_items'] ?? 0));
    }

    public function maximumInvalidRatio(): float
    {
        return min(1, max(0, (float) ($this->exportSettings()['maximum_invalid_ratio'] ?? 1)));
    }

    public function blockPublishOnCriticalConformance(): bool
    {
        return (bool) ($this->exportSettings()['block_publish_on_critical_conformance'] ?? true);
    }

    public function minimumPictures(): int
    {
        return max(1, (int) ($this->exportSettings()['minimum_pictures'] ?? 1));
    }
}
