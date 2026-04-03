<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Arr;

class SourceConnection extends Model
{
    use HasFactory;

    public const DRIVER_PROM_YML = 'prom_yml';

    public const DRIVER_PROM_API = 'prom_api';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAUSED = 'paused';

    public const CHECK_STATUS_OK = 'ok';

    public const CHECK_STATUS_AUTH_FAILED = 'auth_failed';

    public const CHECK_STATUS_RATE_LIMITED = 'rate_limited';

    public const CHECK_STATUS_NETWORK_ERROR = 'network_error';

    public const CHECK_STATUS_INVALID_PAYLOAD = 'invalid_payload';

    public const CHECK_STATUS_REMOTE_ERROR = 'remote_error';

    public const CHECK_STATUS_CONFIG_ERROR = 'config_error';

    public const CHECK_STATUS_FAILED = 'failed';

    protected $fillable = [
        'shop_id',
        'name',
        'code',
        'driver',
        'status',
        'source_url',
        'credentials',
        'api_base_url',
        'api_token',
        'api_version',
        'options',
        'last_connection_check_at',
        'last_connection_check_status',
        'last_connection_check_message',
        'last_sync_status',
        'last_sync_message',
        'last_sync_summary',
        'sync_interval_minutes',
        'last_synced_at',
        'next_sync_at',
    ];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'api_token' => 'encrypted',
            'options' => 'array',
            'last_connection_check_at' => 'datetime',
            'last_sync_summary' => 'array',
            'last_synced_at' => 'datetime',
            'next_sync_at' => 'datetime',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function driverOptions(): array
    {
        return [
            self::DRIVER_PROM_YML => 'Prom YML',
            self::DRIVER_PROM_API => 'Prom API',
        ];
    }

    /**
     * @return list<string>
     */
    public static function supportedDrivers(): array
    {
        return array_keys(self::driverOptions());
    }

    public static function defaultPromApiBaseUrl(): string
    {
        return rtrim((string) config('feed_mediator.prom_api.default_base_url', 'https://my.prom.ua'), '/');
    }

    public static function defaultPromApiVersion(): string
    {
        return trim((string) config('feed_mediator.prom_api.default_version', 'v1'), '/');
    }

    /**
     * @return list<string>
     */
    public static function checkStatuses(): array
    {
        return [
            self::CHECK_STATUS_OK,
            self::CHECK_STATUS_AUTH_FAILED,
            self::CHECK_STATUS_RATE_LIMITED,
            self::CHECK_STATUS_NETWORK_ERROR,
            self::CHECK_STATUS_INVALID_PAYLOAD,
            self::CHECK_STATUS_REMOTE_ERROR,
            self::CHECK_STATUS_CONFIG_ERROR,
            self::CHECK_STATUS_FAILED,
        ];
    }

    public function usesPromApi(): bool
    {
        return $this->driver === self::DRIVER_PROM_API;
    }

    public function usesPromYml(): bool
    {
        return $this->driver === self::DRIVER_PROM_YML;
    }

    public function resolvedApiBaseUrl(): string
    {
        return rtrim($this->api_base_url ?: self::defaultPromApiBaseUrl(), '/');
    }

    public function resolvedApiVersion(): string
    {
        return trim($this->api_version ?: self::defaultPromApiVersion(), '/');
    }

    public function apiEndpointBase(): string
    {
        return $this->resolvedApiBaseUrl().'/api/'.$this->resolvedApiVersion();
    }

    public function maskedApiToken(): ?string
    {
        if (blank($this->api_token)) {
            return null;
        }

        $token = (string) $this->api_token;

        if (mb_strlen($token) <= 8) {
            return str_repeat('*', mb_strlen($token));
        }

        return mb_substr($token, 0, 4).str_repeat('*', max(4, mb_strlen($token) - 8)).mb_substr($token, -4);
    }

    /**
     * @return array<string, mixed>
     */
    public function promotionMeta(): array
    {
        return (array) data_get($this->options ?? [], 'promotion_meta', []);
    }

    /**
     * @param  array<string, mixed>  $secretPolicy
     */
    public function promotionSecretStateFor(array $secretPolicy): string
    {
        $requiredFields = array_values(array_filter((array) ($secretPolicy['required_fields'] ?? [])));

        if ($requiredFields === []) {
            return 'not_required';
        }

        $hasSecrets = $this->usesPromApi()
            ? filled($this->api_token)
            : ! empty($this->credentials);

        if (! $hasSecrets) {
            return 'missing';
        }

        $appliedAt = data_get($this->promotionMeta(), 'applied_at');
        $validatedAt = data_get($this->promotionMeta(), 'validated_at');

        if ($this->last_connection_check_status === self::CHECK_STATUS_OK) {
            if (
                is_string($appliedAt)
                && is_string($validatedAt)
                && strtotime($validatedAt) !== false
                && strtotime($appliedAt) !== false
                && strtotime($validatedAt) < strtotime($appliedAt)
            ) {
                return 'not_validated';
            }

            return 'validated';
        }

        $storedState = (string) ($this->promotionMeta()['secret_state'] ?? '');

        return in_array($storedState, ['validated', 'not_validated'], true)
            ? $storedState
            : 'not_validated';
    }

    public function promotionSecretState(): string
    {
        $requiredFields = $this->usesPromApi()
            ? ['api_token']
            : (! empty($this->credentials) ? ['credentials'] : []);

        return $this->promotionSecretStateFor(['required_fields' => $requiredFields]);
    }

    /**
     * @param  array<string, mixed>  $secretPolicy
     */
    public function promotionSecretRebindRequiredFor(array $secretPolicy): bool
    {
        return $this->promotionSecretStateFor($secretPolicy) !== 'validated'
            && $this->promotionSecretStateFor($secretPolicy) !== 'not_required';
    }

    public function promotionSecretRebindRequired(): bool
    {
        return $this->promotionSecretState() !== 'validated'
            && $this->promotionSecretState() !== 'not_required';
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function withPromotionMeta(array $attributes): static
    {
        $options = (array) ($this->options ?? []);
        $options['promotion_meta'] = array_merge($this->promotionMeta(), $attributes);
        $this->options = $options;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function savePromotionMeta(array $attributes): void
    {
        $this->withPromotionMeta($attributes)->save();
    }

    /**
     * @param  array<string, mixed>  $secretPolicy
     */
    public function applyPromotionSecretPolicy(array $secretPolicy, ?string $snapshotChecksum = null): static
    {
        $state = $this->promotionSecretStateFor($secretPolicy);

        return $this->withPromotionMeta([
            'secret_policy' => Arr::except($secretPolicy, ['secret_present', 'secret_validated']),
            'secret_state' => $state,
            'secret_rebind_required' => $state !== 'validated' && $state !== 'not_required',
            'source_snapshot_checksum' => $snapshotChecksum,
            'applied_at' => now()->toIso8601String(),
            'validated_at' => $state === 'validated'
                ? now()->toIso8601String()
                : data_get($this->promotionMeta(), 'validated_at'),
        ]);
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function imports(): HasMany
    {
        return $this->hasMany(SourceImport::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(SourceCategory::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(SourceProduct::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(SourceVariant::class);
    }

    public function sourceAttributes(): HasMany
    {
        return $this->hasMany(SourceAttribute::class);
    }

    public function feedProfiles(): HasMany
    {
        return $this->hasMany(FeedProfile::class);
    }

    public function latestImport(): HasOne
    {
        return $this->hasOne(SourceImport::class)->latestOfMany();
    }
}
