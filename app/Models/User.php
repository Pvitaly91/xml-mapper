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
    public const STATE_INVITED = 'invited';
    public const STATE_ACTIVE = 'active';
    public const STATE_SUSPENDED = 'suspended';
    public const STATE_LOCKED = 'locked';
    public const STATE_PASSWORD_RESET_REQUIRED = 'password_reset_required';

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
        'account_state',
        'failed_login_attempts',
        'locked_until',
        'last_login_at',
        'last_login_ip',
        'password_changed_at',
        'password_reset_required_at',
        'invite_accepted_at',
        'mfa_secret',
        'mfa_pending_secret',
        'mfa_recovery_codes',
        'mfa_enabled_at',
        'mfa_last_verified_at',
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
        'mfa_secret',
        'mfa_pending_secret',
        'mfa_recovery_codes',
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
            'failed_login_attempts' => 'integer',
            'locked_until' => 'datetime',
            'last_login_at' => 'datetime',
            'password_changed_at' => 'datetime',
            'password_reset_required_at' => 'datetime',
            'invite_accepted_at' => 'datetime',
            'mfa_secret' => 'encrypted',
            'mfa_pending_secret' => 'encrypted',
            'mfa_recovery_codes' => 'encrypted:array',
            'mfa_enabled_at' => 'datetime',
            'mfa_last_verified_at' => 'datetime',
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

    public function adminInvites(): HasMany
    {
        return $this->hasMany(AdminInvite::class);
    }

    public function adminSessions(): HasMany
    {
        return $this->hasMany(AdminSession::class);
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

    public function performanceRuns(): HasMany
    {
        return $this->hasMany(PerformanceRun::class);
    }

    public function isAdmin(): bool
    {
        if (! $this->canUseAdminAuthentication()) {
            return false;
        }

        if ($this->memberships()->exists()) {
            return $this->memberships()
                ->where('status', ShopMembership::STATUS_ACTIVE)
                ->exists();
        }

        return $this->role === self::ROLE_ADMIN;
    }

    public function canUseAdminAuthentication(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->account_state === self::STATE_INVITED || $this->account_state === self::STATE_SUSPENDED) {
            return false;
        }

        return ! $this->isLocked();
    }

    public function isLocked(): bool
    {
        return $this->account_state === self::STATE_LOCKED
            && ($this->locked_until?->isFuture() ?? true);
    }

    public function requiresPasswordReset(): bool
    {
        return $this->account_state === self::STATE_PASSWORD_RESET_REQUIRED
            || $this->password_reset_required_at !== null;
    }

    public function hasMfaEnabled(): bool
    {
        return filled($this->mfa_secret) && $this->mfa_enabled_at !== null;
    }

    public function hasPendingMfaSetup(): bool
    {
        return filled($this->mfa_pending_secret) && ! $this->hasMfaEnabled();
    }

    public function mfaStatus(): string
    {
        return match (true) {
            $this->hasMfaEnabled() => 'enabled',
            $this->hasPendingMfaSetup() => 'pending_setup',
            filled($this->mfa_recovery_codes) => 'recovery_only',
            default => 'not_enabled',
        };
    }

    /**
     * @return list<string>
     */
    public static function accountStates(): array
    {
        return [
            self::STATE_INVITED,
            self::STATE_ACTIVE,
            self::STATE_SUSPENDED,
            self::STATE_LOCKED,
            self::STATE_PASSWORD_RESET_REQUIRED,
        ];
    }
}
