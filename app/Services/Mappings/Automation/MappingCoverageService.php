<?php

namespace App\Services\Mappings\Automation;

use App\Models\AttributeMapping;
use App\Models\CategoryMapping;
use App\Models\FeedProfile;
use App\Models\MappingBatch;
use App\Models\MappingRule;
use App\Models\MappingTemplate;
use App\Models\ValidationError;
use App\Models\ValueMapping;
use App\Services\Shops\UnresolvedMappingsWorkbenchService;

class MappingCoverageService
{
    public function __construct(
        private readonly MappingSuggestionService $suggestionService,
        private readonly MappingFeedbackRecommendationService $feedbackRecommendationService,
        private readonly UnresolvedMappingsWorkbenchService $workbenchService,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function summarize(FeedProfile $feedProfile, array $filters = []): array
    {
        $categoryTotal = (int) $feedProfile->sourceConnection?->categories()->count();
        $categoryMapped = CategoryMapping::query()->where('feed_profile_id', $feedProfile->id)->where('is_active', true)->count();
        $attributeTotal = AttributeMapping::query()->where('feed_profile_id', $feedProfile->id)->count();
        $valueTotal = ValueMapping::query()->where('feed_profile_id', $feedProfile->id)->count();
        $manualSplit = [
            'category_manual' => CategoryMapping::query()->where('feed_profile_id', $feedProfile->id)->where('mapping_strategy', CategoryMapping::STRATEGY_MANUAL)->count(),
            'attribute_manual' => AttributeMapping::query()->where('feed_profile_id', $feedProfile->id)->where('mapping_strategy', AttributeMapping::STRATEGY_MANUAL)->count(),
            'value_manual' => ValueMapping::query()->where('feed_profile_id', $feedProfile->id)->where('mapping_strategy', ValueMapping::STRATEGY_MANUAL)->count(),
        ];

        $categorySuggestions = $this->suggestionService->categorySuggestions($feedProfile, $filters);
        $attributeSuggestions = $this->suggestionService->attributeSuggestions($feedProfile, $filters);
        $valueSuggestions = $this->suggestionService->valueSuggestions($feedProfile, $filters);

        return [
            'summary' => [
                'category_coverage_pct' => $categoryTotal > 0 ? round(($categoryMapped / $categoryTotal) * 100, 1) : 100,
                'attribute_mapping_count' => $attributeTotal,
                'value_mapping_count' => $valueTotal,
                'unresolved_mapping_items' => ValidationError::query()
                    ->where('feed_profile_id', $feedProfile->id)
                    ->where('is_active', true)
                    ->whereIn('code', ValidationError::mappingCodes())
                    ->distinct('feed_item_id')
                    ->count('feed_item_id'),
                'manual_split' => $manualSplit,
                'auto_split' => [
                    'category_auto' => max(0, $categoryMapped - $manualSplit['category_manual']),
                    'attribute_auto' => max(0, $attributeTotal - $manualSplit['attribute_manual']),
                    'value_auto' => max(0, $valueTotal - $manualSplit['value_manual']),
                ],
            ],
            'latest_batches' => MappingBatch::query()
                ->with(['requestedBy', 'approvalRequest'])
                ->where('feed_profile_id', $feedProfile->id)
                ->latest('id')
                ->limit(10)
                ->get(),
            'template_summary' => [
                'stored_templates' => MappingTemplate::query()
                    ->where(function ($query) use ($feedProfile): void {
                        $query->where('feed_profile_id', $feedProfile->id)
                            ->orWhere(function ($inner) use ($feedProfile): void {
                                $inner->whereNull('feed_profile_id')
                                    ->where('shop_id', $feedProfile->shop_id);
                            })
                            ->orWhere(function ($inner): void {
                                $inner->whereNull('feed_profile_id')
                                    ->whereNull('shop_id');
                            });
                    })
                    ->where('is_active', true)
                    ->count(),
                'active_rules' => MappingRule::query()
                    ->where(function ($query) use ($feedProfile): void {
                        $query->where('feed_profile_id', $feedProfile->id)
                            ->orWhere(function ($inner) use ($feedProfile): void {
                                $inner->whereNull('feed_profile_id')
                                    ->where('shop_id', $feedProfile->shop_id);
                            })
                            ->orWhere(function ($inner): void {
                                $inner->whereNull('feed_profile_id')
                                    ->whereNull('shop_id');
                            });
                    })
                    ->where('is_active', true)
                    ->count(),
            ],
            'suggestions' => [
                'category' => array_slice($categorySuggestions, 0, 20),
                'attribute' => array_slice($attributeSuggestions, 0, 20),
                'value' => array_slice($valueSuggestions, 0, 20),
            ],
            'estimated_ready_gain' => [
                'category' => array_sum(array_column(array_slice($categorySuggestions, 0, 10), 'unlock_estimate')),
                'attribute' => array_sum(array_column(array_slice($attributeSuggestions, 0, 10), 'unlock_estimate')),
                'value' => array_sum(array_column(array_slice($valueSuggestions, 0, 10), 'unlock_estimate')),
            ],
            'feedback_recommendations' => array_slice($this->feedbackRecommendationService->recommend($feedProfile), 0, 20),
            'backlog' => $this->prioritizeBacklog($feedProfile),
            'workbench' => $this->workbenchService->summarize($feedProfile, [
                'problem' => $filters['problem'] ?? null,
                'search' => $filters['search'] ?? null,
            ]),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function prioritizeBacklog(FeedProfile $feedProfile): array
    {
        return ValidationError::query()
            ->where('feed_profile_id', $feedProfile->id)
            ->where('is_active', true)
            ->whereIn('code', ValidationError::mappingCodes())
            ->get()
            ->groupBy(function (ValidationError $error): string {
                return match ($error->code) {
                    ValidationError::CODE_MISSING_CATEGORY_MAPPING => 'missing_category|'.($error->payload['source_category_name'] ?? 'Unknown category'),
                    ValidationError::CODE_MISSING_REQUIRED_ATTRIBUTE_MAPPING => 'missing_attribute|'.($error->payload['attribute_name'] ?? 'Unknown attribute'),
                    ValidationError::CODE_MISSING_VALUE_MAPPING => 'missing_value|'.(($error->payload['source_attribute'] ?? 'Unknown attribute').'='.($error->payload['source_value'] ?? 'Unknown value')),
                    default => 'other|'.$error->code,
                };
            })
            ->map(function ($errors, string $key): array {
                [$bucket, $subject] = explode('|', $key, 2);
                $count = $errors->pluck('feed_item_id')->filter()->unique()->count();

                return [
                    'bucket' => match ($bucket) {
                        'missing_category' => 'missing required category mapping',
                        'missing_attribute' => 'blocks many ready items',
                        'missing_value' => 'easy wins via exact-match bulk apply',
                        default => 'low-value tail',
                    },
                    'subject' => $subject,
                    'impact_count' => $count,
                    'readiness_gain' => $count,
                    'safe_bulk_action' => $bucket === 'missing_value',
                ];
            })
            ->sortByDesc('impact_count')
            ->values()
            ->all();
    }
}
