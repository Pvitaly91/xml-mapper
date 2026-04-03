<?php

namespace App\Services\Feeds;

use App\Models\FeedGeneration;

class FeedGenerationDiffService
{
    public function __construct(
        private readonly KastaExportXmlService $xmlService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function summarize(?FeedGeneration $baselineGeneration, FeedGeneration $candidateGeneration): array
    {
        $candidateOffers = blank($candidateGeneration->file_path)
            ? []
            : $this->xmlService->parseOfferSnapshots((string) $candidateGeneration->file_path);
        $baselineOffers = blank($baselineGeneration?->file_path)
            ? []
            : $this->xmlService->parseOfferSnapshots((string) $baselineGeneration->file_path);

        $addedItems = [];
        $removedItems = [];
        $changedItems = [];
        $changedFieldCounters = [
            'price' => 0,
            'availability' => 0,
            'categoryId' => 0,
            'vendorCode' => 0,
        ];

        foreach ($candidateOffers as $offerId => $candidateOffer) {
            if (! array_key_exists($offerId, $baselineOffers)) {
                $addedItems[] = [
                    'offer_id' => $offerId,
                    'price' => $candidateOffer['price'],
                    'availability' => $candidateOffer['available'],
                    'categoryId' => $candidateOffer['categoryId'],
                    'vendorCode' => $candidateOffer['vendorCode'],
                ];

                continue;
            }

            $baselineOffer = $baselineOffers[$offerId];
            $fieldChanges = [];

            foreach ([
                'price' => 'price',
                'available' => 'availability',
                'categoryId' => 'categoryId',
                'vendorCode' => 'vendorCode',
            ] as $field => $summaryField) {
                if (($baselineOffer[$field] ?? null) === ($candidateOffer[$field] ?? null)) {
                    continue;
                }

                $fieldChanges[] = [
                    'field' => $summaryField,
                    'from' => $baselineOffer[$field] ?? null,
                    'to' => $candidateOffer[$field] ?? null,
                ];
                $changedFieldCounters[$summaryField]++;
            }

            if ($fieldChanges !== []) {
                $changedItems[] = [
                    'offer_id' => $offerId,
                    'name' => $candidateOffer['name'],
                    'changes' => $fieldChanges,
                ];
            }
        }

        foreach ($baselineOffers as $offerId => $baselineOffer) {
            if (array_key_exists($offerId, $candidateOffers)) {
                continue;
            }

            $removedItems[] = [
                'offer_id' => $offerId,
                'price' => $baselineOffer['price'],
                'availability' => $baselineOffer['available'],
                'categoryId' => $baselineOffer['categoryId'],
                'vendorCode' => $baselineOffer['vendorCode'],
            ];
        }

        return [
            'baseline_generation_id' => $baselineGeneration?->id,
            'candidate_generation_id' => $candidateGeneration->id,
            'summary' => [
                'added_items_total' => count($addedItems),
                'removed_items_total' => count($removedItems),
                'changed_items_total' => count($changedItems),
                'changed_fields' => $changedFieldCounters,
            ],
            'added_items' => array_slice($addedItems, 0, 20),
            'removed_items' => array_slice($removedItems, 0, 20),
            'changed_items' => array_slice($changedItems, 0, 20),
        ];
    }
}
