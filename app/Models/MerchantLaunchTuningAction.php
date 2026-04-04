<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MerchantLaunchTuningAction extends Model
{
    use HasFactory;

    public const TYPE_PUBLISH_GUARD = 'publish_guard';

    public const TYPE_EXCLUDED_CATEGORY = 'excluded_category';

    public const TYPE_EXCLUDED_VENDOR = 'excluded_vendor';

    public const TYPE_MINIMUM_IMAGE_COUNT = 'minimum_image_count';

    public const TYPE_MINIMUM_PRICE = 'minimum_price';

    public const TYPE_FORCED_ATTRIBUTE_OVERRIDE = 'forced_attribute_override';

    public const TYPE_FORCED_VALUE_OVERRIDE = 'forced_value_override';

    public const MODE_NORMAL = 'normal';

    public const MODE_EMERGENCY = 'emergency';

    protected $fillable = [
        'merchant_launch_id',
        'user_id',
        'type',
        'mode',
        'reason',
        'summary',
        'applied_at',
    ];

    protected function casts(): array
    {
        return [
            'summary' => 'array',
            'applied_at' => 'datetime',
        ];
    }

    public function launch(): BelongsTo
    {
        return $this->belongsTo(MerchantLaunch::class, 'merchant_launch_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return list<string>
     */
    public static function types(): array
    {
        return [
            self::TYPE_PUBLISH_GUARD,
            self::TYPE_EXCLUDED_CATEGORY,
            self::TYPE_EXCLUDED_VENDOR,
            self::TYPE_MINIMUM_IMAGE_COUNT,
            self::TYPE_MINIMUM_PRICE,
            self::TYPE_FORCED_ATTRIBUTE_OVERRIDE,
            self::TYPE_FORCED_VALUE_OVERRIDE,
        ];
    }

    /**
     * @return list<string>
     */
    public static function modes(): array
    {
        return [
            self::MODE_NORMAL,
            self::MODE_EMERGENCY,
        ];
    }
}
