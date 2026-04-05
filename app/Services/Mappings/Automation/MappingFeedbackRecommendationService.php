<?php

namespace App\Services\Mappings\Automation;

use App\Models\FeedbackRecord;
use App\Models\FeedProfile;
use App\Models\ValidationError;
use Illuminate\Support\Collection;

class MappingFeedbackRecommendationService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function recommend(FeedProfile $feedProfile): array
    {
        $records = FeedbackRecord::query()
            ->with(['feedItem.activeValidationErrors', 'feedItem.sourceProduct.sourceCategory'])
            ->where('feed_profile_id', $feedProfile->id)
            ->where('status', FeedbackRecord::STATUS_REJECTED)
            ->get();

        return collect()
            ->merge($this->valueAliasRecommendations($records))
            ->merge($this->ruleRecommendations($records))
            ->merge($this->exclusionRecommendations($records))
            ->merge($this->merchantOverrideRecommendations($records))
            ->sortByDesc('impact_count')
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, FeedbackRecord>  $records
     * @return Collection<int, array<string, mixed>>
     */
    private function valueAliasRecommendations(Collection $records): Collection
    {
        return $records
            ->flatMap(function (FeedbackRecord $record): array {
                return $record->feedItem?->activeValidationErrors
                    ? $record->feedItem->activeValidationErrors
                        ->where('code', ValidationError::CODE_MISSING_VALUE_MAPPING)
                        ->map(fn (ValidationError $error) => [
                            'kind' => 'value_alias',
                            'key' => ($error->payload['source_attribute'] ?? 'attribute').'|'.($error->payload['source_value'] ?? 'value').'|'.($record->rejection_reason_code ?: 'n/a'),
                            'title' => 'Add value alias suggestion',
                            'subject' => trim(($error->payload['source_attribute'] ?? 'Unknown attribute').' = '.($error->payload['source_value'] ?? 'Unknown value')),
                            'rationale' => sprintf(
                                'Same source value was rejected repeatedly for reason [%s].',
                                $record->rejection_reason_code ?: ($record->rejection_reason_message ?: 'n/a')
                            ),
                            'payload' => [
                                'source_attribute' => $error->payload['source_attribute'] ?? null,
                                'source_value' => $error->payload['source_value'] ?? null,
                                'rejection_reason_code' => $record->rejection_reason_code,
                            ],
                        ])->all()
                    : [];
            })
            ->groupBy('key')
            ->map(function (Collection $group): array {
                $first = $group->first();

                return array_merge($first, [
                    'impact_count' => $group->count(),
                    'recommendation_type' => 'value_alias',
                    'safe_to_auto_apply' => false,
                ]);
            })
            ->filter(fn (array $row) => $row['impact_count'] >= (int) config('feed_mediator.mapping_automation.feedback.minimum_repeated_rejections', 3))
            ->values();
    }

    /**
     * @param  Collection<int, FeedbackRecord>  $records
     * @return Collection<int, array<string, mixed>>
     */
    private function ruleRecommendations(Collection $records): Collection
    {
        return $records
            ->flatMap(function (FeedbackRecord $record): array {
                return $record->feedItem?->activeValidationErrors
                    ? $record->feedItem->activeValidationErrors
                        ->whereIn('code', [ValidationError::CODE_MISSING_CATEGORY_MAPPING, ValidationError::CODE_MISSING_REQUIRED_ATTRIBUTE_MAPPING])
                        ->map(function (ValidationError $error) use ($record): array {
                            $subject = $error->code === ValidationError::CODE_MISSING_CATEGORY_MAPPING
                                ? ($error->payload['source_category_name'] ?? 'Unknown category')
                                : ($error->payload['attribute_name'] ?? 'Unknown attribute');

                            return [
                                'kind' => 'mapping_rule',
                                'key' => $error->code.'|'.$subject,
                                'title' => 'Create reusable mapping rule suggestion',
                                'subject' => $subject,
                                'rationale' => sprintf('%s keeps appearing on rejected items.', str_replace('_', ' ', $error->code)),
                                'payload' => [
                                    'validation_code' => $error->code,
                                    'payload' => $error->payload,
                                    'rejection_reason_code' => $record->rejection_reason_code,
                                ],
                            ];
                        })->all()
                    : [];
            })
            ->groupBy('key')
            ->map(function (Collection $group): array {
                $first = $group->first();

                return array_merge($first, [
                    'impact_count' => $group->count(),
                    'recommendation_type' => 'mapping_rule',
                    'safe_to_auto_apply' => false,
                ]);
            })
            ->filter(fn (array $row) => $row['impact_count'] >= (int) config('feed_mediator.mapping_automation.feedback.minimum_repeated_rejections', 3))
            ->values();
    }

    /**
     * @param  Collection<int, FeedbackRecord>  $records
     * @return Collection<int, array<string, mixed>>
     */
    private function exclusionRecommendations(Collection $records): Collection
    {
        return $records
            ->filter(fn (FeedbackRecord $record) => $record->resolution_status === FeedbackRecord::RESOLUTION_EXCLUDED)
            ->groupBy(function (FeedbackRecord $record): string {
                return ($record->feedItem?->sourceProduct?->brand ?: 'n/a').'|'.($record->feedItem?->sourceProduct?->sourceCategory?->full_path ?: 'n/a');
            })
            ->map(function (Collection $group, string $key): array {
                [$brand, $category] = explode('|', $key);

                return [
                    'recommendation_type' => 'exclusion',
                    'title' => 'Review category/vendor exclusion',
                    'subject' => trim($brand.' / '.$category),
                    'impact_count' => $group->count(),
                    'safe_to_auto_apply' => false,
                    'rationale' => sprintf('Same brand/category combination was excluded in %d feedback cases.', $group->count()),
                    'payload' => [
                        'brand' => $brand !== 'n/a' ? $brand : null,
                        'source_category_path' => $category !== 'n/a' ? $category : null,
                    ],
                ];
            })
            ->filter(fn (array $row) => $row['impact_count'] >= (int) config('feed_mediator.mapping_automation.feedback.minimum_exclusion_pattern', 2))
            ->values();
    }

    /**
     * @param  Collection<int, FeedbackRecord>  $records
     * @return Collection<int, array<string, mixed>>
     */
    private function merchantOverrideRecommendations(Collection $records): Collection
    {
        return $records
            ->flatMap(function (FeedbackRecord $record): array {
                return $record->feedItem?->activeValidationErrors
                    ? $record->feedItem->activeValidationErrors
                        ->whereIn('code', [ValidationError::CODE_INVALID_COLOR, ValidationError::CODE_INVALID_SIZE])
                        ->map(fn (ValidationError $error) => [
                            'key' => $error->code.'|'.($record->feedItem?->sourceProduct?->brand ?: 'n/a'),
                            'subject' => ($record->feedItem?->sourceProduct?->brand ?: 'Unknown brand'),
                            'validation_code' => $error->code,
                        ])->all()
                    : [];
            })
            ->groupBy('key')
            ->map(function (Collection $group): array {
                $first = $group->first();

                return [
                    'recommendation_type' => 'merchant_override',
                    'title' => 'Consider merchant override',
                    'subject' => $first['subject'],
                    'impact_count' => $group->count(),
                    'safe_to_auto_apply' => false,
                    'rationale' => sprintf('Same merchant data issue [%s] repeated %d times.', $first['validation_code'], $group->count()),
                    'payload' => [
                        'brand' => $first['subject'],
                        'validation_code' => $first['validation_code'],
                    ],
                ];
            })
            ->filter(fn (array $row) => $row['impact_count'] >= (int) config('feed_mediator.mapping_automation.feedback.minimum_override_pattern', 2))
            ->values();
    }
}
