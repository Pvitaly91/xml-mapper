<?php

namespace App\Services\Mappings;

use App\Contracts\Mappings\CategoryMappingServiceInterface;
use App\Models\CategoryMapping;
use App\Models\FeedProfile;
use App\Models\KastaCategory;
use App\Models\SourceCategory;

class CategoryMappingService implements CategoryMappingServiceInterface
{
    public function resolve(FeedProfile $feedProfile, SourceCategory|int|null $sourceCategory): ?CategoryMapping
    {
        $category = $sourceCategory instanceof SourceCategory
            ? $sourceCategory
            : ($sourceCategory ? SourceCategory::find($sourceCategory) : null);

        if ($category === null) {
            return null;
        }

        $mapping = CategoryMapping::query()
            ->with('kastaCategory')
            ->where('feed_profile_id', $feedProfile->id)
            ->where('source_category_id', $category->id)
            ->first();

        if ($mapping !== null && $mapping->is_active) {
            return $mapping;
        }

        if (blank($category->rz_id)) {
            return null;
        }

        $kastaCategory = KastaCategory::query()
            ->where('rz_id', $category->rz_id)
            ->where('is_active', true)
            ->first();

        if ($kastaCategory === null) {
            return null;
        }

        if ($mapping !== null) {
            $mapping->update([
                'kasta_category_id' => $kastaCategory->id,
                'rz_id' => $category->rz_id,
                'mapping_strategy' => CategoryMapping::STRATEGY_RZ_ID,
                'is_active' => true,
            ]);

            return $mapping->refresh()->load('kastaCategory');
        }

        return CategoryMapping::create([
            'shop_id' => $feedProfile->shop_id,
            'source_connection_id' => $feedProfile->source_connection_id,
            'feed_profile_id' => $feedProfile->id,
            'source_category_id' => $category->id,
            'kasta_category_id' => $kastaCategory->id,
            'rz_id' => $category->rz_id,
            'mapping_strategy' => CategoryMapping::STRATEGY_RZ_ID,
            'is_active' => true,
        ])->load('kastaCategory');
    }

    public function getMappedCategory(FeedProfile $feedProfile, SourceCategory|int|null $sourceCategory): ?KastaCategory
    {
        return $this->resolve($feedProfile, $sourceCategory)?->kastaCategory;
    }
}
