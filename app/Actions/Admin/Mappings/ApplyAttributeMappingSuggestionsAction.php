<?php

namespace App\Actions\Admin\Mappings;

use App\Models\AttributeMapping;
use App\Models\FeedProfile;
use Illuminate\Support\Facades\DB;

class ApplyAttributeMappingSuggestionsAction
{
    public function __construct(
        private readonly PreviewAttributeMappingSuggestionsAction $previewAction,
    ) {}

    /**
     * @param  list<int>  $sourceAttributeIds
     * @return array{created:int,skipped:int}
     */
    public function handle(FeedProfile $feedProfile, int $sourceCategoryId, array $sourceAttributeIds = []): array
    {
        $suggestions = $this->previewAction->handle($feedProfile, $sourceCategoryId);

        if ($sourceAttributeIds !== []) {
            $suggestions = array_values(array_filter(
                $suggestions,
                fn (array $suggestion): bool => in_array($suggestion['source_attribute']->id, $sourceAttributeIds, true)
            ));
        }

        if ($suggestions === []) {
            return ['created' => 0, 'skipped' => 0];
        }

        return DB::transaction(function () use ($feedProfile, $sourceCategoryId, $suggestions): array {
            $summary = ['created' => 0, 'skipped' => 0];

            foreach ($suggestions as $suggestion) {
                $sourceAttribute = $suggestion['source_attribute'];
                $kastaAttribute = $suggestion['kasta_attribute'];

                $exists = AttributeMapping::query()
                    ->where('feed_profile_id', $feedProfile->id)
                    ->where('source_category_id', $sourceCategoryId)
                    ->where(function ($query) use ($sourceAttribute, $kastaAttribute): void {
                        $query->where('source_attribute_id', $sourceAttribute->id)
                            ->orWhere('kasta_attribute_id', $kastaAttribute->id);
                    })
                    ->exists();

                if ($exists) {
                    $summary['skipped']++;

                    continue;
                }

                AttributeMapping::create([
                    'shop_id' => $feedProfile->shop_id,
                    'source_connection_id' => $feedProfile->source_connection_id,
                    'feed_profile_id' => $feedProfile->id,
                    'source_category_id' => $sourceCategoryId,
                    'source_attribute_id' => $sourceAttribute->id,
                    'kasta_category_id' => $kastaAttribute->kasta_category_id,
                    'kasta_attribute_id' => $kastaAttribute->id,
                    'mapping_strategy' => AttributeMapping::STRATEGY_EXACT_NAME,
                    'is_required' => $kastaAttribute->is_required,
                    'default_value' => null,
                    'use_variant_value' => $sourceAttribute->is_variant_axis,
                ]);

                $summary['created']++;
            }

            return $summary;
        });
    }
}
