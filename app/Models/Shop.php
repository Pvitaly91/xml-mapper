<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shop extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'currency',
        'locale',
        'timezone',
        'is_active',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'settings' => 'array',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(ShopMembership::class);
    }

    public function sourceConnections(): HasMany
    {
        return $this->hasMany(SourceConnection::class);
    }

    public function feedProfiles(): HasMany
    {
        return $this->hasMany(FeedProfile::class);
    }

    public function opsRuns(): HasMany
    {
        return $this->hasMany(OpsRun::class);
    }

    public function pilotRuns(): HasMany
    {
        return $this->hasMany(PilotRun::class);
    }

    public function hypercareWindows(): HasMany
    {
        return $this->hasMany(FeedHypercareWindow::class);
    }

    public function opsAlerts(): HasMany
    {
        return $this->hasMany(OpsAlert::class);
    }

    public function opsPolicyResults(): HasMany
    {
        return $this->hasMany(OpsPolicyResult::class);
    }

    public function silenceWindows(): HasMany
    {
        return $this->hasMany(OpsSilenceWindow::class);
    }

    public function promotionSnapshots(): HasMany
    {
        return $this->hasMany(PromotionSnapshot::class);
    }

    public function promotionRuns(): HasMany
    {
        return $this->hasMany(PromotionRun::class);
    }
}
