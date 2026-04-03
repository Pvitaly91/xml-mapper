<?php

namespace App\Services\Feeds;

use App\Contracts\Mappings\AttributeMappingServiceInterface;
use App\Contracts\Mappings\CategoryMappingServiceInterface;
use App\Models\FeedItem;
use App\Models\FeedProfile;
use App\Models\KastaAttribute;
use App\Models\SourceProduct;
use App\Models\SourceVariant;
use App\Models\ValidationError;
use App\Support\Canonicalizer;

class KastaExportConformanceService
{
    public function __construct(
        private readonly CategoryMappingServiceInterface $categoryMappingService,
        private readonly AttributeMappingServiceInterface $attributeMappingService,
        private readonly KastaExportFieldNormalizer $fieldNormalizer,
        private readonly FeedProfileOverrideService $overrideService,
    ) {
    }

    /**
     * @param  list<array{code:string,message:string,payload:array<string,mixed>}>  $sourceErrors
     * @return array{
     *     source_errors:list<array{code:string,message:string,payload:array<string,mixed>}>,
     *     mapping_errors:list<array{code:string,message:string,payload:array<string,mixed>}>,
     *     conformance_errors:list<array{code:string,message:string,payload:array<string,mixed>}>,
     *     mapped_category:?array<string, mixed>,
     *     mapped_attributes:array<string, string>,
     *     attribute_rows:array<int, array<string, mixed>>,
     *     required_attribute_diagnostics:array<int, array<string, mixed>>,
     *     normalized_export_snapshot:array<string, mixed>,
     *     diagnostics_summary:array<string, mixed>
     * }
     */
    public function analyze(
        FeedProfile $feedProfile,
        SourceProduct $product,
        SourceVariant $variant,
        array $sourceErrors = [],
        ?FeedItem $feedItem = null
    ): array {
        $mappingErrors = [];
        $conformanceErrors = [];
        $mappedCategory = $this->categoryMappingService->getMappedCategory($feedProfile, $product->sourceCategory);
        $mappingRows = collect();
        $requiredDiagnostics = [];
        $mappedAttributes = [];

        if ($mappedCategory === null) {
            $mappingErrors[] = $this->error(
                ValidationError::CODE_MISSING_CATEGORY_MAPPING,
                'Kasta category mapping is missing for this source category.',
                [
                    'source_category_id' => $product->source_category_id,
                    'source_category_name' => $product->sourceCategory?->full_path ?: $product->sourceCategory?->name,
                ]
            );
        } else {
            $mappingRows = $this->attributeMappingService->resolveMappingRows($feedProfile, $product, $variant, $mappedCategory);

            $mappingRows = $mappingRows->map(function (array $row) use ($feedProfile): array {
                $originalMappedValue = $row['mapped_value'] ?? null;
                $override = $this->overrideService->overrideValue(
                    $feedProfile,
                    $originalMappedValue ?? $row['source_value'] ?? null
                );

                if ($override !== null) {
                    $row['mapped_value'] = $override;
                    $row['resolution'] = $originalMappedValue === null ? 'forced_value_override' : 'forced_override';
                }

                return $row;
            });

            $mappedAttributes = $mappingRows
                ->filter(fn (array $row) => $row['mapped_value'] !== null)
                ->mapWithKeys(fn (array $row) => [$row['kasta_attribute_code'] => $row['mapped_value']])
                ->all();
            $mappedAttributes = $this->overrideService->applyAttributeOverrides($feedProfile, $mappedAttributes);

            $requiredAttributes = KastaAttribute::query()
                ->where('kasta_category_id', $mappedCategory->id)
                ->where('is_required', true)
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get();

            $rowsByAttributeId = $mappingRows->keyBy('kasta_attribute_id');

            foreach ($requiredAttributes as $requiredAttribute) {
                $row = $rowsByAttributeId->get($requiredAttribute->id);
                $forcedOverride = $mappedAttributes[Canonicalizer::normalizeKey($requiredAttribute->code)]
                    ?? $mappedAttributes[$requiredAttribute->code]
                    ?? null;

                if ($row === null && $forcedOverride !== null) {
                    $requiredDiagnostics[] = [
                        'attribute_id' => $requiredAttribute->id,
                        'attribute_code' => $requiredAttribute->code,
                        'attribute_name' => $requiredAttribute->name,
                        'status' => 'ok',
                        'failure_type' => null,
                        'message' => 'Filled by merchant attribute override.',
                        'source_attribute' => null,
                        'source_value' => null,
                        'mapped_value' => $forcedOverride,
                    ];

                    continue;
                }

                if (! is_array($row)) {
                    $mappingErrors[] = $this->error(
                        ValidationError::CODE_MISSING_REQUIRED_ATTRIBUTE_MAPPING,
                        sprintf('Required Kasta attribute [%s] is not mapped.', $requiredAttribute->name),
                        [
                            'attribute_id' => $requiredAttribute->id,
                            'attribute_code' => $requiredAttribute->code,
                            'attribute_name' => $requiredAttribute->name,
                        ]
                    );

                    $requiredDiagnostics[] = [
                        'attribute_id' => $requiredAttribute->id,
                        'attribute_code' => $requiredAttribute->code,
                        'attribute_name' => $requiredAttribute->name,
                        'status' => 'missing',
                        'failure_type' => 'missing_mapping',
                        'message' => 'Attribute mapping is missing.',
                        'source_attribute' => null,
                        'source_value' => null,
                        'mapped_value' => null,
                    ];

                    continue;
                }

                if ($row['source_value'] === null && $row['mapped_value'] === null) {
                    if ($forcedOverride !== null) {
                        $requiredDiagnostics[] = [
                            'attribute_id' => $requiredAttribute->id,
                            'attribute_code' => $requiredAttribute->code,
                            'attribute_name' => $requiredAttribute->name,
                            'status' => 'ok',
                            'failure_type' => null,
                            'message' => 'Filled by merchant attribute override.',
                            'source_attribute' => $row['source_attribute_name'],
                            'source_value' => null,
                            'mapped_value' => $forcedOverride,
                        ];

                        continue;
                    }

                    $sourceErrors[] = $this->error(
                        ValidationError::CODE_MISSING_REQUIRED_ATTRIBUTE_VALUE,
                        sprintf('Required attribute [%s] has no source value.', $requiredAttribute->name),
                        [
                            'attribute_id' => $requiredAttribute->id,
                            'attribute_code' => $requiredAttribute->code,
                            'attribute_name' => $requiredAttribute->name,
                            'reason' => 'missing_source_value',
                            'source_attribute' => $row['source_attribute_name'],
                        ]
                    );

                    $requiredDiagnostics[] = [
                        'attribute_id' => $requiredAttribute->id,
                        'attribute_code' => $requiredAttribute->code,
                        'attribute_name' => $requiredAttribute->name,
                        'status' => 'missing',
                        'failure_type' => 'missing_source_value',
                        'message' => 'Source value is missing.',
                        'source_attribute' => $row['source_attribute_name'],
                        'source_value' => null,
                        'mapped_value' => null,
                    ];

                    continue;
                }

                if ($row['mapped_value'] === null && $row['resolution'] === 'missing_value_mapping') {
                    if ($forcedOverride !== null) {
                        $requiredDiagnostics[] = [
                            'attribute_id' => $requiredAttribute->id,
                            'attribute_code' => $requiredAttribute->code,
                            'attribute_name' => $requiredAttribute->name,
                            'status' => 'ok',
                            'failure_type' => null,
                            'message' => 'Filled by merchant attribute override.',
                            'source_attribute' => $row['source_attribute_name'],
                            'source_value' => $row['source_value'],
                            'mapped_value' => $forcedOverride,
                        ];

                        continue;
                    }

                    $mappingErrors[] = $this->error(
                        ValidationError::CODE_MISSING_VALUE_MAPPING,
                        sprintf('Required attribute [%s] is missing a value mapping.', $requiredAttribute->name),
                        [
                            'attribute_id' => $requiredAttribute->id,
                            'attribute_code' => $requiredAttribute->code,
                            'attribute_name' => $requiredAttribute->name,
                            'reason' => 'missing_value_mapping',
                            'source_attribute' => $row['source_attribute_name'],
                            'source_value' => $row['source_value'],
                        ]
                    );

                    $requiredDiagnostics[] = [
                        'attribute_id' => $requiredAttribute->id,
                        'attribute_code' => $requiredAttribute->code,
                        'attribute_name' => $requiredAttribute->name,
                        'status' => 'missing',
                        'failure_type' => 'missing_value_mapping',
                        'message' => 'Value mapping is missing.',
                        'source_attribute' => $row['source_attribute_name'],
                        'source_value' => $row['source_value'],
                        'mapped_value' => null,
                    ];

                    continue;
                }

                $requiredDiagnostics[] = [
                    'attribute_id' => $requiredAttribute->id,
                    'attribute_code' => $requiredAttribute->code,
                    'attribute_name' => $requiredAttribute->name,
                    'status' => 'ok',
                    'failure_type' => null,
                    'message' => 'Mapped and ready.',
                    'source_attribute' => $row['source_attribute_name'],
                    'source_value' => $row['source_value'],
                    'mapped_value' => $row['mapped_value'],
                ];
            }
        }

        $pictures = $this->fieldNormalizer->normalizePictures([
            ...($variant->images_json ?? []),
            ...($product->images_json ?? []),
            $product->primary_image_url,
        ]);

        $normalizedVendor = $this->fieldNormalizer->normalizeVendor($product->vendor, $product->brand);
        $normalizedArticle = $this->fieldNormalizer->normalizeArticle($product->article);
        $normalizedColor = $this->fieldNormalizer->normalizeColor(
            $this->firstAttributeValue($mappedAttributes, ['color', 'colour']) ?: $variant->color
        );
        $normalizedSize = $this->fieldNormalizer->normalizeSize(
            $this->firstAttributeValue($mappedAttributes, ['size']) ?: $variant->size
        );

        if ($normalizedVendor['value'] === null) {
            $conformanceErrors[] = $this->error(ValidationError::CODE_INVALID_VENDOR, 'Vendor/brand cannot be normalized for export.');
        }

        if ($normalizedArticle['value'] === null) {
            $conformanceErrors[] = $this->error(ValidationError::CODE_INVALID_ARTICLE, 'Vendor code/article cannot be normalized for export.');
        }

        if ($normalizedColor['value'] === null) {
            $conformanceErrors[] = $this->error(ValidationError::CODE_INVALID_COLOR, 'Color is required for Kasta export.');
        }

        if ($normalizedSize['value'] === null) {
            $conformanceErrors[] = $this->error(ValidationError::CODE_INVALID_SIZE, 'Size is required for Kasta export.');
        }

        $invalidPictures = collect($pictures)
            ->reject(fn (string $picture) => filter_var($picture, FILTER_VALIDATE_URL) !== false)
            ->values()
            ->all();

        if ($invalidPictures !== []) {
            $conformanceErrors[] = $this->error(
                ValidationError::CODE_INVALID_IMAGE_URL,
                'Some export images are not valid URLs.',
                ['invalid_pictures' => $invalidPictures]
            );
        }

        $duplicateStableOfferId = blank($variant->stable_offer_id)
            || blank($variant->export_key_hash)
            || SourceVariant::query()
                ->where('shop_id', $variant->shop_id)
                ->where('stable_offer_id', $variant->stable_offer_id)
                ->whereKeyNot($variant->id)
                ->exists();

        if ($duplicateStableOfferId || ($variant->published_export_key_hash !== null && $variant->published_export_key_hash !== $variant->export_key_hash)) {
            $conformanceErrors[] = $this->error(
                ValidationError::CODE_DUPLICATED_OR_UNSTABLE_OFFER_ID,
                'Export key is blank, duplicated, or changed after publication.',
                [
                    'stable_offer_id' => $variant->stable_offer_id,
                    'export_key_hash' => $variant->export_key_hash,
                    'published_export_key_hash' => $variant->published_export_key_hash,
                ]
            );
        }

        $normalizedExportSnapshot = [
            'offer_id' => $variant->stable_offer_id,
            'available' => (bool) $variant->is_available,
            'name' => $variant->title ?: $product->name,
            'price' => $variant->price !== null ? number_format((float) $variant->price, 2, '.', '') : null,
            'currency' => $variant->currency ?: $feedProfile->currency ?: 'UAH',
            'category_id' => $mappedCategory?->external_id,
            'category_name' => $mappedCategory?->full_path ?: $mappedCategory?->name,
            'vendor' => $normalizedVendor['value'],
            'vendor_code' => $normalizedArticle['value'],
            'color' => $normalizedColor['value'],
            'size' => $normalizedSize['value'],
            'pictures' => $pictures,
            'description' => Canonicalizer::normalizeText($product->description),
            'params' => $mappedAttributes,
            'export_key' => $variant->stable_offer_id,
            'export_key_hash' => $variant->export_key_hash,
            'published_export_key_hash' => $variant->published_export_key_hash,
            'canonical_identity' => [
                'vendor' => $normalizedVendor['key'],
                'article' => $normalizedArticle['key'],
                'color' => $normalizedColor['key'],
                'size' => $normalizedSize['key'],
            ],
        ];

        $attributeRows = $mappingRows
            ->map(fn (array $row) => [
                'source_attribute' => $row['source_attribute_name'],
                'source_value' => $row['source_value'],
                'kasta_attribute' => $row['kasta_attribute_name'],
                'kasta_attribute_code' => $row['kasta_attribute_code'],
                'target_value' => $row['mapped_value'],
                'resolution' => $row['resolution'],
            ])
            ->values()
            ->all();

        return [
            'source_errors' => array_values($sourceErrors),
            'mapping_errors' => $mappingErrors,
            'conformance_errors' => $conformanceErrors,
            'mapped_category' => $mappedCategory === null ? null : [
                'id' => $mappedCategory->id,
                'external_id' => $mappedCategory->external_id,
                'name' => $mappedCategory->name,
                'full_path' => $mappedCategory->full_path,
            ],
            'mapped_attributes' => $mappedAttributes,
            'attribute_rows' => $attributeRows,
            'required_attribute_diagnostics' => $requiredDiagnostics,
            'normalized_export_snapshot' => $normalizedExportSnapshot,
            'diagnostics_summary' => [
                'source_error_count' => count($sourceErrors),
                'mapping_error_count' => count($mappingErrors),
                'conformance_error_count' => count($conformanceErrors),
                'missing_required_attributes' => collect($requiredDiagnostics)->where('status', 'missing')->count(),
                'operator_summary' => $this->operatorSummary($sourceErrors, $mappingErrors, $conformanceErrors, $requiredDiagnostics, $feedItem),
            ],
        ];
    }

    /**
     * @param  array<string, string>  $mappedAttributes
     * @param  list<string>  $candidateKeys
     */
    private function firstAttributeValue(array $mappedAttributes, array $candidateKeys): ?string
    {
        $dictionary = collect($mappedAttributes)
            ->mapWithKeys(fn ($value, $key) => [Canonicalizer::normalizeKey((string) $key) => $value]);

        foreach ($candidateKeys as $candidateKey) {
            $value = $dictionary->get(Canonicalizer::normalizeKey($candidateKey));

            if (is_string($value) && Canonicalizer::normalizeText($value) !== null) {
                return Canonicalizer::normalizeText($value);
            }
        }

        return null;
    }

    /**
     * @param  list<array{code:string,message:string,payload:array<string,mixed>}>  $sourceErrors
     * @param  list<array{code:string,message:string,payload:array<string,mixed>}>  $mappingErrors
     * @param  list<array{code:string,message:string,payload:array<string,mixed>}>  $conformanceErrors
     * @param  array<int, array<string, mixed>>  $requiredDiagnostics
     * @return array<string, mixed>
     */
    private function operatorSummary(
        array $sourceErrors,
        array $mappingErrors,
        array $conformanceErrors,
        array $requiredDiagnostics,
        ?FeedItem $feedItem = null
    ): array {
        $headline = 'Item is ready for export.';

        if ($sourceErrors !== []) {
            $headline = 'Source data is incomplete. Fix product or variant data before export.';
        } elseif ($mappingErrors !== []) {
            $headline = 'Mappings are incomplete. Finish category, attribute, or value mappings.';
        } elseif ($conformanceErrors !== []) {
            $headline = 'Export conformance failed. Normalize export fields before publish.';
        } elseif ($feedItem?->status === FeedItem::STATUS_EXCLUDED) {
            $headline = 'Item is excluded and will not be exported.';
        }

        return [
            'headline' => $headline,
            'missing_required_attributes' => collect($requiredDiagnostics)
                ->where('status', 'missing')
                ->map(fn (array $diagnostic) => [
                    'attribute_name' => $diagnostic['attribute_name'],
                    'failure_type' => $diagnostic['failure_type'],
                    'message' => $diagnostic['message'],
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{code:string,message:string,payload:array<string,mixed>}
     */
    private function error(string $code, string $message, array $payload = []): array
    {
        return [
            'code' => $code,
            'message' => $message,
            'payload' => $payload,
        ];
    }
}
