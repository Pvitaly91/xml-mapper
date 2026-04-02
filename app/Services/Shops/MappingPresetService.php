<?php

namespace App\Services\Shops;

use App\Models\AttributeMapping;
use App\Models\CategoryMapping;
use App\Models\FeedProfile;
use App\Models\KastaAttribute;
use App\Models\KastaAttributeValue;
use App\Models\KastaCategory;
use App\Models\SourceAttribute;
use App\Models\SourceAttributeValue;
use App\Models\SourceCategory;
use App\Models\ValueMapping;
use App\Support\Canonicalizer;
use Illuminate\Support\Facades\DB;

class MappingPresetService
{
    /**
     * @return array<string, mixed>
     */
    public function export(FeedProfile $feedProfile): array
    {
        $feedProfile->loadMissing([
            'shop',
            'categoryMappings.sourceCategory',
            'categoryMappings.kastaCategory',
            'attributeMappings.sourceCategory',
            'attributeMappings.sourceAttribute',
            'attributeMappings.kastaCategory',
            'attributeMappings.kastaAttribute',
            'valueMappings.attributeMapping.sourceAttribute',
            'valueMappings.kastaAttributeValue',
            'valueMappings.sourceAttributeValue',
        ]);

        return [
            'schema_version' => 1,
            'exported_at' => now()->toIso8601String(),
            'shop' => [
                'name' => $feedProfile->shop?->name,
                'slug' => $feedProfile->shop?->slug,
            ],
            'feed_profile' => [
                'name' => $feedProfile->name,
                'code' => $feedProfile->code,
                'settings' => $feedProfile->exportSettings(),
            ],
            'category_mappings' => $feedProfile->categoryMappings->map(fn (CategoryMapping $mapping) => [
                'source_category' => [
                    'external_id' => $mapping->sourceCategory?->external_id,
                    'rz_id' => $mapping->sourceCategory?->rz_id,
                    'name' => $mapping->sourceCategory?->name,
                    'full_path' => $mapping->sourceCategory?->full_path,
                ],
                'kasta_category' => [
                    'external_id' => $mapping->kastaCategory?->external_id,
                    'rz_id' => $mapping->kastaCategory?->rz_id,
                    'name' => $mapping->kastaCategory?->name,
                    'full_path' => $mapping->kastaCategory?->full_path,
                ],
                'mapping_strategy' => $mapping->mapping_strategy,
                'is_active' => $mapping->is_active,
            ])->values()->all(),
            'attribute_mappings' => $feedProfile->attributeMappings->map(fn (AttributeMapping $mapping) => [
                'source_category' => [
                    'external_id' => $mapping->sourceCategory?->external_id,
                    'rz_id' => $mapping->sourceCategory?->rz_id,
                    'name' => $mapping->sourceCategory?->name,
                    'full_path' => $mapping->sourceCategory?->full_path,
                ],
                'source_attribute' => [
                    'code' => $mapping->sourceAttribute?->code,
                    'name' => $mapping->sourceAttribute?->name,
                ],
                'kasta_category' => [
                    'external_id' => $mapping->kastaCategory?->external_id,
                    'rz_id' => $mapping->kastaCategory?->rz_id,
                    'name' => $mapping->kastaCategory?->name,
                ],
                'kasta_attribute' => [
                    'code' => $mapping->kastaAttribute?->code,
                    'external_id' => $mapping->kastaAttribute?->external_id,
                    'name' => $mapping->kastaAttribute?->name,
                ],
                'mapping_strategy' => $mapping->mapping_strategy,
                'is_required' => $mapping->is_required,
                'default_value' => $mapping->default_value,
                'use_variant_value' => $mapping->use_variant_value,
            ])->values()->all(),
            'value_mappings' => $feedProfile->valueMappings->map(fn (ValueMapping $mapping) => [
                'source_attribute' => [
                    'code' => $mapping->attributeMapping?->sourceAttribute?->code,
                    'name' => $mapping->attributeMapping?->sourceAttribute?->name,
                ],
                'source_value' => [
                    'raw_value' => $mapping->source_raw_value,
                    'normalized_value' => $mapping->normalized_source_value,
                ],
                'kasta_attribute_value' => [
                    'value' => $mapping->kastaAttributeValue?->value ?? $mapping->target_value,
                    'external_id' => $mapping->kastaAttributeValue?->external_id,
                ],
                'mapping_strategy' => $mapping->mapping_strategy,
                'is_active' => $mapping->is_active,
            ])->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $preset
     * @return array<string, mixed>
     */
    public function previewImport(FeedProfile $feedProfile, array $preset, string $collisionStrategy): array
    {
        return $this->plan($feedProfile, $preset, $collisionStrategy);
    }

    /**
     * @param  array<string, mixed>  $preset
     * @return array<string, mixed>
     */
    public function import(FeedProfile $feedProfile, array $preset, string $collisionStrategy): array
    {
        $plan = $this->plan($feedProfile, $preset, $collisionStrategy);

        DB::transaction(function () use ($feedProfile, $preset, $collisionStrategy, &$plan): void {
            foreach ($plan['operations']['category_mappings'] as $operation) {
                $this->applyCategoryMappingOperation($feedProfile, $operation);
            }

            foreach ($plan['operations']['attribute_mappings'] as $operation) {
                $this->applyAttributeMappingOperation($feedProfile, $operation);
            }

            $valuePlan = $this->plan($feedProfile->fresh(), ['value_mappings' => $preset['value_mappings'] ?? []], $collisionStrategy);
            $plan['operations']['value_mappings'] = $valuePlan['operations']['value_mappings'];
            $plan['summary']['value_mappings'] = $valuePlan['summary']['value_mappings'];

            foreach ($valuePlan['operations']['value_mappings'] as $operation) {
                $this->applyValueMappingOperation($feedProfile, $operation);
            }
        });

        return $plan;
    }

    /**
     * @param  array<string, mixed>  $preset
     * @return array<string, mixed>
     */
    private function plan(FeedProfile $feedProfile, array $preset, string $collisionStrategy): array
    {
        $operations = [
            'category_mappings' => [],
            'attribute_mappings' => [],
            'value_mappings' => [],
        ];
        $summary = [
            'category_mappings' => ['create' => 0, 'update' => 0, 'skip' => 0, 'collisions' => 0, 'unresolved' => 0],
            'attribute_mappings' => ['create' => 0, 'update' => 0, 'skip' => 0, 'collisions' => 0, 'unresolved' => 0],
            'value_mappings' => ['create' => 0, 'update' => 0, 'skip' => 0, 'collisions' => 0, 'unresolved' => 0],
        ];

        foreach (($preset['category_mappings'] ?? []) as $row) {
            $sourceCategory = $this->resolveSourceCategory($feedProfile, $row['source_category'] ?? []);
            $kastaCategory = $this->resolveKastaCategory($row['kasta_category'] ?? []);
            $existing = $sourceCategory
                ? CategoryMapping::query()
                    ->where('feed_profile_id', $feedProfile->id)
                    ->where('source_category_id', $sourceCategory->id)
                    ->first()
                : null;
            $operation = (! $sourceCategory || ! $kastaCategory)
                ? 'unresolved'
                : $this->operationForExisting($existing, $kastaCategory->id, $collisionStrategy);
            $operations['category_mappings'][] = compact('row', 'sourceCategory', 'kastaCategory', 'existing', 'operation');
            $summary['category_mappings'][$operation]++;
        }

        foreach (($preset['attribute_mappings'] ?? []) as $row) {
            $sourceCategory = $this->resolveSourceCategory($feedProfile, $row['source_category'] ?? []);
            $sourceAttribute = $this->resolveSourceAttribute($feedProfile, $row['source_attribute'] ?? []);
            $kastaCategory = $this->resolveKastaCategory($row['kasta_category'] ?? []);
            $kastaAttribute = $this->resolveKastaAttribute($kastaCategory?->id, $row['kasta_attribute'] ?? []);
            $existing = ($sourceCategory && $sourceAttribute)
                ? AttributeMapping::query()
                    ->where('feed_profile_id', $feedProfile->id)
                    ->where('source_category_id', $sourceCategory->id)
                    ->where('source_attribute_id', $sourceAttribute->id)
                    ->first()
                : null;
            $operation = (! $sourceCategory || ! $sourceAttribute || ! $kastaCategory || ! $kastaAttribute)
                ? 'unresolved'
                : $this->operationForExisting($existing, $kastaAttribute->id, $collisionStrategy);
            $operations['attribute_mappings'][] = compact('row', 'sourceCategory', 'sourceAttribute', 'kastaCategory', 'kastaAttribute', 'existing', 'operation');
            $summary['attribute_mappings'][$operation]++;
        }

        $plannedAttributeMappings = collect($operations['attribute_mappings'])
            ->filter(fn (array $operation): bool => in_array($operation['operation'], ['create', 'update', 'skip'], true))
            ->filter(fn (array $operation): bool => $operation['sourceAttribute'] !== null && $operation['kastaAttribute'] !== null)
            ->mapWithKeys(fn (array $operation) => [$operation['sourceAttribute']->id => $operation])
            ->all();

        foreach (($preset['value_mappings'] ?? []) as $row) {
            $sourceAttribute = $this->resolveSourceAttribute($feedProfile, $row['source_attribute'] ?? []);
            $attributeMapping = $sourceAttribute
                ? AttributeMapping::query()
                    ->where('feed_profile_id', $feedProfile->id)
                    ->where('source_attribute_id', $sourceAttribute->id)
                    ->first()
                : null;
            $plannedAttributeMapping = ($sourceAttribute && ! $attributeMapping)
                ? ($plannedAttributeMappings[$sourceAttribute->id] ?? null)
                : null;
            $sourceAttributeValue = ($sourceAttribute && isset($row['source_value']))
                ? $this->resolveSourceAttributeValue($sourceAttribute, $row['source_value'])
                : null;
            $kastaAttributeId = $attributeMapping?->kastaAttribute?->id
                ?? $plannedAttributeMapping['kastaAttribute']?->id
                ?? null;
            $kastaAttributeValue = $kastaAttributeId
                ? $this->resolveKastaAttributeValue($kastaAttributeId, $row['kasta_attribute_value'] ?? [])
                : null;
            $existing = ($attributeMapping && isset($row['source_value']['raw_value']))
                ? ValueMapping::query()
                    ->where('attribute_mapping_id', $attributeMapping->id)
                    ->where('source_raw_value', $row['source_value']['raw_value'])
                    ->first()
                : null;
            $targetValue = $kastaAttributeValue?->value ?? ($row['kasta_attribute_value']['value'] ?? null);
            $resolvedAttributeMapping = $attributeMapping ?: ($plannedAttributeMapping !== null ? new AttributeMapping([
                'source_attribute_id' => $sourceAttribute?->id,
                'kasta_attribute_id' => $plannedAttributeMapping['kastaAttribute']->id,
            ]) : null);
            $operation = (! $resolvedAttributeMapping || blank($row['source_value']['raw_value'] ?? null) || blank($targetValue))
                ? 'unresolved'
                : $this->operationForExisting($existing, $targetValue, $collisionStrategy, 'target_value');
            $operations['value_mappings'][] = compact(
                'row',
                'attributeMapping',
                'plannedAttributeMapping',
                'sourceAttributeValue',
                'kastaAttributeValue',
                'existing',
                'operation',
                'targetValue'
            );
            $summary['value_mappings'][$operation]++;
        }

        return [
            'collision_strategy' => $collisionStrategy,
            'summary' => $summary,
            'operations' => $operations,
        ];
    }

    /**
     * @param  array<string, mixed>  $operation
     */
    private function applyCategoryMappingOperation(FeedProfile $feedProfile, array $operation): void
    {
        if (! in_array($operation['operation'], ['create', 'update'], true)) {
            return;
        }

        $payload = [
            'shop_id' => $feedProfile->shop_id,
            'source_connection_id' => $feedProfile->source_connection_id,
            'feed_profile_id' => $feedProfile->id,
            'source_category_id' => $operation['sourceCategory']->id,
            'kasta_category_id' => $operation['kastaCategory']->id,
            'rz_id' => $operation['row']['source_category']['rz_id'] ?? $operation['row']['kasta_category']['rz_id'] ?? null,
            'mapping_strategy' => $operation['row']['mapping_strategy'] ?? CategoryMapping::STRATEGY_MANUAL,
            'is_active' => (bool) ($operation['row']['is_active'] ?? true),
        ];

        if ($operation['existing']) {
            $operation['existing']->update($payload);

            return;
        }

        CategoryMapping::create($payload);
    }

    /**
     * @param  array<string, mixed>  $operation
     */
    private function applyAttributeMappingOperation(FeedProfile $feedProfile, array $operation): void
    {
        if (! in_array($operation['operation'], ['create', 'update'], true)) {
            return;
        }

        $payload = [
            'shop_id' => $feedProfile->shop_id,
            'source_connection_id' => $feedProfile->source_connection_id,
            'feed_profile_id' => $feedProfile->id,
            'source_category_id' => $operation['sourceCategory']->id,
            'source_attribute_id' => $operation['sourceAttribute']->id,
            'kasta_category_id' => $operation['kastaCategory']->id,
            'kasta_attribute_id' => $operation['kastaAttribute']->id,
            'mapping_strategy' => $operation['row']['mapping_strategy'] ?? AttributeMapping::STRATEGY_MANUAL,
            'is_required' => (bool) ($operation['row']['is_required'] ?? false),
            'default_value' => $operation['row']['default_value'] ?? null,
            'use_variant_value' => (bool) ($operation['row']['use_variant_value'] ?? false),
        ];

        if ($operation['existing']) {
            $operation['existing']->update($payload);

            return;
        }

        AttributeMapping::create($payload);
    }

    /**
     * @param  array<string, mixed>  $operation
     */
    private function applyValueMappingOperation(FeedProfile $feedProfile, array $operation): void
    {
        if (! in_array($operation['operation'], ['create', 'update'], true)) {
            return;
        }

        $attributeMapping = $operation['attributeMapping']
            ?: AttributeMapping::query()
                ->where('feed_profile_id', $feedProfile->id)
                ->where('source_attribute_id', $operation['plannedAttributeMapping']['sourceAttribute']->id)
                ->first();

        if (! $attributeMapping) {
            return;
        }

        $payload = [
            'shop_id' => $feedProfile->shop_id,
            'feed_profile_id' => $feedProfile->id,
            'attribute_mapping_id' => $attributeMapping->id,
            'source_attribute_value_id' => $operation['sourceAttributeValue']?->id,
            'kasta_attribute_value_id' => $operation['kastaAttributeValue']?->id,
            'source_raw_value' => $operation['row']['source_value']['raw_value'] ?? null,
            'normalized_source_value' => $operation['row']['source_value']['normalized_value'] ?? Canonicalizer::normalizeText(mb_strtolower((string) ($operation['row']['source_value']['raw_value'] ?? ''))),
            'target_value' => $operation['targetValue'],
            'mapping_strategy' => $operation['row']['mapping_strategy'] ?? ValueMapping::STRATEGY_MANUAL,
            'is_active' => true,
        ];

        if ($operation['existing']) {
            $operation['existing']->update($payload);

            return;
        }

        ValueMapping::create($payload);
    }

    /**
     * @param  array<string, mixed>  $target
     */
    private function resolveSourceCategory(FeedProfile $feedProfile, array $target): ?SourceCategory
    {
        if (! collect(['external_id', 'rz_id', 'full_path', 'name'])->contains(fn (string $field) => filled($target[$field] ?? null))) {
            return null;
        }

        return SourceCategory::query()
            ->where('source_connection_id', $feedProfile->source_connection_id)
            ->where(function ($query) use ($target): void {
                foreach (['external_id', 'rz_id', 'full_path', 'name'] as $field) {
                    if (filled($target[$field] ?? null)) {
                        $query->orWhere($field, $target[$field]);
                    }
                }
            })
            ->first();
    }

    /**
     * @param  array<string, mixed>  $target
     */
    private function resolveKastaCategory(array $target): ?KastaCategory
    {
        if (! collect(['external_id', 'rz_id', 'name'])->contains(fn (string $field) => filled($target[$field] ?? null))) {
            return null;
        }

        return KastaCategory::query()
            ->where(function ($query) use ($target): void {
                foreach (['external_id', 'rz_id', 'name'] as $field) {
                    if (filled($target[$field] ?? null)) {
                        $query->orWhere($field, $target[$field]);
                    }
                }
            })
            ->first();
    }

    /**
     * @param  array<string, mixed>  $target
     */
    private function resolveSourceAttribute(FeedProfile $feedProfile, array $target): ?SourceAttribute
    {
        if (! collect(['code', 'name'])->contains(fn (string $field) => filled($target[$field] ?? null))) {
            return null;
        }

        return SourceAttribute::query()
            ->where('source_connection_id', $feedProfile->source_connection_id)
            ->where(function ($query) use ($target): void {
                foreach (['code', 'name'] as $field) {
                    if (filled($target[$field] ?? null)) {
                        $query->orWhere($field, $target[$field]);
                    }
                }
            })
            ->first();
    }

    /**
     * @param  array<string, mixed>  $target
     */
    private function resolveKastaAttribute(?int $kastaCategoryId, array $target): ?KastaAttribute
    {
        if ($kastaCategoryId === null || ! collect(['code', 'external_id', 'name'])->contains(fn (string $field) => filled($target[$field] ?? null))) {
            return null;
        }

        return KastaAttribute::query()
            ->where('kasta_category_id', $kastaCategoryId)
            ->where(function ($query) use ($target): void {
                foreach (['code', 'external_id', 'name'] as $field) {
                    if (filled($target[$field] ?? null)) {
                        $query->orWhere($field, $target[$field]);
                    }
                }
            })
            ->first();
    }

    /**
     * @param  array<string, mixed>  $target
     */
    private function resolveSourceAttributeValue(SourceAttribute $attribute, array $target): ?SourceAttributeValue
    {
        if (! collect(['raw_value', 'normalized_value'])->contains(fn (string $field) => filled($target[$field] ?? null))) {
            return null;
        }

        return SourceAttributeValue::query()
            ->where('source_attribute_id', $attribute->id)
            ->where(function ($query) use ($target): void {
                foreach (['raw_value', 'normalized_value'] as $field) {
                    if (filled($target[$field] ?? null)) {
                        $query->orWhere($field, $target[$field]);
                    }
                }
            })
            ->first();
    }

    /**
     * @param  array<string, mixed>  $target
     */
    private function resolveKastaAttributeValue(?int $kastaAttributeId, array $target): ?KastaAttributeValue
    {
        if ($kastaAttributeId === null || ! collect(['external_id', 'value'])->contains(fn (string $field) => filled($target[$field] ?? null))) {
            return null;
        }

        return KastaAttributeValue::query()
            ->whereHas('kastaAttribute', fn ($query) => $query->whereKey($kastaAttributeId))
            ->where(function ($query) use ($target): void {
                foreach (['external_id', 'value'] as $field) {
                    if (filled($target[$field] ?? null)) {
                        $query->orWhere($field, $target[$field]);
                    }
                }
            })
            ->first();
    }

    private function operationForExisting($existing, $incomingTarget, string $collisionStrategy, string $targetField = 'kasta_attribute_id'): string
    {
        if ($existing === null) {
            return $incomingTarget === null ? 'unresolved' : 'create';
        }

        if ($incomingTarget === null) {
            return 'unresolved';
        }

        $current = $targetField === 'target_value'
            ? ($existing->kastaAttributeValue?->value ?? $existing->target_value)
            : ($existing->{$targetField} ?? null);

        if ((string) $current === (string) $incomingTarget) {
            return 'skip';
        }

        return match ($collisionStrategy) {
            'overwrite_existing' => 'update',
            'merge_if_safe' => 'collisions',
            default => 'skip',
        };
    }
}
