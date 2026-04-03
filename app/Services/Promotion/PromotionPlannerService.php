<?php

namespace App\Services\Promotion;

use App\Models\AttributeMapping;
use App\Models\CategoryMapping;
use App\Models\FeedProfile;
use App\Models\KastaAttribute;
use App\Models\KastaAttributeValue;
use App\Models\KastaCategory;
use App\Models\PromotionRun;
use App\Models\PromotionSnapshot;
use App\Models\SourceAttribute;
use App\Models\SourceCategory;
use App\Models\ValueMapping;
use Illuminate\Support\Arr;

class PromotionPlannerService
{
    public function __construct(
        private readonly PromotionSnapshotService $snapshotService,
        private readonly PromotionFingerprintService $fingerprintService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function driftReport(PromotionSnapshot $sourceSnapshot, FeedProfile $targetFeedProfile): array
    {
        $sourcePayload = (array) ($sourceSnapshot->payload ?? []);
        $targetPayload = $this->snapshotService->payloadForProfile(
            $targetFeedProfile,
            (string) config('feed_mediator.environment.class', config('app.env', 'local')),
            (string) (config('feed_mediator.environment.label') ?: null)
        );
        $compatibility = $this->compatibility($sourcePayload, $targetFeedProfile, $targetPayload);
        $categoryDiff = $this->mappingDiff(
            (array) data_get($sourcePayload, 'mappings.category', []),
            (array) data_get($targetPayload, 'mappings.category', []),
            fn (array $row) => (string) ($row['identity_key'] ?? $this->snapshotService->categoryMappingKey($row))
        );
        $attributeDiff = $this->mappingDiff(
            (array) data_get($sourcePayload, 'mappings.attribute', []),
            (array) data_get($targetPayload, 'mappings.attribute', []),
            fn (array $row) => (string) ($row['identity_key'] ?? $this->snapshotService->attributeMappingKey($row))
        );
        $valueDiff = $this->mappingDiff(
            (array) data_get($sourcePayload, 'mappings.value', []),
            (array) data_get($targetPayload, 'mappings.value', []),
            fn (array $row) => (string) ($row['identity_key'] ?? $this->snapshotService->valueMappingKey($row))
        );
        $shopDiff = $this->configDiff(
            (array) data_get($sourcePayload, 'shop.config', []),
            (array) data_get($targetPayload, 'shop.config', [])
        );
        $onboardingDiff = $this->configDiff(
            (array) data_get($sourcePayload, 'shop.onboarding', []),
            (array) data_get($targetPayload, 'shop.onboarding', [])
        );
        $profileConfigDiff = $this->configDiff(
            (array) data_get($sourcePayload, 'feed_profile.config', []),
            (array) data_get($targetPayload, 'feed_profile.config', [])
        );
        $feedSettingsDiff = $this->configDiff(
            (array) data_get($sourcePayload, 'feed_profile.settings', []),
            (array) data_get($targetPayload, 'feed_profile.settings', [])
        );
        $publishRulesDiff = $this->configDiff(
            (array) data_get($sourcePayload, 'feed_profile.publish_rules', []),
            (array) data_get($targetPayload, 'feed_profile.publish_rules', [])
        );
        $merchantOverridesDiff = $this->configDiff(
            (array) data_get($sourcePayload, 'feed_profile.merchant_overrides', []),
            (array) data_get($targetPayload, 'feed_profile.merchant_overrides', [])
        );
        $sourceConnectionDiff = $this->configDiff(
            (array) data_get($sourcePayload, 'source_connection.metadata', []),
            (array) data_get($targetPayload, 'source_connection.metadata', [])
        );
        $dictionaryDiff = $this->dictionaryDiff(
            (array) data_get($sourcePayload, 'dictionary_refs', []),
            (array) data_get($targetPayload, 'dictionary_refs', [])
        );
        $hasDrift = collect([
            $categoryDiff['summary']['changes_total'],
            $attributeDiff['summary']['changes_total'],
            $valueDiff['summary']['changes_total'],
            $shopDiff['summary']['changes_total'],
            $onboardingDiff['summary']['changes_total'],
            $profileConfigDiff['summary']['changes_total'],
            $feedSettingsDiff['summary']['changes_total'],
            $publishRulesDiff['summary']['changes_total'],
            $merchantOverridesDiff['summary']['changes_total'],
            $sourceConnectionDiff['summary']['changes_total'],
            $dictionaryDiff['summary']['changes_total'],
        ])->sum() > 0;

        return [
            'status' => $compatibility['errors'] !== []
                ? 'incompatible'
                : ($hasDrift ? 'drift_detected' : 'no_drift'),
            'source_snapshot_id' => $sourceSnapshot->id,
            'source_snapshot_checksum' => $sourceSnapshot->checksum,
            'warnings' => $compatibility['warnings'],
            'errors' => $compatibility['errors'],
            'sections' => [
                'shop_config' => $shopDiff,
                'onboarding_state' => $onboardingDiff,
                'feed_profile_config' => $profileConfigDiff,
                'feed_settings' => $feedSettingsDiff,
                'publish_rules' => $publishRulesDiff,
                'merchant_overrides' => $merchantOverridesDiff,
                'source_connection_shape' => $sourceConnectionDiff,
                'dictionary_refs' => $dictionaryDiff,
                'category_mappings' => $categoryDiff,
                'attribute_mappings' => $attributeDiff,
                'value_mappings' => $valueDiff,
            ],
            'summary' => [
                'shop_changes' => $shopDiff['summary']['changes_total'],
                'onboarding_changes' => $onboardingDiff['summary']['changes_total'],
                'feed_profile_changes' => $profileConfigDiff['summary']['changes_total'],
                'feed_settings_changes' => $feedSettingsDiff['summary']['changes_total'],
                'publish_rules_changes' => $publishRulesDiff['summary']['changes_total'],
                'merchant_overrides_changes' => $merchantOverridesDiff['summary']['changes_total'],
                'source_connection_changes' => $sourceConnectionDiff['summary']['changes_total'],
                'dictionary_mismatches' => $dictionaryDiff['summary']['changes_total'],
                'missing_mappings' => $categoryDiff['summary']['missing_in_target']
                    + $attributeDiff['summary']['missing_in_target']
                    + $valueDiff['summary']['missing_in_target'],
                'changed_mappings' => $categoryDiff['summary']['changed']
                    + $attributeDiff['summary']['changed']
                    + $valueDiff['summary']['changed'],
                'target_only_mappings' => $categoryDiff['summary']['extra_in_target']
                    + $attributeDiff['summary']['extra_in_target']
                    + $valueDiff['summary']['extra_in_target'],
                'no_drift' => ! $hasDrift && $compatibility['errors'] === [],
            ],
            'fingerprints' => [
                'source' => (array) data_get($sourcePayload, 'fingerprints', []),
                'target' => (array) data_get($targetPayload, 'fingerprints', []),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function plan(PromotionSnapshot $sourceSnapshot, FeedProfile $targetFeedProfile, string $strategy): array
    {
        $strategy = in_array($strategy, PromotionRun::strategies(), true)
            ? $strategy
            : PromotionRun::STRATEGY_SAFE_MERGE;
        $drift = $this->driftReport($sourceSnapshot, $targetFeedProfile);
        $sourcePayload = (array) ($sourceSnapshot->payload ?? []);
        $operations = [
            'shop' => [],
            'onboarding' => [],
            'feed_profile' => [],
            'source_connection' => [],
            'category_mappings' => [],
            'attribute_mappings' => [],
            'value_mappings' => [],
        ];
        $warnings = $drift['warnings'];
        $errors = $drift['errors'];
        $conflicts = [];

        $shopOperation = $this->shopOperation($sourcePayload, $targetFeedProfile);
        $operations['shop'][] = $shopOperation;
        $this->collectOperation($shopOperation, $conflicts, $warnings, $errors);

        $onboardingOperation = $this->onboardingOperation($sourcePayload, $targetFeedProfile);
        $operations['onboarding'][] = $onboardingOperation;
        $this->collectOperation($onboardingOperation, $conflicts, $warnings, $errors);

        [$resolvedSettings, $settingsWarnings, $settingsErrors] = $this->restoreSettingsForTarget(
            $targetFeedProfile,
            (array) data_get($sourcePayload, 'feed_profile.settings', [])
        );
        $warnings = array_merge($warnings, $settingsWarnings);
        $errors = array_merge($errors, $settingsErrors);

        $feedProfileOperation = $this->feedProfileOperation($sourcePayload, $targetFeedProfile, $resolvedSettings);
        $operations['feed_profile'][] = $feedProfileOperation;
        $this->collectOperation($feedProfileOperation, $conflicts, $warnings, $errors);

        $sourceConnectionOperation = $this->sourceConnectionOperation($sourcePayload, $targetFeedProfile);
        $operations['source_connection'][] = $sourceConnectionOperation;
        $this->collectOperation($sourceConnectionOperation, $conflicts, $warnings, $errors);

        $attributeOperationsByKey = [];
        foreach ((array) data_get($sourcePayload, 'mappings.category', []) as $row) {
            $operation = $this->categoryMappingOperation($targetFeedProfile, (array) $row, $strategy);
            $operations['category_mappings'][] = $operation;
            $this->collectOperation($operation, $conflicts, $warnings, $errors);
        }

        foreach ((array) data_get($sourcePayload, 'mappings.attribute', []) as $row) {
            $operation = $this->attributeMappingOperation($targetFeedProfile, (array) $row, $strategy);
            $operations['attribute_mappings'][] = $operation;
            $attributeOperationsByKey[$operation['identity_key']] = $operation;
            $this->collectOperation($operation, $conflicts, $warnings, $errors);
        }

        foreach ((array) data_get($sourcePayload, 'mappings.value', []) as $row) {
            $operation = $this->valueMappingOperation($targetFeedProfile, (array) $row, $strategy, $attributeOperationsByKey);
            $operations['value_mappings'][] = $operation;
            $this->collectOperation($operation, $conflicts, $warnings, $errors);
        }

        if ($strategy === PromotionRun::STRATEGY_OVERWRITE_TARGET) {
            foreach ((array) data_get($drift, 'sections.category_mappings.extra_in_target', []) as $row) {
                $operations['category_mappings'][] = $this->deleteMappingOperation(
                    'category_mappings',
                    (array) ($row['target'] ?? []),
                    (string) data_get($row, 'target.identity.source_category.full_path', data_get($row, 'target.identity.source_category.name', 'Category mapping'))
                );
            }

            foreach ((array) data_get($drift, 'sections.attribute_mappings.extra_in_target', []) as $row) {
                $operations['attribute_mappings'][] = $this->deleteMappingOperation(
                    'attribute_mappings',
                    (array) ($row['target'] ?? []),
                    (string) data_get($row, 'target.identity.source_attribute.name', 'Attribute mapping')
                );
            }

            foreach ((array) data_get($drift, 'sections.value_mappings.extra_in_target', []) as $row) {
                $operations['value_mappings'][] = $this->deleteMappingOperation(
                    'value_mappings',
                    (array) ($row['target'] ?? []),
                    (string) data_get($row, 'target.identity.source_value.raw_value', 'Value mapping')
                );
            }
        }

        $summary = [
            'created' => $this->countOperations($operations, 'create'),
            'updated' => $this->countOperations($operations, 'update'),
            'deleted' => $this->countOperations($operations, 'delete'),
            'skipped' => $this->countOperations($operations, 'skip'),
            'conflicts' => count($conflicts),
            'warnings' => count(array_unique($warnings)),
            'blocking_errors' => count(array_unique($errors)),
            'secret_rebind_required' => (bool) data_get($sourceConnectionOperation, 'secret_rebind.required', false),
        ];

        return [
            'status' => $summary['blocking_errors'] > 0
                ? 'blocked'
                : ($summary['conflicts'] > 0 ? 'warning' : 'ready'),
            'strategy' => $strategy,
            'drift' => $drift,
            'summary' => $summary,
            'warnings' => array_values(array_unique($warnings)),
            'errors' => array_values(array_unique($errors)),
            'conflicts' => array_values($conflicts),
            'secret_rebind' => data_get($sourceConnectionOperation, 'secret_rebind', []),
            'operations' => $operations,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function deleteMappingOperation(string $section, array $row, string $label): array
    {
        return [
            'section' => $section,
            'action' => 'delete',
            'identity_key' => (string) ($row['identity_key'] ?? $label),
            'label' => $label,
            'reason' => 'Target-only mapping will be removed to match the source snapshot.',
            'current' => $row,
            'incoming' => null,
            'resolved' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $sourcePayload
     * @param  array<string, mixed>  $targetPayload
     * @return array<string, mixed>
     */
    private function compatibility(array $sourcePayload, FeedProfile $targetFeedProfile, array $targetPayload): array
    {
        $warnings = [];
        $errors = [];
        $sourceDriver = (string) data_get($sourcePayload, 'source_connection.driver');
        $targetDriver = (string) ($targetFeedProfile->sourceConnection?->driver ?? '');

        if ($sourceDriver === '') {
            $errors[] = 'Source snapshot is missing source driver metadata.';
        } elseif ($targetDriver === '') {
            $warnings[] = 'Target source connection is missing and will need metadata promotion, secret re-entry, and a fresh sync.';
        } elseif ($sourceDriver !== $targetDriver) {
            $errors[] = sprintf(
                'Source driver [%s] is incompatible with target driver [%s].',
                $sourceDriver,
                $targetDriver
            );
        }

        $sourceShopSlug = (string) data_get($sourcePayload, 'shop.identity.slug');
        $targetShopSlug = (string) data_get($targetPayload, 'shop.identity.slug');

        if ($sourceShopSlug !== '' && $targetShopSlug !== '' && $sourceShopSlug !== $targetShopSlug) {
            $warnings[] = sprintf('Shop slug differs: source [%s], target [%s].', $sourceShopSlug, $targetShopSlug);
        }

        $sourceProfileCode = (string) data_get($sourcePayload, 'feed_profile.identity.code');

        if ($sourceProfileCode !== '' && $targetFeedProfile->code !== $sourceProfileCode) {
            $warnings[] = sprintf(
                'Feed profile code differs: source [%s], target [%s].',
                $sourceProfileCode,
                $targetFeedProfile->code
            );
        }

        $sourceDictionaryRefs = collect((array) data_get($sourcePayload, 'dictionary_refs', []))->keyBy('type');
        $targetDictionaryRefs = collect((array) data_get($targetPayload, 'dictionary_refs', []))->keyBy('type');

        foreach ($sourceDictionaryRefs as $type => $reference) {
            $targetReference = $targetDictionaryRefs->get($type);

            if ($targetReference === null) {
                $errors[] = sprintf('Required dictionary import [%s] is missing on target.', $type);

                continue;
            }

            if (($reference['checksum'] ?? null) !== ($targetReference['checksum'] ?? null)) {
                $warnings[] = sprintf(
                    'Dictionary checksum mismatch for [%s]: source [%s], target [%s].',
                    $type,
                    $reference['checksum'] ?? 'n/a',
                    $targetReference['checksum'] ?? 'n/a'
                );
            }
        }

        return [
            'warnings' => array_values(array_unique($warnings)),
            'errors' => array_values(array_unique($errors)),
        ];
    }

    /**
     * @param  array<string, mixed>  $sourcePayload
     * @return array<string, mixed>
     */
    private function shopOperation(array $sourcePayload, FeedProfile $targetFeedProfile): array
    {
        $incoming = (array) data_get($sourcePayload, 'shop.config', []);
        $incomingSettings = (array) ($incoming['settings'] ?? []);
        $current = [
            'currency' => $targetFeedProfile->shop?->currency,
            'locale' => $targetFeedProfile->shop?->locale,
            'timezone' => $targetFeedProfile->shop?->timezone,
            'is_active' => (bool) ($targetFeedProfile->shop?->is_active ?? false),
            'settings' => Arr::only(
                (array) Arr::except((array) ($targetFeedProfile->shop?->settings ?? []), ['onboarding']),
                array_keys($incomingSettings)
            ),
        ];
        $incoming['settings'] = $incomingSettings;
        $mutableFields = ['currency', 'locale', 'timezone', 'is_active', 'settings'];
        $changes = collect($mutableFields)
            ->filter(fn ($field) => $this->fingerprintService->fingerprint($current[$field] ?? null) !== $this->fingerprintService->fingerprint($incoming[$field] ?? null))
            ->values()
            ->all();

        return [
            'section' => 'shop',
            'action' => $changes === [] ? 'skip' : 'update',
            'identity_key' => (string) data_get($sourcePayload, 'shop.identity.slug', $targetFeedProfile->shop?->slug),
            'label' => 'Shop non-secret config',
            'reason' => $changes === [] ? 'No shop config drift.' : 'Shop config differs.',
            'current' => $current,
            'incoming' => Arr::only($incoming, $mutableFields),
            'changes' => $changes,
        ];
    }

    /**
     * @param  array<string, mixed>  $sourcePayload
     * @return array<string, mixed>
     */
    private function onboardingOperation(array $sourcePayload, FeedProfile $targetFeedProfile): array
    {
        $incoming = (array) data_get($sourcePayload, 'shop.onboarding', []);
        $current = Arr::only(
            (array) data_get($targetFeedProfile->shop?->settings, 'onboarding', []),
            array_keys($incoming)
        );
        $changes = collect(array_values(array_unique(array_merge(array_keys($incoming), array_keys($current)))))
            ->filter(fn ($field) => $this->fingerprintService->fingerprint($current[$field] ?? null) !== $this->fingerprintService->fingerprint($incoming[$field] ?? null))
            ->values()
            ->all();

        return [
            'section' => 'onboarding',
            'action' => $changes === [] ? 'skip' : 'update',
            'identity_key' => (string) data_get($sourcePayload, 'shop.identity.slug', $targetFeedProfile->shop?->slug),
            'label' => 'Onboarding state',
            'reason' => $changes === [] ? 'No onboarding drift.' : 'Onboarding/bootstrap state differs.',
            'current' => $current,
            'incoming' => $incoming,
            'changes' => $changes,
        ];
    }

    /**
     * @param  array<string, mixed>  $sourcePayload
     * @param  array<string, mixed>  $resolvedSettings
     * @return array<string, mixed>
     */
    private function feedProfileOperation(array $sourcePayload, FeedProfile $targetFeedProfile, array $resolvedSettings): array
    {
        $incoming = (array) data_get($sourcePayload, 'feed_profile.config', []);
        $current = [
            'status' => $targetFeedProfile->status,
            'currency' => $targetFeedProfile->currency,
            'language' => $targetFeedProfile->language,
            'include_unavailable' => (bool) $targetFeedProfile->include_unavailable,
            'auto_sync' => (bool) $targetFeedProfile->auto_sync,
            'auto_build' => (bool) $targetFeedProfile->auto_build,
            'build_interval_minutes' => (int) $targetFeedProfile->build_interval_minutes,
            'settings' => Arr::only((array) $targetFeedProfile->exportSettings(), array_keys($resolvedSettings)),
        ];
        $incoming['settings'] = $resolvedSettings;
        $changes = collect(array_keys($current))
            ->filter(fn ($field) => $this->fingerprintService->fingerprint($current[$field] ?? null) !== $this->fingerprintService->fingerprint($incoming[$field] ?? null))
            ->values()
            ->all();

        return [
            'section' => 'feed_profile',
            'action' => $changes === [] ? 'skip' : 'update',
            'identity_key' => (string) data_get($sourcePayload, 'feed_profile.identity.code', $targetFeedProfile->code),
            'label' => 'Feed profile config',
            'reason' => $changes === [] ? 'No feed profile drift.' : 'Feed profile config differs.',
            'current' => $current,
            'incoming' => $incoming,
            'changes' => $changes,
        ];
    }

    /**
     * @param  array<string, mixed>  $sourcePayload
     * @return array<string, mixed>
     */
    private function sourceConnectionOperation(array $sourcePayload, FeedProfile $targetFeedProfile): array
    {
        $connection = $targetFeedProfile->sourceConnection;
        $incomingMetadata = (array) data_get($sourcePayload, 'source_connection.metadata', []);
        $incomingOptions = (array) ($incomingMetadata['options'] ?? []);
        $currentMetadata = [
            'status' => $connection?->status,
            'source_url' => $connection?->source_url,
            'api_base_url' => $connection?->api_base_url,
            'api_version' => $connection?->api_version,
            'sync_interval_minutes' => $connection?->sync_interval_minutes,
            'options' => Arr::only((array) Arr::except((array) ($connection?->options ?? []), ['promotion_meta']), array_keys($incomingOptions)),
            'redacted_option_keys' => [],
        ];
        $incomingMetadata['options'] = $incomingOptions;
        $changes = collect(['status', 'source_url', 'api_base_url', 'api_version', 'sync_interval_minutes', 'options'])
            ->filter(fn ($field) => $this->fingerprintService->fingerprint($currentMetadata[$field] ?? null) !== $this->fingerprintService->fingerprint($incomingMetadata[$field] ?? null))
            ->values()
            ->all();
        $secretPolicy = (array) data_get($sourcePayload, 'source_connection.secret_policy', []);
        $secretRebind = [
            'required' => $connection?->promotionSecretRebindRequiredFor($secretPolicy) ?? false,
            'state' => $connection?->promotionSecretStateFor($secretPolicy) ?? 'missing',
            'required_fields' => (array) ($secretPolicy['required_fields'] ?? []),
            'edit_route' => $connection ? route('admin.source-connections.edit', $connection) : null,
            'show_route' => $connection ? route('admin.source-connections.show', $connection) : null,
        ];

        return [
            'section' => 'source_connection',
            'action' => $connection === null
                ? 'create'
                : ($changes === [] ? 'skip' : 'update'),
            'identity_key' => (string) data_get($sourcePayload, 'source_connection.identity.code', $connection?->code),
            'label' => 'Source connection metadata',
            'reason' => $connection === null
                ? 'Target source connection is missing and will be created without secrets.'
                : ($changes === [] ? 'No non-secret source drift.' : 'Source connection metadata differs.'),
            'current' => $currentMetadata,
            'incoming' => $incomingMetadata,
            'changes' => $changes,
            'secret_rebind' => $secretRebind,
            'secret_policy' => $secretPolicy,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function categoryMappingOperation(FeedProfile $targetFeedProfile, array $row, string $strategy): array
    {
        $sourceCategory = $this->resolveSourceCategory($targetFeedProfile, (array) data_get($row, 'identity.source_category', []));
        $kastaCategory = $this->resolveKastaCategory((array) data_get($row, 'target.kasta_category', []));
        $existing = $sourceCategory
            ? CategoryMapping::query()
                ->where('feed_profile_id', $targetFeedProfile->id)
                ->where('source_category_id', $sourceCategory->id)
                ->first()
            : null;

        return $this->mappingOperation(
            'category_mappings',
            (string) ($row['identity_key'] ?? $this->snapshotService->categoryMappingKey($row)),
            (string) data_get($row, 'identity.source_category.full_path', data_get($row, 'identity.source_category.name', 'Category mapping')),
            $row,
            $existing ? [
                'kasta_category_id' => $existing->kasta_category_id,
                'mapping_strategy' => $existing->mapping_strategy,
                'is_active' => (bool) $existing->is_active,
            ] : null,
            $sourceCategory !== null && $kastaCategory !== null,
            [
                'source_category_id' => $sourceCategory?->id,
                'kasta_category_id' => $kastaCategory?->id,
            ],
            $strategy,
            $existing?->kasta_category_id,
            $kastaCategory?->id
        );
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function attributeMappingOperation(FeedProfile $targetFeedProfile, array $row, string $strategy): array
    {
        $sourceCategory = $this->resolveSourceCategory($targetFeedProfile, (array) data_get($row, 'identity.source_category', []));
        $sourceAttribute = $this->resolveSourceAttribute($targetFeedProfile, (array) data_get($row, 'identity.source_attribute', []));
        $kastaCategory = $this->resolveKastaCategory((array) data_get($row, 'target.kasta_category', []));
        $kastaAttribute = $this->resolveKastaAttribute($kastaCategory?->id, (array) data_get($row, 'target.kasta_attribute', []));
        $existing = ($sourceCategory && $sourceAttribute)
            ? AttributeMapping::query()
                ->where('feed_profile_id', $targetFeedProfile->id)
                ->where('source_category_id', $sourceCategory->id)
                ->where('source_attribute_id', $sourceAttribute->id)
                ->first()
            : null;

        return $this->mappingOperation(
            'attribute_mappings',
            (string) ($row['identity_key'] ?? $this->snapshotService->attributeMappingKey($row)),
            (string) data_get($row, 'identity.source_attribute.name', 'Attribute mapping'),
            $row,
            $existing ? [
                'kasta_category_id' => $existing->kasta_category_id,
                'kasta_attribute_id' => $existing->kasta_attribute_id,
                'mapping_strategy' => $existing->mapping_strategy,
                'is_required' => (bool) $existing->is_required,
                'default_value' => $existing->default_value,
                'use_variant_value' => (bool) $existing->use_variant_value,
            ] : null,
            $sourceCategory !== null && $sourceAttribute !== null && $kastaCategory !== null && $kastaAttribute !== null,
            [
                'source_category_id' => $sourceCategory?->id,
                'source_attribute_id' => $sourceAttribute?->id,
                'kasta_category_id' => $kastaCategory?->id,
                'kasta_attribute_id' => $kastaAttribute?->id,
            ],
            $strategy,
            $existing?->kasta_attribute_id,
            $kastaAttribute?->id
        );
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, array<string, mixed>>  $attributeOperationsByKey
     * @return array<string, mixed>
     */
    private function valueMappingOperation(
        FeedProfile $targetFeedProfile,
        array $row,
        string $strategy,
        array $attributeOperationsByKey
    ): array {
        $attributeKey = $this->snapshotService->attributeMappingKey($row);
        $attributeOperation = $attributeOperationsByKey[$attributeKey] ?? null;

        if ($attributeOperation === null || in_array($attributeOperation['action'], ['conflict', 'error'], true)) {
            return [
                'section' => 'value_mappings',
                'action' => 'conflict',
                'identity_key' => (string) ($row['identity_key'] ?? $this->snapshotService->valueMappingKey($row)),
                'label' => (string) data_get($row, 'identity.source_value.raw_value', 'Value mapping'),
                'reason' => 'Value mapping cannot be planned until the parent attribute mapping is compatible.',
                'current' => null,
                'incoming' => $row,
                'resolved' => [],
                'conflict' => true,
            ];
        }

        $resolvedAttributeMapping = null;

        if (($attributeOperation['resolved']['source_category_id'] ?? null) && ($attributeOperation['resolved']['source_attribute_id'] ?? null)) {
            $resolvedAttributeMapping = AttributeMapping::query()
                ->where('feed_profile_id', $targetFeedProfile->id)
                ->where('source_category_id', $attributeOperation['resolved']['source_category_id'])
                ->where('source_attribute_id', $attributeOperation['resolved']['source_attribute_id'])
                ->first();
        }

        $existing = ($resolvedAttributeMapping && filled(data_get($row, 'identity.source_value.raw_value')))
            ? ValueMapping::query()
                ->where('attribute_mapping_id', $resolvedAttributeMapping->id)
                ->where('source_raw_value', (string) data_get($row, 'identity.source_value.raw_value'))
                ->first()
            : null;
        $kastaAttribute = (($attributeOperation['resolved']['kasta_attribute_id'] ?? null))
            ? KastaAttribute::query()->find($attributeOperation['resolved']['kasta_attribute_id'])
            : null;
        $kastaAttributeValue = $this->resolveKastaAttributeValue(
            $kastaAttribute?->id,
            (array) data_get($row, 'target.kasta_attribute_value', [])
        );
        $incomingTargetValue = $kastaAttributeValue?->value ?: (string) data_get($row, 'target.kasta_attribute_value.value');

        return $this->mappingOperation(
            'value_mappings',
            (string) ($row['identity_key'] ?? $this->snapshotService->valueMappingKey($row)),
            (string) data_get($row, 'identity.source_value.raw_value', 'Value mapping'),
            $row,
            $existing ? [
                'target_value' => $existing->kastaAttributeValue?->value ?? $existing->target_value,
                'mapping_strategy' => $existing->mapping_strategy,
                'is_active' => (bool) $existing->is_active,
            ] : null,
            $attributeOperation['action'] !== 'conflict' && filled($incomingTargetValue),
            [
                'attribute_mapping_id' => $resolvedAttributeMapping?->id,
                'kasta_attribute_value_id' => $kastaAttributeValue?->id,
                'target_value' => $incomingTargetValue,
            ],
            $strategy,
            $existing?->target_value,
            $incomingTargetValue
        );
    }

    /**
     * @param  array<string, mixed>|null  $current
     * @param  array<string, mixed>  $resolved
     * @return array<string, mixed>
     */
    private function mappingOperation(
        string $section,
        string $identityKey,
        string $label,
        array $incoming,
        ?array $current,
        bool $resolvable,
        array $resolved,
        string $strategy,
        mixed $currentPrimary,
        mixed $incomingPrimary
    ): array {
        if (! $resolvable) {
            return [
                'section' => $section,
                'action' => 'conflict',
                'identity_key' => $identityKey,
                'label' => $label,
                'reason' => 'Target entities required for this mapping are missing.',
                'current' => $current,
                'incoming' => $incoming,
                'resolved' => $resolved,
                'conflict' => true,
            ];
        }

        if ($current === null) {
            return [
                'section' => $section,
                'action' => 'create',
                'identity_key' => $identityKey,
                'label' => $label,
                'reason' => 'Mapping is missing on target.',
                'current' => null,
                'incoming' => $incoming,
                'resolved' => $resolved,
            ];
        }

        if ($this->fingerprintService->fingerprint($current) === $this->fingerprintService->fingerprint($this->comparableIncoming($section, $incoming, $incomingPrimary, $resolved))) {
            return [
                'section' => $section,
                'action' => 'skip',
                'identity_key' => $identityKey,
                'label' => $label,
                'reason' => 'Mapping is unchanged.',
                'current' => $current,
                'incoming' => $incoming,
                'resolved' => $resolved,
            ];
        }

        $primaryChanged = (string) $currentPrimary !== (string) $incomingPrimary;

        if (! $primaryChanged || $strategy === PromotionRun::STRATEGY_OVERWRITE_TARGET) {
            return [
                'section' => $section,
                'action' => 'update',
                'identity_key' => $identityKey,
                'label' => $label,
                'reason' => $primaryChanged ? 'Target mapping will be overwritten.' : 'Mapping metadata differs.',
                'current' => $current,
                'incoming' => $incoming,
                'resolved' => $resolved,
            ];
        }

        return [
            'section' => $section,
            'action' => $strategy === PromotionRun::STRATEGY_SKIP_EXISTING_CONFLICTS ? 'skip' : 'conflict',
            'identity_key' => $identityKey,
            'label' => $label,
            'reason' => 'Target mapping points to a different destination.',
            'current' => $current,
            'incoming' => $incoming,
            'resolved' => $resolved,
            'conflict' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $sourceSettings
     * @return array{0:array<string, mixed>,1:list<string>,2:list<string>}
     */
    public function restoreSettingsForTarget(FeedProfile $targetFeedProfile, array $sourceSettings): array
    {
        $warnings = [];
        $errors = [];
        $settings = $sourceSettings;
        $excludedCategoryRefs = collect((array) ($settings['excluded_source_categories'] ?? []));
        $resolvedIds = [];

        foreach ($excludedCategoryRefs as $reference) {
            $category = $this->resolveSourceCategory($targetFeedProfile, (array) $reference);

            if (! $category instanceof SourceCategory) {
                $warnings[] = 'Some excluded source categories from the snapshot are missing on target and were skipped.';

                continue;
            }

            $resolvedIds[] = $category->id;
        }

        unset($settings['excluded_source_categories']);
        $settings['excluded_source_category_ids'] = array_values(array_unique($resolvedIds));
        $settings['disabled_export_category_ids'] = array_values(array_filter((array) ($settings['disabled_export_category_ids'] ?? [])));
        $settings['excluded_vendors'] = array_values(array_filter((array) ($settings['excluded_vendors'] ?? [])));
        $settings['forced_attribute_overrides'] = (array) ($settings['forced_attribute_overrides'] ?? []);
        $settings['forced_value_overrides'] = (array) ($settings['forced_value_overrides'] ?? []);
        $settings['hypercare'] = (array) ($settings['hypercare'] ?? []);

        if (! KastaCategory::query()->exists() || ! KastaAttribute::query()->exists()) {
            $errors[] = 'Kasta dictionaries are not ready on target, so promotion apply is blocked.';
        }

        return [$settings, array_values(array_unique($warnings)), array_values(array_unique($errors))];
    }

    /**
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    private function comparableIncoming(string $section, array $incoming, mixed $incomingPrimary, array $resolved): array
    {
        return match ($section) {
            'category_mappings' => [
                'kasta_category_id' => $incomingPrimary,
                'mapping_strategy' => $incoming['mapping_strategy'] ?? null,
                'is_active' => (bool) ($incoming['is_active'] ?? false),
            ],
            'attribute_mappings' => [
                'kasta_category_id' => $resolved['kasta_category_id'] ?? null,
                'kasta_attribute_id' => $incomingPrimary,
                'mapping_strategy' => $incoming['mapping_strategy'] ?? null,
                'is_required' => (bool) ($incoming['is_required'] ?? false),
                'default_value' => $incoming['default_value'] ?? null,
                'use_variant_value' => (bool) ($incoming['use_variant_value'] ?? false),
            ],
            default => [
                'target_value' => $incomingPrimary,
                'mapping_strategy' => $incoming['mapping_strategy'] ?? null,
                'is_active' => (bool) ($incoming['is_active'] ?? false),
            ],
        };
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $target
     * @return array<string, mixed>
     */
    private function configDiff(array $source, array $target): array
    {
        $changes = [];
        $keys = array_values(array_unique(array_merge(array_keys($source), array_keys($target))));

        foreach ($keys as $key) {
            $sourceExists = array_key_exists($key, $source);
            $targetExists = array_key_exists($key, $target);

            if (! $targetExists) {
                $changes[] = ['key' => $key, 'type' => 'missing_in_target', 'source' => $source[$key] ?? null, 'target' => null];

                continue;
            }

            if (! $sourceExists) {
                $changes[] = ['key' => $key, 'type' => 'extra_in_target', 'source' => null, 'target' => $target[$key] ?? null];

                continue;
            }

            if ($this->fingerprintService->fingerprint($source[$key]) !== $this->fingerprintService->fingerprint($target[$key])) {
                $changes[] = ['key' => $key, 'type' => 'changed', 'source' => $source[$key], 'target' => $target[$key]];
            }
        }

        return [
            'changes' => $changes,
            'summary' => [
                'missing_in_target' => collect($changes)->where('type', 'missing_in_target')->count(),
                'extra_in_target' => collect($changes)->where('type', 'extra_in_target')->count(),
                'changed' => collect($changes)->where('type', 'changed')->count(),
                'changes_total' => count($changes),
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $sourceRows
     * @param  list<array<string, mixed>>  $targetRows
     * @return array<string, mixed>
     */
    private function mappingDiff(array $sourceRows, array $targetRows, callable $keyResolver): array
    {
        $source = collect($sourceRows)->mapWithKeys(fn (array $row) => [$keyResolver($row) => $row]);
        $target = collect($targetRows)->mapWithKeys(fn (array $row) => [$keyResolver($row) => $row]);
        $missing = [];
        $changed = [];
        $extra = [];

        foreach ($source as $key => $row) {
            if (! $target->has($key)) {
                $missing[] = ['identity_key' => $key, 'source' => $row];

                continue;
            }

            if ($this->fingerprintService->fingerprint($row) !== $this->fingerprintService->fingerprint($target->get($key))) {
                $changed[] = ['identity_key' => $key, 'source' => $row, 'target' => $target->get($key)];
            }
        }

        foreach ($target as $key => $row) {
            if (! $source->has($key)) {
                $extra[] = ['identity_key' => $key, 'target' => $row];
            }
        }

        return [
            'missing_in_target' => $missing,
            'changed' => $changed,
            'extra_in_target' => $extra,
            'summary' => [
                'missing_in_target' => count($missing),
                'changed' => count($changed),
                'extra_in_target' => count($extra),
                'changes_total' => count($missing) + count($changed) + count($extra),
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $sourceRows
     * @param  list<array<string, mixed>>  $targetRows
     * @return array<string, mixed>
     */
    private function dictionaryDiff(array $sourceRows, array $targetRows): array
    {
        $source = collect($sourceRows)->keyBy('type');
        $target = collect($targetRows)->keyBy('type');
        $changes = [];

        foreach ($source as $type => $row) {
            if (! $target->has($type)) {
                $changes[] = ['type' => $type, 'status' => 'missing_in_target', 'source' => $row, 'target' => null];

                continue;
            }

            if (($row['checksum'] ?? null) !== ($target[$type]['checksum'] ?? null)) {
                $changes[] = ['type' => $type, 'status' => 'checksum_mismatch', 'source' => $row, 'target' => $target[$type]];
            }
        }

        foreach ($target as $type => $row) {
            if (! $source->has($type)) {
                $changes[] = ['type' => $type, 'status' => 'extra_in_target', 'source' => null, 'target' => $row];
            }
        }

        return [
            'changes' => $changes,
            'summary' => [
                'changes_total' => count($changes),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $operation
     * @param  list<string>  $conflicts
     * @param  list<string>  $warnings
     * @param  list<string>  $errors
     */
    private function collectOperation(array $operation, array &$conflicts, array &$warnings, array &$errors): void
    {
        if (($operation['action'] ?? null) === 'conflict' || (($operation['conflict'] ?? false) === true && ($operation['action'] ?? null) !== 'update')) {
            $conflicts[] = (string) ($operation['label'].' - '.$operation['reason']);
        }

        if (filled($operation['warning'] ?? null)) {
            $warnings[] = (string) $operation['warning'];
        }

        if (filled($operation['error'] ?? null)) {
            $errors[] = (string) $operation['error'];
        }
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $operations
     */
    private function countOperations(array $operations, string $action): int
    {
        return collect($operations)
            ->flatten(1)
            ->where('action', $action)
            ->count();
    }

    public function resolveSourceCategory(FeedProfile $feedProfile, array $reference): ?SourceCategory
    {
        if (! collect(['external_id', 'rz_id', 'full_path', 'name'])->contains(fn ($field) => filled($reference[$field] ?? null))) {
            return null;
        }

        return SourceCategory::query()
            ->where('source_connection_id', $feedProfile->source_connection_id)
            ->where(function ($query) use ($reference): void {
                foreach (['external_id', 'rz_id', 'full_path', 'name'] as $field) {
                    if (filled($reference[$field] ?? null)) {
                        $query->orWhere($field, $reference[$field]);
                    }
                }
            })
            ->first();
    }

    public function resolveSourceAttribute(FeedProfile $feedProfile, array $reference): ?SourceAttribute
    {
        if (! collect(['code', 'name'])->contains(fn ($field) => filled($reference[$field] ?? null))) {
            return null;
        }

        return SourceAttribute::query()
            ->where('source_connection_id', $feedProfile->source_connection_id)
            ->where(function ($query) use ($reference): void {
                foreach (['code', 'name'] as $field) {
                    if (filled($reference[$field] ?? null)) {
                        $query->orWhere($field, $reference[$field]);
                    }
                }
            })
            ->first();
    }

    public function resolveKastaCategory(array $reference): ?KastaCategory
    {
        if (! collect(['external_id', 'rz_id', 'full_path', 'name'])->contains(fn ($field) => filled($reference[$field] ?? null))) {
            return null;
        }

        return KastaCategory::query()
            ->where(function ($query) use ($reference): void {
                foreach (['external_id', 'rz_id', 'full_path', 'name'] as $field) {
                    if (filled($reference[$field] ?? null)) {
                        $query->orWhere($field, $reference[$field]);
                    }
                }
            })
            ->first();
    }

    public function resolveKastaAttribute(?int $kastaCategoryId, array $reference): ?KastaAttribute
    {
        if ($kastaCategoryId === null || ! collect(['code', 'external_id', 'name'])->contains(fn ($field) => filled($reference[$field] ?? null))) {
            return null;
        }

        return KastaAttribute::query()
            ->where('kasta_category_id', $kastaCategoryId)
            ->where(function ($query) use ($reference): void {
                foreach (['code', 'external_id', 'name'] as $field) {
                    if (filled($reference[$field] ?? null)) {
                        $query->orWhere($field, $reference[$field]);
                    }
                }
            })
            ->first();
    }

    public function resolveKastaAttributeValue(?int $kastaAttributeId, array $reference): ?KastaAttributeValue
    {
        if ($kastaAttributeId === null || ! collect(['external_id', 'value'])->contains(fn ($field) => filled($reference[$field] ?? null))) {
            return null;
        }

        return KastaAttributeValue::query()
            ->where('kasta_attribute_id', $kastaAttributeId)
            ->where(function ($query) use ($reference): void {
                foreach (['external_id', 'value'] as $field) {
                    if (filled($reference[$field] ?? null)) {
                        $query->orWhere($field, $reference[$field]);
                    }
                }
            })
            ->first();
    }
}
