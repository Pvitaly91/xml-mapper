<?php

namespace App\Services\Shops;

use App\Models\FeedItem;
use App\Models\FeedProfile;
use App\Models\ValidationError;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class UnresolvedMappingsWorkbenchService
{
    /**
     * @return array<string, mixed>
     */
    public function summarize(FeedProfile $feedProfile, array $filters = []): array
    {
        $definitions = $this->problemDefinitions($feedProfile);
        $selectedProblem = $filters['problem'] ?? collect($definitions)->firstWhere('count', '>', 0)['key'] ?? 'missing_category_mapping';

        return [
            'problems' => $definitions,
            'selected_problem' => $selectedProblem,
            'items' => $this->items($feedProfile, $selectedProblem, $filters['search'] ?? null),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function problemDefinitions(FeedProfile $feedProfile): array
    {
        return [
            $this->definition(
                $feedProfile,
                'missing_category_mapping',
                'Missing category mapping',
                'Source categories that still need a Kasta category.',
                [ValidationError::CODE_MISSING_CATEGORY_MAPPING],
                route('admin.feed-profiles.category-mappings.index', $feedProfile)
            ),
            $this->definition(
                $feedProfile,
                'missing_attribute_mapping',
                'Missing attribute mappings',
                'Required Kasta attributes are not mapped yet.',
                [ValidationError::CODE_MISSING_REQUIRED_ATTRIBUTE_MAPPING],
                route('admin.feed-profiles.attribute-mappings.index', $feedProfile)
            ),
            $this->definition(
                $feedProfile,
                'missing_value_mapping',
                'Missing value mappings',
                'Mapped attributes still need exact or normalized value mappings.',
                [ValidationError::CODE_MISSING_VALUE_MAPPING],
                route('admin.feed-profiles.value-mappings.index', $feedProfile)
            ),
            $this->definition(
                $feedProfile,
                'missing_required_source_values',
                'Missing required source values',
                'Source products or variants do not provide required values.',
                [ValidationError::CODE_MISSING_REQUIRED_ATTRIBUTE_VALUE],
                route('admin.feed-profiles.feed-items.index', ['feed_profile' => $feedProfile, 'diagnostic' => 'missing_required_attribute'])
            ),
            $this->definition(
                $feedProfile,
                'invalid_color_size',
                'Invalid color or size',
                'Normalization failed for color or size fields.',
                [ValidationError::CODE_INVALID_COLOR, ValidationError::CODE_INVALID_SIZE],
                route('admin.feed-profiles.feed-items.index', ['feed_profile' => $feedProfile, 'diagnostic' => 'invalid_color_size'])
            ),
            [
                'key' => 'excluded_items',
                'label' => 'Excluded items',
                'description' => 'Items excluded manually or due to availability rules.',
                'count' => FeedItem::query()
                    ->where('feed_profile_id', $feedProfile->id)
                    ->where('status', FeedItem::STATUS_EXCLUDED)
                    ->count(),
                'quick_action_url' => route('admin.feed-profiles.feed-items.index', ['feed_profile' => $feedProfile, 'status' => FeedItem::STATUS_EXCLUDED]),
            ],
        ];
    }

    private function definition(
        FeedProfile $feedProfile,
        string $key,
        string $label,
        string $description,
        array $codes,
        string $quickActionUrl
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'description' => $description,
            'count' => ValidationError::query()
                ->where('feed_profile_id', $feedProfile->id)
                ->where('is_active', true)
                ->whereIn('code', $codes)
                ->distinct('feed_item_id')
                ->count('feed_item_id'),
            'quick_action_url' => $quickActionUrl,
        ];
    }

    private function items(FeedProfile $feedProfile, string $problem, ?string $search = null): LengthAwarePaginator
    {
        return FeedItem::query()
            ->with(['sourceProduct.sourceCategory', 'sourceVariant', 'activeValidationErrors'])
            ->where('feed_profile_id', $feedProfile->id)
            ->when($problem === 'excluded_items', fn ($query) => $query->where('status', FeedItem::STATUS_EXCLUDED))
            ->when($problem !== 'excluded_items', function ($query) use ($problem): void {
                $codes = $this->problemCodes($problem);

                if ($codes !== []) {
                    $query->whereHas('activeValidationErrors', fn ($innerQuery) => $innerQuery->whereIn('code', $codes));
                }
            })
            ->when($search, function ($query, $search): void {
                $query->where(function ($innerQuery) use ($search): void {
                    $innerQuery->whereHas('sourceProduct', fn ($productQuery) => $productQuery
                        ->where('name', 'like', '%'.$search.'%')
                        ->orWhere('article', 'like', '%'.$search.'%'))
                        ->orWhereHas('sourceVariant', fn ($variantQuery) => $variantQuery
                            ->where('stable_offer_id', 'like', '%'.$search.'%')
                            ->orWhere('external_offer_id', 'like', '%'.$search.'%'));
                });
            })
            ->orderByDesc('id')
            ->paginate((int) config('feed_mediator.performance.workbench_page_size', 20))
            ->withQueryString();
    }

    /**
     * @return list<string>
     */
    private function problemCodes(string $problem): array
    {
        return match ($problem) {
            'missing_category_mapping' => [ValidationError::CODE_MISSING_CATEGORY_MAPPING],
            'missing_attribute_mapping' => [ValidationError::CODE_MISSING_REQUIRED_ATTRIBUTE_MAPPING],
            'missing_value_mapping' => [ValidationError::CODE_MISSING_VALUE_MAPPING],
            'missing_required_source_values' => [ValidationError::CODE_MISSING_REQUIRED_ATTRIBUTE_VALUE],
            'invalid_color_size' => [ValidationError::CODE_INVALID_COLOR, ValidationError::CODE_INVALID_SIZE],
            default => [],
        };
    }
}
