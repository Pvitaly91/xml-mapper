<?php

namespace App\Actions\Admin\Mappings;

use App\Models\CategoryMapping;
use App\Models\FeedProfile;
use App\Models\KastaCategory;
use App\Models\SourceCategory;

class UpsertCategoryMappingAction
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(FeedProfile $feedProfile, array $payload, ?CategoryMapping $mapping = null): CategoryMapping
    {
        $sourceCategory = SourceCategory::query()
            ->where('source_connection_id', $feedProfile->source_connection_id)
            ->findOrFail($payload['source_category_id']);

        $kastaCategory = KastaCategory::query()->findOrFail($payload['kasta_category_id']);

        $mapping ??= new CategoryMapping();
        $mapping->fill([
            'shop_id' => $feedProfile->shop_id,
            'source_connection_id' => $feedProfile->source_connection_id,
            'feed_profile_id' => $feedProfile->id,
            'source_category_id' => $sourceCategory->id,
            'kasta_category_id' => $kastaCategory->id,
            'rz_id' => $sourceCategory->rz_id,
            'mapping_strategy' => CategoryMapping::STRATEGY_MANUAL,
            'is_active' => (bool) ($payload['is_active'] ?? true),
        ]);
        $mapping->save();

        return $mapping->refresh()->load(['sourceCategory', 'kastaCategory']);
    }
}
