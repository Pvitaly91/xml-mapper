<?php

namespace App\Actions\Admin\Mappings;

use App\Models\CategoryMapping;
use App\Models\FeedProfile;
use App\Models\KastaCategory;
use App\Models\SourceCategory;
use Illuminate\Support\Facades\DB;

class RunCategoryAutomapAction
{
    /**
     * @param  list<int>  $sourceCategoryIds
     * @return array{created:int,updated:int,skipped:int}
     */
    public function handle(FeedProfile $feedProfile, array $sourceCategoryIds = []): array
    {
        return DB::transaction(function () use ($feedProfile, $sourceCategoryIds): array {
            $summary = ['created' => 0, 'updated' => 0, 'skipped' => 0];

            $categories = SourceCategory::query()
                ->where('source_connection_id', $feedProfile->source_connection_id)
                ->when($sourceCategoryIds !== [], fn ($query) => $query->whereIn('id', $sourceCategoryIds))
                ->orderBy('id')
                ->get();

            foreach ($categories as $category) {
                $existing = CategoryMapping::query()
                    ->where('feed_profile_id', $feedProfile->id)
                    ->where('source_category_id', $category->id)
                    ->first();

                if ($existing?->mapping_strategy === CategoryMapping::STRATEGY_MANUAL && $existing->is_active) {
                    $summary['skipped']++;
                    continue;
                }

                if (blank($category->rz_id)) {
                    $summary['skipped']++;
                    continue;
                }

                $kastaCategory = KastaCategory::query()
                    ->where('rz_id', $category->rz_id)
                    ->where('is_active', true)
                    ->first();

                if ($kastaCategory === null) {
                    $summary['skipped']++;
                    continue;
                }

                if ($existing !== null) {
                    $existing->update([
                        'kasta_category_id' => $kastaCategory->id,
                        'rz_id' => $category->rz_id,
                        'mapping_strategy' => CategoryMapping::STRATEGY_RZ_ID,
                        'is_active' => true,
                    ]);
                    $summary['updated']++;

                    continue;
                }

                CategoryMapping::create([
                    'shop_id' => $feedProfile->shop_id,
                    'source_connection_id' => $feedProfile->source_connection_id,
                    'feed_profile_id' => $feedProfile->id,
                    'source_category_id' => $category->id,
                    'kasta_category_id' => $kastaCategory->id,
                    'rz_id' => $category->rz_id,
                    'mapping_strategy' => CategoryMapping::STRATEGY_RZ_ID,
                    'is_active' => true,
                ]);

                $summary['created']++;
            }

            return $summary;
        });
    }
}
