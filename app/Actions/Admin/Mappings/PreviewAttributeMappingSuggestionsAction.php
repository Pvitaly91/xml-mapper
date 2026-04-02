<?php

namespace App\Actions\Admin\Mappings;

use App\Models\AttributeMapping;
use App\Models\CategoryMapping;
use App\Models\FeedProfile;
use App\Models\KastaAttribute;
use App\Models\SourceAttribute;
use App\Support\Canonicalizer;

class PreviewAttributeMappingSuggestionsAction
{
    /**
     * @return array<int, array{source_attribute:SourceAttribute,kasta_attribute:KastaAttribute}>
     */
    public function handle(FeedProfile $feedProfile, ?int $sourceCategoryId): array
    {
        if ($sourceCategoryId === null) {
            return [];
        }

        $categoryMapping = CategoryMapping::query()
            ->with('kastaCategory')
            ->where('feed_profile_id', $feedProfile->id)
            ->where('source_category_id', $sourceCategoryId)
            ->where('is_active', true)
            ->first();

        if ($categoryMapping?->kasta_category_id === null) {
            return [];
        }

        $targetAttributes = KastaAttribute::query()
            ->where('kasta_category_id', $categoryMapping->kasta_category_id)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $targetIndex = [];

        foreach ($targetAttributes as $targetAttribute) {
            foreach (array_unique([
                Canonicalizer::normalizeKey($targetAttribute->name),
                Canonicalizer::normalizeKey($targetAttribute->code),
            ]) as $key) {
                $targetIndex[$key] ??= $targetAttribute;
            }
        }

        $existing = AttributeMapping::query()
            ->where('feed_profile_id', $feedProfile->id)
            ->where('source_category_id', $sourceCategoryId)
            ->get();

        $mappedSourceIds = $existing->pluck('source_attribute_id')->all();
        $mappedTargetIds = $existing->pluck('kasta_attribute_id')->filter()->values()->all();
        $suggestions = [];

        $sourceAttributes = SourceAttribute::query()
            ->where('source_connection_id', $feedProfile->source_connection_id)
            ->orderBy('name')
            ->get();

        foreach ($sourceAttributes as $sourceAttribute) {
            if (in_array($sourceAttribute->id, $mappedSourceIds, true)) {
                continue;
            }

            $match = $targetIndex[Canonicalizer::normalizeKey($sourceAttribute->name)]
                ?? $targetIndex[Canonicalizer::normalizeKey($sourceAttribute->code)]
                ?? null;

            if ($match === null || in_array($match->id, $mappedTargetIds, true)) {
                continue;
            }

            $suggestions[] = [
                'source_attribute' => $sourceAttribute,
                'kasta_attribute' => $match,
            ];

            $mappedTargetIds[] = $match->id;
        }

        return $suggestions;
    }
}
