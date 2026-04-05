<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MappingRule extends Model
{
    use HasFactory;

    public const TYPE_CATEGORY = 'category';
    public const TYPE_ATTRIBUTE = 'attribute';
    public const TYPE_VALUE = 'value';

    public const MATCH_EXACT = 'exact_normalized';
    public const MATCH_ALIAS = 'alias';
    public const MATCH_CONTAINS = 'contains';
    public const MATCH_STARTS_WITH = 'starts_with';
    public const MATCH_ENDS_WITH = 'ends_with';
    public const MATCH_REGEX = 'regex';
    public const MATCH_CATEGORY_PATH = 'source_category_path';
    public const MATCH_RZ_ID = 'rz_id';

    protected $fillable = [
        'shop_id',
        'feed_profile_id',
        'created_by_user_id',
        'rule_type',
        'match_type',
        'source_pattern',
        'source_normalized',
        'source_attribute_code',
        'source_category_path',
        'vendor_scope',
        'brand_scope',
        'target_reference',
        'target_label',
        'target_payload',
        'explanation',
        'evidence',
        'priority',
        'is_auto_apply_safe',
        'is_active',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'target_payload' => 'array',
            'evidence' => 'array',
            'meta' => 'array',
            'is_auto_apply_safe' => 'boolean',
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
