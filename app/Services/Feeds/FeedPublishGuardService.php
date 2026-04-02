<?php

namespace App\Services\Feeds;

use App\Models\FeedGeneration;
use App\Models\FeedProfile;
use App\Models\ValidationError;

class FeedPublishGuardService
{
    /**
     * @return array<string, mixed>
     */
    public function evaluate(FeedProfile $feedProfile, FeedGeneration $generation): array
    {
        $summary = $generation->meta['summary'] ?? [
            'total' => $generation->items_total,
            'ready' => $generation->valid_items_total,
            'invalid_total' => $generation->invalid_items_total,
            'excluded' => 0,
        ];

        $readyItems = (int) ($summary['ready'] ?? 0);
        $invalidItems = (int) ($summary['invalid_total'] ?? $generation->invalid_items_total);
        $candidateTotal = max($readyItems + $invalidItems, 1);
        $invalidRatio = $invalidItems / $candidateTotal;
        $reasons = [];

        if ($feedProfile->publishGuardEnabled()) {
            if ($readyItems < $feedProfile->minimumReadyItems()) {
                $reasons[] = sprintf(
                    'Ready items %d are below the minimum threshold %d.',
                    $readyItems,
                    $feedProfile->minimumReadyItems()
                );
            }

            if ($invalidRatio > $feedProfile->maximumInvalidRatio()) {
                $reasons[] = sprintf(
                    'Invalid ratio %.2f exceeds the configured maximum %.2f.',
                    $invalidRatio,
                    $feedProfile->maximumInvalidRatio()
                );
            }

            if ($feedProfile->blockPublishOnCriticalConformance()) {
                $criticalConformanceErrors = ValidationError::query()
                    ->where('feed_profile_id', $feedProfile->id)
                    ->where('is_active', true)
                    ->whereIn('code', ValidationError::criticalConformanceCodes())
                    ->whereHas('feedItem', fn ($query) => $query->where('last_built_generation_id', $generation->id))
                    ->count();

                if ($criticalConformanceErrors > 0) {
                    $reasons[] = sprintf(
                        'Critical conformance errors remain on %d item(s) in generation #%d.',
                        $criticalConformanceErrors,
                        $generation->id
                    );
                }
            }
        }

        return [
            'enabled' => $feedProfile->publishGuardEnabled(),
            'allowed' => $reasons === [],
            'reasons' => $reasons,
            'summary' => [
                'ready_items' => $readyItems,
                'invalid_items' => $invalidItems,
                'excluded_items' => (int) ($summary['excluded'] ?? 0),
                'invalid_ratio' => round($invalidRatio, 4),
                'minimum_ready_items' => $feedProfile->minimumReadyItems(),
                'maximum_invalid_ratio' => $feedProfile->maximumInvalidRatio(),
                'block_publish_on_critical_conformance' => $feedProfile->blockPublishOnCriticalConformance(),
            ],
        ];
    }
}
