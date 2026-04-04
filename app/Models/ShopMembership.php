<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopMembership extends Model
{
    use HasFactory;

    public const ROLE_PLATFORM_ADMIN = 'platform_admin';
    public const ROLE_SHOP_ADMIN = 'shop_admin';
    public const ROLE_OPERATOR = 'operator';
    public const ROLE_REVIEWER = 'reviewer';
    public const ROLE_OBSERVER = 'observer';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INVITED = 'invited';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_REVOKED = 'revoked';

    protected $fillable = [
        'user_id',
        'shop_id',
        'role',
        'status',
        'invited_by_user_id',
        'updated_by_user_id',
        'note',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isPlatformAdmin(): bool
    {
        return $this->role === self::ROLE_PLATFORM_ADMIN;
    }

    /**
     * @return list<string>
     */
    public static function roles(): array
    {
        return [
            self::ROLE_PLATFORM_ADMIN,
            self::ROLE_SHOP_ADMIN,
            self::ROLE_OPERATOR,
            self::ROLE_REVIEWER,
            self::ROLE_OBSERVER,
        ];
    }

    /**
     * @return list<string>
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_ACTIVE,
            self::STATUS_INVITED,
            self::STATUS_SUSPENDED,
            self::STATUS_REVOKED,
        ];
    }
}
