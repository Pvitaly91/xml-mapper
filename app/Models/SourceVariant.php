<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SourceVariant extends Model
{
    use HasFactory;

    protected $fillable = [
        'shop_id',
        'source_connection_id',
        'source_import_id',
        'source_product_id',
        'external_offer_id',
        'external_sku',
        'stable_offer_id',
        'offer_identity_key',
        'export_key_hash',
        'published_export_key_hash',
        'title',
        'price',
        'old_price',
        'currency',
        'quantity',
        'is_available',
        'color',
        'size',
        'barcode',
        'images_json',
        'attributes_snapshot',
        'raw_payload',
        'last_seen_at',
        'first_published_at',
        'last_published_at',
        'is_enabled',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'old_price' => 'decimal:2',
            'is_available' => 'boolean',
            'images_json' => 'array',
            'attributes_snapshot' => 'array',
            'raw_payload' => 'array',
            'last_seen_at' => 'datetime',
            'first_published_at' => 'datetime',
            'last_published_at' => 'datetime',
            'is_enabled' => 'boolean',
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

    public function product(): BelongsTo
    {
        return $this->belongsTo(SourceProduct::class, 'source_product_id');
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
