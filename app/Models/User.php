<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const ROLE_ADMIN = 'admin';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'shop_id',
        'name',
        'email',
        'password',
        'role',
        'is_active',
        'settings',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'settings' => 'array',
        ];
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function feedProfiles(): HasMany
    {
        return $this->hasMany(FeedProfile::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(ShopMembership::class);
    }

    public function activeMemberships(): HasMany
    {
        return $this->memberships()->where('status', ShopMembership::STATUS_ACTIVE);
    }

    public function governedApprovalRequests(): HasMany
    {
        return $this->hasMany(ApprovalRequest::class, 'requested_by_user_id');
    }

    public function governanceApprovals(): HasMany
    {
        return $this->hasMany(ApprovalRequest::class, 'approved_by_user_id');
    }

    public function governanceAudits(): HasMany
    {
        return $this->hasMany(GovernanceAudit::class);
    }

    public function initiatedPilotRuns(): HasMany
    {
        return $this->hasMany(PilotRun::class, 'initiated_by_user_id');
    }

    public function ownedPilotRuns(): HasMany
    {
        return $this->hasMany(PilotRun::class, 'owner_user_id');
    }

    public function isAdmin(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->memberships()->exists()) {
            return $this->memberships()
                ->where('status', ShopMembership::STATUS_ACTIVE)
                ->exists();
        }

        return $this->role === self::ROLE_ADMIN;
    }
}
