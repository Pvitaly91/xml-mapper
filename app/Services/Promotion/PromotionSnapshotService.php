<?php

namespace App\Services\Promotion;

use App\Models\AttributeMapping;
use App\Models\CategoryMapping;
use App\Models\DictionaryImport;
use App\Models\FeedProfile;
use App\Models\KastaAttribute;
use App\Models\PromotionSnapshot;
use App\Models\SourceCategory;
use App\Models\SourceConnection;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

class PromotionSnapshotService
{
    public function __construct(
        private readonly PromotionFingerprintService $fingerprintService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function payloadForProfile(
        FeedProfile $feedProfile,
        string $environmentClass,
        ?string $environmentLabel = null
    ): array {
        $feedProfile->loadMissing([
            'shop',
            'sourceConnection.latestImport',
            'categoryMappings.sourceCategory',
            'categoryMappings.kastaCategory',
            'attributeMappings.sourceCategory',
            'attributeMappings.sourceAttribute',
            'attributeMappings.kastaCategory',
            'attributeMappings.kastaAttribute',
            'valueMappings.attributeMapping.sourceCategory',
            'valueMappings.attributeMapping.sourceAttribute',
            'valueMappings.attributeMapping.kastaCategory',
            'valueMappings.attributeMapping.kastaAttribute',
            'valueMappings.sourceAttributeValue',
            'valueMappings.kastaAttributeValue',
        ]);

        $settings = $this->normalizedFeedSettings($feedProfile);
        $shopConfig = [
            'currency' => $feedProfile->shop?->currency,
            'locale' => $feedProfile->shop?->locale,
            'timezone' => $feedProfile->shop?->timezone,
            'is_active' => (bool) ($feedProfile->shop?->is_active ?? false),
            'settings' => $this->sanitizeArray((array) Arr::except((array) ($feedProfile->shop?->settings ?? []), ['onboarding'])),
        ];
        [$sourceOptions, $redactedOptionKeys] = $this->sanitizeOptions((array) Arr::except((array) ($feedProfile->sourceConnection?->options ?? []), ['promotion_meta']));
        $sourceSecretPolicy = $this->sourceSecretPolicy($feedProfile->sourceConnection);
        $categoryMappings = $this->categoryMappings($feedProfile);
        $attributeMappings = $this->attributeMappings($feedProfile);
        $valueMappings = $this->valueMappings($feedProfile);
        $publishRuleKeys = [
            'publish_guard_enabled',
            'minimum_ready_items',
            'maximum_invalid_ratio',
            'block_publish_on_critical_conformance',
            'minimum_pictures',
            'signoff_required',
            'required_signoff_status',
            'publish_window_enabled',
            'publish_window_days',
            'publish_window_start',
            'publish_window_end',
            'publish_window_timezone',
            'freeze_mode',
            'hypercare',
        ];
        $merchantOverrideKeys = [
            'excluded_source_categories',
            'excluded_vendors',
            'minimum_price_threshold',
            'override_minimum_pictures',
            'forced_attribute_overrides',
            'forced_value_overrides',
            'disabled_export_category_ids',
        ];
        $publishRules = Arr::only($settings, $publishRuleKeys);
        $merchantOverrides = Arr::only($settings, $merchantOverrideKeys);
        $sourceMetadata = [
            'status' => $feedProfile->sourceConnection?->status,
            'source_url' => $feedProfile->sourceConnection?->source_url,
            'api_base_url' => $feedProfile->sourceConnection?->api_base_url,
            'api_version' => $feedProfile->sourceConnection?->api_version,
            'sync_interval_minutes' => $feedProfile->sourceConnection?->sync_interval_minutes,
            'options' => $sourceOptions,
            'redacted_option_keys' => $redactedOptionKeys,
        ];
        $fingerprints = [
            'shop_config' => $this->fingerprintService->fingerprint($shopConfig),
            'feed_profile_config' => $this->fingerprintService->fingerprint([
                'status' => $feedProfile->status,
                'currency' => $feedProfile->currency,
                'language' => $feedProfile->language,
                'include_unavailable' => (bool) $feedProfile->include_unavailable,
                'auto_sync' => (bool) $feedProfile->auto_sync,
                'auto_build' => (bool) $feedProfile->auto_build,
                'build_interval_minutes' => (int) $feedProfile->build_interval_minutes,
            ]),
            'settings' => $this->fingerprintService->fingerprint($settings),
            'publish_rules' => $this->fingerprintService->fingerprint($publishRules),
            'merchant_overrides' => $this->fingerprintService->fingerprint($merchantOverrides),
            'category_mappings' => $this->fingerprintService->fingerprint($categoryMappings),
            'attribute_mappings' => $this->fingerprintService->fingerprint($attributeMappings),
            'value_mappings' => $this->fingerprintService->fingerprint($valueMappings),
            'mappings' => $this->fingerprintService->fingerprint([
                'category' => $categoryMappings,
                'attribute' => $attributeMappings,
                'value' => $valueMappings,
            ]),
            'source_connection' => $this->fingerprintService->fingerprint([
                'driver' => $feedProfile->sourceConnection?->driver,
                'metadata' => $sourceMetadata,
                'secret_policy' => Arr::except($sourceSecretPolicy, ['secret_present', 'secret_validated']),
            ]),
        ];
        $payload = [
            'schema_version' => (int) config('feed_mediator.promotion.snapshot_schema_version', 1),
            'generated_at' => now()->toIso8601String(),
            'environment' => [
                'class' => $environmentClass,
                'label' => $environmentLabel ?: ucfirst($environmentClass),
            ],
            'shop' => [
                'identity' => [
                    'name' => $feedProfile->shop?->name,
                    'slug' => $feedProfile->shop?->slug,
                ],
                'config' => $shopConfig,
                'onboarding' => (array) data_get($feedProfile->shop?->settings, 'onboarding', []),
            ],
            'feed_profile' => [
                'identity' => [
                    'name' => $feedProfile->name,
                    'code' => $feedProfile->code,
                ],
                'config' => [
                    'status' => $feedProfile->status,
                    'currency' => $feedProfile->currency,
                    'language' => $feedProfile->language,
                    'include_unavailable' => (bool) $feedProfile->include_unavailable,
                    'auto_sync' => (bool) $feedProfile->auto_sync,
                    'auto_build' => (bool) $feedProfile->auto_build,
                    'build_interval_minutes' => (int) $feedProfile->build_interval_minutes,
                ],
                'settings' => $settings,
                'publish_rules' => $publishRules,
                'merchant_overrides' => $merchantOverrides,
            ],
            'source_connection' => [
                'identity' => [
                    'name' => $feedProfile->sourceConnection?->name,
                    'code' => $feedProfile->sourceConnection?->code,
                ],
                'driver' => $feedProfile->sourceConnection?->driver,
                'metadata' => $sourceMetadata,
                'secret_policy' => $sourceSecretPolicy,
            ],
            'dictionary_refs' => $this->dictionaryReferences(),
            'mappings' => [
                'category' => $categoryMappings,
                'attribute' => $attributeMappings,
                'value' => $valueMappings,
            ],
            'compatibility' => [
                'required_source_driver' => $feedProfile->sourceConnection?->driver,
                'secret_transfer' => 'never_plaintext',
                'required_dictionary_types' => collect($this->dictionaryReferences())->pluck('type')->values()->all(),
                'target_expectations' => [
                    'target_feed_profile_exists',
                    'target_source_driver_matches',
                    'target_source_catalog_is_synced',
                    'target_dictionaries_are_compatible',
                ],
            ],
            'fingerprints' => $fingerprints,
        ];
        $payload['fingerprints']['overall'] = $this->fingerprintService->fingerprint(Arr::except($payload, ['generated_at', 'fingerprints']));

        return $payload;
    }

    public function generate(
        FeedProfile $feedProfile,
        string $environmentClass,
        ?string $environmentLabel = null,
        ?User $user = null,
        string $sourceType = PromotionSnapshot::SOURCE_GENERATED,
        ?string $name = null
    ): PromotionSnapshot {
        $payload = $this->payloadForProfile($feedProfile, $environmentClass, $environmentLabel);

        return $this->createFromPayload(
            $payload,
            $feedProfile,
            $user,
            $sourceType,
            $name ?: ($feedProfile->code.' promotion snapshot')
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createFromPayload(
        array $payload,
        ?FeedProfile $feedProfile = null,
        ?User $user = null,
        string $sourceType = PromotionSnapshot::SOURCE_IMPORTED,
        ?string $name = null
    ): PromotionSnapshot {
        $checksum = $this->checksumForPayload($payload);
        $snapshot = PromotionSnapshot::query()->firstOrNew(['checksum' => $checksum]);

        $snapshot->fill([
            'shop_id' => $feedProfile?->shop_id,
            'feed_profile_id' => $feedProfile?->id,
            'user_id' => $user?->id,
            'environment_class' => (string) data_get($payload, 'environment.class', 'unknown'),
            'environment_label' => data_get($payload, 'environment.label'),
            'source_type' => $sourceType,
            'name' => $name,
            'checksum' => $checksum,
            'mapping_fingerprint' => data_get($payload, 'fingerprints.mappings'),
            'settings_fingerprint' => data_get($payload, 'fingerprints.settings'),
            'source_connection_fingerprint' => data_get($payload, 'fingerprints.source_connection'),
            'payload' => $payload,
            'summary' => [
                'shop_slug' => data_get($payload, 'shop.identity.slug'),
                'feed_profile_code' => data_get($payload, 'feed_profile.identity.code'),
                'driver' => data_get($payload, 'source_connection.driver'),
                'category_mappings_count' => count((array) data_get($payload, 'mappings.category', [])),
                'attribute_mappings_count' => count((array) data_get($payload, 'mappings.attribute', [])),
                'value_mappings_count' => count((array) data_get($payload, 'mappings.value', [])),
                'dictionary_refs_count' => count((array) data_get($payload, 'dictionary_refs', [])),
            ],
            'generated_at' => now(),
            'imported_at' => $sourceType === PromotionSnapshot::SOURCE_IMPORTED ? now() : $snapshot->imported_at,
        ]);
        $snapshot->save();

        return $snapshot->refresh();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function checksumForPayload(array $payload): string
    {
        return $this->fingerprintService->fingerprint(Arr::except($payload, ['generated_at', 'fingerprints']));
    }

    public function importFromJson(string $json, ?User $user = null, ?string $name = null): PromotionSnapshot
    {
        /** @var array<string, mixed> $document */
        $document = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $payload = is_array(data_get($document, 'payload'))
            ? (array) $document['payload']
            : $document;

        return $this->createFromPayload(
            $payload,
            null,
            $user,
            PromotionSnapshot::SOURCE_IMPORTED,
            $name ?: ((string) data_get($payload, 'feed_profile.identity.code', 'imported-snapshot'))
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function document(PromotionSnapshot $snapshot): array
    {
        return [
            'checksum' => $snapshot->checksum,
            'source_type' => $snapshot->source_type,
            'environment_class' => $snapshot->environment_class,
            'payload' => $snapshot->payload,
            'summary' => $snapshot->summary,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function categoryMappings(FeedProfile $feedProfile): array
    {
        return $feedProfile->categoryMappings
            ->map(function (CategoryMapping $mapping): array {
                $row = [
                    'identity' => [
                        'source_category' => $this->sourceCategoryReference($mapping->sourceCategory),
                    ],
                    'target' => [
                        'kasta_category' => $this->kastaCategoryReference($mapping->kastaCategory),
                    ],
                    'mapping_strategy' => $mapping->mapping_strategy,
                    'is_active' => (bool) $mapping->is_active,
                ];
                $row['identity_key'] = $this->categoryMappingKey($row);

                return $row;
            })
            ->sortBy('identity_key')
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function attributeMappings(FeedProfile $feedProfile): array
    {
        return $feedProfile->attributeMappings
            ->map(function (AttributeMapping $mapping): array {
                $row = [
                    'identity' => [
                        'source_category' => $this->sourceCategoryReference($mapping->sourceCategory),
                        'source_attribute' => $this->sourceAttributeReference($mapping->sourceAttribute),
                    ],
                    'target' => [
                        'kasta_category' => $this->kastaCategoryReference($mapping->kastaCategory),
                        'kasta_attribute' => $this->kastaAttributeReference($mapping->kastaAttribute),
                    ],
                    'mapping_strategy' => $mapping->mapping_strategy,
                    'is_required' => (bool) $mapping->is_required,
                    'default_value' => $mapping->default_value,
                    'use_variant_value' => (bool) $mapping->use_variant_value,
                ];
                $row['identity_key'] = $this->attributeMappingKey($row);

                return $row;
            })
            ->sortBy('identity_key')
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function valueMappings(FeedProfile $feedProfile): array
    {
        return $feedProfile->valueMappings
            ->map(function ($mapping): array {
                $row = [
                    'identity' => [
                        'source_category' => $this->sourceCategoryReference($mapping->attributeMapping?->sourceCategory),
                        'source_attribute' => $this->sourceAttributeReference($mapping->attributeMapping?->sourceAttribute),
                        'source_value' => [
                            'raw_value' => $mapping->source_raw_value,
                            'normalized_value' => $mapping->normalized_source_value,
                        ],
                    ],
                    'target' => [
                        'kasta_category' => $this->kastaCategoryReference($mapping->attributeMapping?->kastaCategory),
                        'kasta_attribute' => $this->kastaAttributeReference($mapping->attributeMapping?->kastaAttribute),
                        'kasta_attribute_value' => $this->kastaAttributeValueReference(
                            $mapping->kastaAttributeValue,
                            $mapping->target_value
                        ),
                    ],
                    'mapping_strategy' => $mapping->mapping_strategy,
                    'is_active' => (bool) $mapping->is_active,
                ];
                $row['identity_key'] = $this->valueMappingKey($row);

                return $row;
            })
            ->sortBy('identity_key')
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function dictionaryReferences(): array
    {
        $types = [
            DictionaryImport::TYPE_KASTA_CATEGORIES,
            DictionaryImport::TYPE_KASTA_ATTRIBUTES,
            DictionaryImport::TYPE_KASTA_ATTRIBUTE_VALUES,
            DictionaryImport::TYPE_SIZE_GRIDS,
        ];

        return collect($types)
            ->map(function (string $type): ?array {
                $latest = DictionaryImport::query()
                    ->where('type', $type)
                    ->where('status', DictionaryImport::STATUS_COMPLETED)
                    ->where('dry_run', false)
                    ->latest('finished_at')
                    ->latest('id')
                    ->first();

                if ($latest === null) {
                    return null;
                }

                return [
                    'type' => $latest->type,
                    'checksum' => $latest->checksum,
                    'finished_at' => optional($latest->finished_at)->toIso8601String(),
                    'source_format' => $latest->source_format,
                    'original_filename' => $latest->original_filename,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizedFeedSettings(FeedProfile $feedProfile): array
    {
        $settings = $this->sanitizeArray($feedProfile->exportSettings());
        $excludedIds = array_map('intval', (array) ($settings['excluded_source_category_ids'] ?? []));
        $categories = SourceCategory::query()
            ->where('source_connection_id', $feedProfile->source_connection_id)
            ->whereIn('id', $excludedIds)
            ->get()
            ->map(fn (SourceCategory $category) => $this->sourceCategoryReference($category))
            ->sortBy(fn (array $row) => $this->sourceCategoryKey($row))
            ->values()
            ->all();

        unset($settings['excluded_source_category_ids']);
        $settings['excluded_source_categories'] = $categories;
        $settings['excluded_vendors'] = array_values(array_filter((array) ($settings['excluded_vendors'] ?? [])));
        $settings['disabled_export_category_ids'] = array_values(array_filter((array) ($settings['disabled_export_category_ids'] ?? [])));
        $settings['forced_attribute_overrides'] = $this->sanitizeArray((array) ($settings['forced_attribute_overrides'] ?? []));
        $settings['forced_value_overrides'] = $this->sanitizeArray((array) ($settings['forced_value_overrides'] ?? []));
        $settings['hypercare'] = $this->sanitizeArray((array) ($settings['hypercare'] ?? []));

        return $settings;
    }

    /**
     * @return array{0:array<string, mixed>,1:list<string>}
     */
    private function sanitizeOptions(array $options): array
    {
        $redactedKeys = [];

        $sanitized = $this->sanitizeArray($options, '', $redactedKeys);

        return [$sanitized, array_values(array_unique($redactedKeys))];
    }

    /**
     * @param  list<string>  $redactedKeys
     * @return array<string, mixed>
     */
    private function sanitizeArray(array $value, string $prefix = '', array &$redactedKeys = []): array
    {
        $sanitized = [];

        foreach ($value as $key => $item) {
            $keyString = (string) $key;
            $path = $prefix === '' ? $keyString : $prefix.'.'.$keyString;

            if ($this->looksSensitiveKey($keyString)) {
                $sanitized[$keyString] = '[redacted]';
                $redactedKeys[] = $path;

                continue;
            }

            if (is_array($item)) {
                $sanitized[$keyString] = $this->sanitizeArray($item, $path, $redactedKeys);

                continue;
            }

            $sanitized[$keyString] = $item;
        }

        return $sanitized;
    }

    /**
     * @return array<string, mixed>
     */
    private function sourceSecretPolicy(?SourceConnection $connection): array
    {
        $requiredFields = [];
        $credentialKeys = [];

        if (! $connection instanceof SourceConnection) {
            return [
                'required_fields' => [],
                'credential_keys' => [],
                'secret_present' => false,
                'secret_validated' => false,
                'transfer_mode' => 'never_plaintext',
            ];
        }

        if ($connection->usesPromApi()) {
            $requiredFields = ['api_token'];
        } elseif (! empty($connection->credentials)) {
            $requiredFields = ['credentials'];
            $credentialKeys = array_keys((array) $connection->credentials);
        }

        return [
            'required_fields' => $requiredFields,
            'credential_keys' => $credentialKeys,
            'secret_present' => $connection->usesPromApi()
                ? filled($connection->api_token)
                : ! empty($connection->credentials),
            'secret_validated' => $connection->last_connection_check_status === SourceConnection::CHECK_STATUS_OK,
            'transfer_mode' => 'never_plaintext',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sourceCategoryReference(?SourceCategory $category): array
    {
        return [
            'external_id' => $category?->external_id,
            'rz_id' => $category?->rz_id,
            'name' => $category?->name,
            'full_path' => $category?->full_path,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sourceAttributeReference(?\App\Models\SourceAttribute $attribute): array
    {
        return [
            'code' => $attribute?->code,
            'name' => $attribute?->name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function kastaCategoryReference(?\App\Models\KastaCategory $category): array
    {
        return [
            'external_id' => $category?->external_id,
            'rz_id' => $category?->rz_id,
            'name' => $category?->name,
            'full_path' => $category?->full_path,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function kastaAttributeReference(?KastaAttribute $attribute): array
    {
        return [
            'code' => $attribute?->code,
            'external_id' => $attribute?->external_id,
            'name' => $attribute?->name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function kastaAttributeValueReference(?\App\Models\KastaAttributeValue $value, ?string $targetValue = null): array
    {
        return [
            'external_id' => $value?->external_id,
            'value' => $value?->value ?? $targetValue,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function categoryMappingKey(array $row): string
    {
        return $this->sourceCategoryKey((array) data_get($row, 'identity.source_category', []));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function attributeMappingKey(array $row): string
    {
        $category = $this->sourceCategoryKey((array) data_get($row, 'identity.source_category', []));
        $attribute = collect((array) data_get($row, 'identity.source_attribute', []))
            ->filter(fn ($value) => filled($value))
            ->join('|');

        return $category.'::'.$attribute;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function valueMappingKey(array $row): string
    {
        return $this->attributeMappingKey($row)
            .'::'.(string) data_get($row, 'identity.source_value.normalized_value')
            .'::'.(string) data_get($row, 'identity.source_value.raw_value');
    }

    /**
     * @param  array<string, mixed>  $reference
     */
    private function sourceCategoryKey(array $reference): string
    {
        return collect($reference)
            ->filter(fn ($value) => filled($value))
            ->join('|');
    }

    private function looksSensitiveKey(string $key): bool
    {
        return (bool) preg_match('/token|secret|password|credential|auth/i', $key);
    }
}
