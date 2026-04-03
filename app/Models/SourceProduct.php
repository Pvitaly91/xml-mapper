<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SourceProduct extends Model
{
    use HasFactory;

    protected $fillable = [
        'shop_id',
        'source_connection_id',
        'source_import_id',
        'source_category_id',
        'group_key',
        'external_group_id',
        'name',
        'vendor',
        'article',
        'brand',
        'description',
        'primary_image_url',
        'images_json',
        'attributes_snapshot',
        'raw_payload',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'images_json' => 'array',
            'attributes_snapshot' => 'array',
            'raw_payload' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function sourceConnection(): BelongsTo
    {
        return $this->belongsTo(SourceConnection::class);
    }

    public function sourceImport(): BelongsTo
    {
        return $this->belongsTo(SourceImport::class);
    }

    public function sourceCategory(): BelongsTo
    {
        return $this->belongsTo(SourceCategory::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(SourceVariant::class);
    }

    public function feedItems(): HasMany
    {
        return $this->hasMany(FeedItem::class);
    }

    public function validationErrors(): HasMany
    {
        return $this->hasMany(ValidationError::class);
    }

    public function feedbackRecords(): HasMany
    {
        return $this->hasMany(FeedbackRecord::class);
    }
}
