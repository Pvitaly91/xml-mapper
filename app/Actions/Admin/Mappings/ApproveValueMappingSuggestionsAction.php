<?php

namespace App\Actions\Admin\Mappings;

use App\Models\AttributeMapping;
use App\Models\ValueMapping;
use Illuminate\Support\Facades\DB;

class ApproveValueMappingSuggestionsAction
{
    public function __construct(
        private readonly PreviewValueMappingSuggestionsAction $previewAction,
    ) {}

    /**
     * @param  list<int>  $sourceAttributeValueIds
     * @return array{created:int}
     */
    public function handle(AttributeMapping $attributeMapping, array $sourceAttributeValueIds = []): array
    {
        $suggestions = $this->previewAction->handle($attributeMapping);

        if ($sourceAttributeValueIds !== []) {
            $suggestions = array_values(array_filter(
                $suggestions,
                fn (array $suggestion): bool => in_array($suggestion['source_attribute_value']->id, $sourceAttributeValueIds, true)
            ));
        }

        return DB::transaction(function () use ($attributeMapping, $suggestions): array {
            $created = 0;

            foreach ($suggestions as $suggestion) {
                ValueMapping::updateOrCreate(
                    [
                        'attribute_mapping_id' => $attributeMapping->id,
                        'source_raw_value' => $suggestion['source_attribute_value']->raw_value,
                    ],
                    [
                        'shop_id' => $attributeMapping->shop_id,
                        'feed_profile_id' => $attributeMapping->feed_profile_id,
                        'source_attribute_value_id' => $suggestion['source_attribute_value']->id,
                        'kasta_attribute_value_id' => $suggestion['kasta_attribute_value']->id,
                        'normalized_source_value' => $suggestion['normalized_source_value'],
                        'target_value' => $suggestion['kasta_attribute_value']->value,
                        'mapping_strategy' => ValueMapping::STRATEGY_NORMALIZED_EXACT,
                        'is_active' => true,
                    ]
                );

                $created++;
            }

            return ['created' => $created];
        });
    }
}
