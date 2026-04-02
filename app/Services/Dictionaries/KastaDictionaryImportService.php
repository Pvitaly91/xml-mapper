<?php

namespace App\Services\Dictionaries;

use App\Contracts\Dictionaries\KastaDictionaryImportServiceInterface;
use App\Models\KastaAttribute;
use App\Models\KastaAttributeValue;
use App\Models\KastaCategory;
use App\Models\Shop;
use App\Models\SizeGrid;
use App\Support\Canonicalizer;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class KastaDictionaryImportService implements KastaDictionaryImportServiceInterface
{
    public function import(?string $directory = null): array
    {
        $directory ??= (string) config('feed_mediator.kasta_dictionary_stub_path');

        if (! is_dir($directory)) {
            throw new RuntimeException(sprintf('Dictionary directory [%s] does not exist.', $directory));
        }

        $categories = $this->readJson($directory.'/categories.json');
        $attributes = $this->readJson($directory.'/attributes.json');
        $attributeValues = $this->readJson($directory.'/attribute_values.json');
        $sizeGrids = $this->readJson($directory.'/size_grids.json');

        return DB::transaction(function () use ($categories, $attributes, $attributeValues, $sizeGrids): array {
            $categoryMap = $this->importCategories($categories);
            $attributeMap = $this->importAttributes($attributes, $categoryMap);
            $this->importAttributeValues($attributeValues, $attributeMap);
            $this->importSizeGrids($sizeGrids);

            return [
                'categories' => count($categories),
                'attributes' => count($attributes),
                'attribute_values' => count($attributeValues),
                'size_grids' => count($sizeGrids),
            ];
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $payload
     * @return array<string, KastaCategory>
     */
    private function importCategories(array $payload): array
    {
        foreach ($payload as $row) {
            $category = KastaCategory::updateOrCreate(
                ['external_id' => (string) $this->required($row, 'external_id')],
                [
                    'name' => (string) $this->required($row, 'name'),
                    'full_path' => $this->nullableString($row['full_path'] ?? null),
                    'rz_id' => $this->nullableString($row['rz_id'] ?? null),
                    'is_active' => (bool) ($row['is_active'] ?? true),
                    'metadata' => is_array($row['metadata'] ?? null) ? $row['metadata'] : null,
                ]
            );

            $category->parent_id = null;
            $category->save();
        }

        $map = KastaCategory::query()->get()->keyBy('external_id')->all();

        foreach ($payload as $row) {
            $externalId = (string) $this->required($row, 'external_id');
            $parentExternalId = $this->nullableString($row['parent_external_id'] ?? null);
            $category = $map[$externalId];

            $category->parent_id = $parentExternalId && isset($map[$parentExternalId])
                ? $map[$parentExternalId]->id
                : null;
            $category->full_path = $this->nullableString($row['full_path'] ?? null)
                ?? $this->buildCategoryPath($category, $map);
            $category->save();

            $map[$externalId] = $category->fresh();
        }

        return $map;
    }

    /**
     * @param  array<int, array<string, mixed>>  $payload
     * @param  array<string, KastaCategory>  $categoryMap
     * @return array<string, KastaAttribute>
     */
    private function importAttributes(array $payload, array $categoryMap): array
    {
        $map = [];

        foreach ($payload as $row) {
            $categoryExternalId = (string) $this->required($row, 'kasta_category_external_id');

            if (! isset($categoryMap[$categoryExternalId])) {
                throw new RuntimeException(sprintf('Unknown category external ID [%s] for attribute import.', $categoryExternalId));
            }

            $attribute = KastaAttribute::updateOrCreate(
                [
                    'kasta_category_id' => $categoryMap[$categoryExternalId]->id,
                    'code' => (string) $this->required($row, 'code'),
                ],
                [
                    'external_id' => $this->nullableString($row['external_id'] ?? null),
                    'name' => (string) $this->required($row, 'name'),
                    'data_type' => (string) ($row['data_type'] ?? 'string'),
                    'is_required' => (bool) ($row['is_required'] ?? false),
                    'allows_custom_value' => (bool) ($row['allows_custom_value'] ?? true),
                    'sort_order' => (int) ($row['sort_order'] ?? 0),
                ]
            );

            $map[$categoryExternalId.'|'.$attribute->code] = $attribute;
        }

        return $map;
    }

    /**
     * @param  array<int, array<string, mixed>>  $payload
     * @param  array<string, KastaAttribute>  $attributeMap
     */
    private function importAttributeValues(array $payload, array $attributeMap): void
    {
        foreach ($payload as $row) {
            $categoryExternalId = (string) $this->required($row, 'kasta_category_external_id');
            $attributeCode = (string) $this->required($row, 'kasta_attribute_code');
            $value = (string) $this->required($row, 'value');
            $key = $categoryExternalId.'|'.$attributeCode;

            if (! isset($attributeMap[$key])) {
                throw new RuntimeException(sprintf('Unknown attribute [%s] in category [%s] for value import.', $attributeCode, $categoryExternalId));
            }

            KastaAttributeValue::updateOrCreate(
                [
                    'kasta_attribute_id' => $attributeMap[$key]->id,
                    'value_hash' => Canonicalizer::fingerprint($value),
                ],
                [
                    'external_id' => $this->nullableString($row['external_id'] ?? null),
                    'value' => $value,
                    'normalized_value' => Canonicalizer::normalizeText(mb_strtolower($value)),
                    'sort_order' => (int) ($row['sort_order'] ?? 0),
                ]
            );
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $payload
     */
    private function importSizeGrids(array $payload): void
    {
        foreach ($payload as $row) {
            $shopSlug = $this->nullableString($row['shop_slug'] ?? null);
            $shopId = $shopSlug ? Shop::query()->where('slug', $shopSlug)->value('id') : null;

            SizeGrid::updateOrCreate(
                [
                    'shop_id' => $shopId,
                    'code' => (string) $this->required($row, 'code'),
                ],
                [
                    'name' => (string) $this->required($row, 'name'),
                    'schema' => is_array($row['schema'] ?? null) ? $row['schema'] : null,
                    'is_active' => (bool) ($row['is_active'] ?? true),
                ]
            );
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readJson(string $path): array
    {
        if (! file_exists($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new RuntimeException(sprintf('Dictionary file [%s] must decode into an array.', $path));
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function required(array $row, string $key): mixed
    {
        $value = $row[$key] ?? null;

        if ($value === null || $value === '') {
            throw new RuntimeException(sprintf('Dictionary row is missing required key [%s].', $key));
        }

        return $value;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        return Canonicalizer::normalizeText((string) $value);
    }

    /**
     * @param  array<string, KastaCategory>  $categoryMap
     */
    private function buildCategoryPath(KastaCategory $category, array $categoryMap): string
    {
        $segments = [$category->name];
        $seen = [$category->external_id => true];
        $parentId = $category->parent_id;

        while ($parentId !== null) {
            $parent = collect($categoryMap)->first(fn (KastaCategory $candidate) => $candidate->id === $parentId);

            if ($parent === null || isset($seen[$parent->external_id])) {
                break;
            }

            $seen[$parent->external_id] = true;
            array_unshift($segments, $parent->name);
            $parentId = $parent->parent_id;
        }

        return implode(' > ', $segments);
    }
}
