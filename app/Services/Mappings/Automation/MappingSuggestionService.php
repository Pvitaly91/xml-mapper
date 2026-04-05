<?php

namespace App\Services\Mappings\Automation;

use App\Models\AttributeMapping;
use App\Models\CategoryMapping;
use App\Models\FeedProfile;
use App\Models\KastaAttribute;
use App\Models\KastaAttributeValue;
use App\Models\KastaCategory;
use App\Models\MappingRule;
use App\Models\SourceAttribute;
use App\Models\SourceCategory;
use App\Models\SourceVariant;
use App\Models\ValueMapping;
use App\Support\Canonicalizer;

class MappingSuggestionService
{
    public function __construct(
        private readonly MappingRuleEngineService $ruleEngine,
    ) {}

    /**
     * @param  array<string, mixed>  $scope
     * @return list<array<string, mixed>>
     */
    public function suggest(FeedProfile $feedProfile, string $type, array $scope = []): array
    {
        return match ($type) {
            MappingRule::TYPE_CATEGORY => $this->categorySuggestions($feedProfile, $scope),
            MappingRule::TYPE_ATTRIBUTE => $this->attributeSuggestions($feedProfile, $scope),
            MappingRule::TYPE_VALUE => $this->valueSuggestions($feedProfile, $scope),
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $scope
     * @return list<array<string, mixed>>
     */
    public function categorySuggestions(FeedProfile $feedProfile, array $scope = []): array
    {
        $targets = KastaCategory::query()
            ->where('is_active', true)
            ->orderBy('full_path')
            ->get()
            ->map(fn (KastaCategory $category) => [
                'id' => $category->id,
                'reference' => $category->external_id ?: $category->rz_id,
                'label' => $category->full_path ?: $category->name,
                'normalized_candidates' => Canonicalizer::uniqueNonEmpty([
                    Canonicalizer::normalizeKey($category->name),
                    Canonicalizer::normalizeKey((string) $category->full_path),
                    Canonicalizer::normalizeKey((string) $category->rz_id),
                ]),
                'rz_id' => $category->rz_id,
            ])
            ->all();

        $existing = CategoryMapping::query()
            ->where('feed_profile_id', $feedProfile->id)
            ->where('is_active', true)
            ->pluck('kasta_category_id', 'source_category_id');

        $unlockCounts = SourceVariant::query()
            ->selectRaw('source_products.source_category_id as category_id, count(source_variants.id) as aggregate')
            ->join('source_products', 'source_products.id', '=', 'source_variants.source_product_id')
            ->where('source_variants.shop_id', $feedProfile->shop_id)
            ->where('source_variants.source_connection_id', $feedProfile->source_connection_id)
            ->groupBy('source_products.source_category_id')
            ->pluck('aggregate', 'category_id');

        return SourceCategory::query()
            ->where('source_connection_id', $feedProfile->source_connection_id)
            ->when($scope['source_category_id'] ?? null, fn ($query, $id) => $query->whereKey($id))
            ->orderBy('full_path')
            ->get()
            ->reject(fn (SourceCategory $category) => $existing->has($category->id))
            ->map(function (SourceCategory $category) use ($feedProfile, $targets, $unlockCounts): ?array {
                $source = $this->sourceDescriptor(
                    $category->id,
                    $category->full_path ?: $category->name,
                    [$category->name, $category->full_path, $category->rz_id],
                    ['rz_id' => $category->rz_id]
                );
                $context = ['source_category_path' => $category->full_path ?: $category->name];
                $matches = $this->ruleEngine->explicitMatches($feedProfile, MappingRule::TYPE_CATEGORY, $source, $targets, $context);

                foreach ($targets as $target) {
                    if (filled($category->rz_id) && filled($target['rz_id']) && (string) $category->rz_id === (string) $target['rz_id']) {
                        $matches[] = $this->ruleEngine->heuristicCandidate(
                            MappingRule::MATCH_RZ_ID,
                            $target,
                            ['rz_id' => $category->rz_id],
                            $source['label']
                        );
                    }

                    if (collect($target['normalized_candidates'])->intersect($source['normalized_candidates'])->isNotEmpty()) {
                        $matches[] = $this->ruleEngine->heuristicCandidate(
                            MappingRule::MATCH_EXACT,
                            $target,
                            ['shared_candidates' => array_values(array_intersect($target['normalized_candidates'], $source['normalized_candidates']))],
                            $source['label']
                        );
                    }
                }

                $best = $this->ruleEngine->sortMatches($matches)[0] ?? null;

                return $best ? array_merge($this->baseSuggestion($best, $source, MappingRule::TYPE_CATEGORY), [
                    'source_category_id' => $category->id,
                    'unlock_estimate' => (int) ($unlockCounts[$category->id] ?? 0),
                ]) : null;
            })
            ->filter()
            ->sortByDesc('unlock_estimate')
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $scope
     * @return list<array<string, mixed>>
     */
    public function attributeSuggestions(FeedProfile $feedProfile, array $scope = []): array
    {
        $sourceAttributes = SourceAttribute::query()
            ->where('source_connection_id', $feedProfile->source_connection_id)
            ->orderBy('name')
            ->get();

        $itemsByCategory = SourceVariant::query()
            ->selectRaw('source_products.source_category_id as category_id, count(source_variants.id) as aggregate')
            ->join('source_products', 'source_products.id', '=', 'source_variants.source_product_id')
            ->where('source_variants.shop_id', $feedProfile->shop_id)
            ->where('source_variants.source_connection_id', $feedProfile->source_connection_id)
            ->groupBy('source_products.source_category_id')
            ->pluck('aggregate', 'category_id');

        return CategoryMapping::query()
            ->with('sourceCategory')
            ->where('feed_profile_id', $feedProfile->id)
            ->where('is_active', true)
            ->when($scope['source_category_id'] ?? null, fn ($query, $id) => $query->where('source_category_id', $id))
            ->orderBy('source_category_id')
            ->get()
            ->flatMap(function (CategoryMapping $categoryMapping) use ($feedProfile, $sourceAttributes, $scope, $itemsByCategory): array {
                $targets = KastaAttribute::query()
                    ->where('kasta_category_id', $categoryMapping->kasta_category_id)
                    ->orderBy('sort_order')
                    ->orderBy('name')
                    ->get()
                    ->map(fn (KastaAttribute $attribute) => [
                        'id' => $attribute->id,
                        'reference' => $attribute->code ?: $attribute->external_id,
                        'label' => $attribute->name,
                        'normalized_candidates' => Canonicalizer::uniqueNonEmpty([
                            Canonicalizer::normalizeKey($attribute->name),
                            Canonicalizer::normalizeKey($attribute->code),
                            Canonicalizer::normalizeKey((string) $attribute->external_id),
                        ]),
                    ])
                    ->all();

                $existingMappings = AttributeMapping::query()
                    ->where('feed_profile_id', $feedProfile->id)
                    ->where('source_category_id', $categoryMapping->source_category_id)
                    ->get();

                $mappedSource = $existingMappings->pluck('source_attribute_id')->all();
                $mappedTarget = $existingMappings->pluck('kasta_attribute_id')->filter()->all();
                $suggestions = [];

                foreach ($sourceAttributes as $sourceAttribute) {
                    if (($scope['source_attribute_id'] ?? null) && (int) $scope['source_attribute_id'] !== (int) $sourceAttribute->id) {
                        continue;
                    }

                    if (in_array($sourceAttribute->id, $mappedSource, true)) {
                        continue;
                    }

                    $source = $this->sourceDescriptor(
                        $sourceAttribute->id,
                        $sourceAttribute->name,
                        [$sourceAttribute->name, $sourceAttribute->code],
                        ['code' => $sourceAttribute->code]
                    );

                    $context = [
                        'source_category_path' => $categoryMapping->sourceCategory?->full_path ?: $categoryMapping->sourceCategory?->name,
                        'source_attribute_code' => $sourceAttribute->code,
                    ];

                    $matches = $this->ruleEngine->explicitMatches($feedProfile, MappingRule::TYPE_ATTRIBUTE, $source, $targets, $context);

                    foreach ($targets as $target) {
                        if (in_array($target['id'], $mappedTarget, true)) {
                            continue;
                        }

                        if (collect($target['normalized_candidates'])->intersect($source['normalized_candidates'])->isNotEmpty()) {
                            $matches[] = $this->ruleEngine->heuristicCandidate(
                                MappingRule::MATCH_EXACT,
                                $target,
                                ['shared_candidates' => array_values(array_intersect($target['normalized_candidates'], $source['normalized_candidates']))],
                                $source['label']
                            );
                        }
                    }

                    $best = $this->ruleEngine->sortMatches($matches)[0] ?? null;

                    if ($best === null || in_array($best['target']['id'], $mappedTarget, true)) {
                        continue;
                    }

                    $suggestions[] = array_merge($this->baseSuggestion($best, $source, MappingRule::TYPE_ATTRIBUTE), [
                        'source_category_id' => $categoryMapping->source_category_id,
                        'source_category_label' => $categoryMapping->sourceCategory?->full_path ?: $categoryMapping->sourceCategory?->name,
                        'unlock_estimate' => (int) ($itemsByCategory[$categoryMapping->source_category_id] ?? 0),
                    ]);
                    $mappedTarget[] = $best['target']['id'];
                }

                return $suggestions;
            })
            ->sortByDesc('unlock_estimate')
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $scope
     * @return list<array<string, mixed>>
     */
    public function valueSuggestions(FeedProfile $feedProfile, array $scope = []): array
    {
        return AttributeMapping::query()
            ->with(['sourceAttribute.values', 'kastaAttribute.values', 'sourceCategory'])
            ->where('feed_profile_id', $feedProfile->id)
            ->when($scope['attribute_mapping_id'] ?? null, fn ($query, $id) => $query->whereKey($id))
            ->orderBy('id')
            ->get()
            ->flatMap(function (AttributeMapping $attributeMapping) use ($feedProfile, $scope): array {
                if ($attributeMapping->sourceAttribute === null || $attributeMapping->kastaAttribute === null) {
                    return [];
                }

                $targets = $attributeMapping->kastaAttribute->values
                    ->map(fn (KastaAttributeValue $value) => [
                        'id' => $value->id,
                        'reference' => $value->external_id ?: $value->value,
                        'label' => $value->value,
                        'normalized_candidates' => Canonicalizer::uniqueNonEmpty([
                            Canonicalizer::normalizeKey($value->value),
                            Canonicalizer::normalizeKey((string) $value->normalized_value),
                            Canonicalizer::normalizeKey((string) $value->external_id),
                        ]),
                    ])
                    ->all();

                $existing = ValueMapping::query()
                    ->where('attribute_mapping_id', $attributeMapping->id)
                    ->where('is_active', true)
                    ->get();

                $existingRaw = $existing->pluck('source_raw_value')->filter()->map(fn ($value) => Canonicalizer::normalizeText((string) $value))->filter()->all();
                $existingNorm = $existing->pluck('normalized_source_value')->filter()->map(fn ($value) => Canonicalizer::normalizeKey((string) $value))->all();
                $suggestions = [];

                foreach ($attributeMapping->sourceAttribute->values as $sourceValue) {
                    if (($scope['source_attribute_value_id'] ?? null) && (int) $scope['source_attribute_value_id'] !== (int) $sourceValue->id) {
                        continue;
                    }

                    $source = $this->sourceDescriptor(
                        $sourceValue->id,
                        $sourceValue->raw_value,
                        [$sourceValue->raw_value, $sourceValue->normalized_value],
                        ['attribute_mapping_id' => $attributeMapping->id]
                    );
                    $normalizedRaw = Canonicalizer::normalizeText((string) $sourceValue->raw_value);
                    $normalizedKey = Canonicalizer::normalizeKey((string) ($sourceValue->normalized_value ?: $sourceValue->raw_value));

                    if (($normalizedRaw !== null && in_array($normalizedRaw, $existingRaw, true)) || in_array($normalizedKey, $existingNorm, true)) {
                        continue;
                    }

                    $context = [
                        'source_category_path' => $attributeMapping->sourceCategory?->full_path ?: $attributeMapping->sourceCategory?->name,
                        'source_attribute_code' => $attributeMapping->sourceAttribute->code,
                    ];

                    $matches = $this->ruleEngine->explicitMatches($feedProfile, MappingRule::TYPE_VALUE, $source, $targets, $context);

                    foreach ($targets as $target) {
                        if (collect($target['normalized_candidates'])->intersect($source['normalized_candidates'])->isNotEmpty()) {
                            $matches[] = $this->ruleEngine->heuristicCandidate(
                                MappingRule::MATCH_EXACT,
                                $target,
                                ['shared_candidates' => array_values(array_intersect($target['normalized_candidates'], $source['normalized_candidates']))],
                                $source['label']
                            );
                        }
                    }

                    $best = $this->ruleEngine->sortMatches($matches)[0] ?? null;

                    if ($best === null) {
                        continue;
                    }

                    $suggestions[] = array_merge($this->baseSuggestion($best, $source, MappingRule::TYPE_VALUE), [
                        'attribute_mapping_id' => $attributeMapping->id,
                        'source_attribute_id' => $attributeMapping->source_attribute_id,
                        'source_category_id' => $attributeMapping->source_category_id,
                        'source_category_label' => $attributeMapping->sourceCategory?->full_path ?: $attributeMapping->sourceCategory?->name,
                        'unlock_estimate' => 1,
                    ]);
                }

                return $suggestions;
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $best
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>
     */
    private function baseSuggestion(array $best, array $source, string $mappingType): array
    {
        return [
            'mapping_type' => $mappingType,
            'source' => [
                'id' => $source['id'],
                'label' => $source['label'],
                'metadata' => $source['metadata'] ?? [],
            ],
            'suggested_target' => [
                'id' => $best['target']['id'],
                'label' => $best['target']['label'],
                'reference' => $best['target']['reference'] ?? null,
            ],
            'confidence' => $best['confidence'],
            'match_strategy' => $best['match_strategy'],
            'explanation' => $best['explanation'],
            'supporting_evidence' => $best['supporting_evidence'],
            'safe_for_auto_apply' => $best['safe_for_auto_apply'],
        ];
    }

    /**
     * @param  array<int, string|null>  $candidates
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function sourceDescriptor(int $id, ?string $label, array $candidates, array $metadata = []): array
    {
        $raw = collect($candidates)
            ->filter(fn ($value) => is_string($value) && Canonicalizer::normalizeText($value) !== null)
            ->map(fn ($value) => Canonicalizer::normalizeText($value))
            ->values()
            ->all();

        return [
            'id' => $id,
            'label' => $label ?: ($raw[0] ?? (string) $id),
            'raw_candidates' => $raw,
            'normalized_candidates' => collect($raw)->map(fn ($value) => Canonicalizer::normalizeKey((string) $value))->unique()->values()->all(),
            'metadata' => $metadata,
        ];
    }
}
