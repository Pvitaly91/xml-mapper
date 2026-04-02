<?php

namespace App\Services\Dictionaries\Importers;

use App\Contracts\Dictionaries\DictionaryTypeImporterInterface;
use App\Data\Dictionaries\DictionaryImportOptions;
use App\Data\Dictionaries\DictionaryImportResult;
use App\Models\DictionaryImport;
use App\Models\KastaAttribute;
use App\Models\KastaCategory;
use RuntimeException;

class KastaAttributesDictionaryImporter extends AbstractDictionaryTypeImporter implements DictionaryTypeImporterInterface
{
    public function type(): string
    {
        return DictionaryImport::TYPE_KASTA_ATTRIBUTES;
    }

    public function import(iterable $rows, DictionaryImportOptions $options): DictionaryImportResult
    {
        $rows = $this->rows($rows);
        $categoryMap = KastaCategory::query()->get()->keyBy('external_id');
        $existing = KastaAttribute::query()->get()->keyBy(fn (KastaAttribute $attribute) => $this->compositeKey((string) $attribute->kasta_category_id, $attribute->code));
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $seenKeys = [];

        foreach ($rows as $row) {
            $categoryExternalId = (string) $this->required($row, 'kasta_category_external_id');
            $category = $categoryMap->get($categoryExternalId);

            if (! $category instanceof KastaCategory) {
                throw new RuntimeException(sprintf('Unknown category external ID [%s] for attribute import.', $categoryExternalId));
            }

            $payload = [
                'external_id' => $this->nullableString($row['external_id'] ?? null),
                'name' => (string) $this->required($row, 'name'),
                'code' => (string) $this->required($row, 'code'),
                'data_type' => (string) ($row['data_type'] ?? 'string'),
                'is_required' => $this->boolean($row['is_required'] ?? null, false),
                'allows_custom_value' => $this->boolean($row['allows_custom_value'] ?? null, true),
                'is_active' => $this->boolean($row['is_active'] ?? null, true),
                'sort_order' => $this->integer($row['sort_order'] ?? null, 0),
            ];

            $key = $this->compositeKey((string) $category->id, $payload['code']);
            $seenKeys[] = $key;
            $current = $existing->get($key);

            if (! $current instanceof KastaAttribute) {
                $created++;
            } elseif ($this->hasChanges($current, $payload)) {
                $updated++;
            } else {
                $skipped++;
            }

            if ($options->dryRun) {
                continue;
            }

            $attribute = $current instanceof KastaAttribute
                ? $current
                : new KastaAttribute(['kasta_category_id' => $category->id, 'code' => $payload['code']]);

            $attribute->fill([
                'external_id' => $payload['external_id'],
                'name' => $payload['name'],
                'data_type' => $payload['data_type'],
                'is_required' => $payload['is_required'],
                'allows_custom_value' => $payload['allows_custom_value'],
                'is_active' => $payload['is_active'],
                'sort_order' => $payload['sort_order'],
            ]);

            if (! $attribute->exists || $attribute->isDirty()) {
                $attribute->save();
            }

            $existing->put($key, $attribute);
        }

        $deactivated = 0;

        if ($options->deactivateMissing) {
            $missingIds = $existing
                ->filter(fn (KastaAttribute $attribute, string $key) => ! in_array($key, $seenKeys, true) && $attribute->is_active)
                ->pluck('id')
                ->all();

            $deactivated = count($missingIds);

            if (! $options->dryRun && $missingIds !== []) {
                KastaAttribute::query()->whereIn('id', $missingIds)->update(['is_active' => false]);
            }
        }

        return new DictionaryImportResult(count($rows), $created, $updated, $skipped, $deactivated);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function hasChanges(KastaAttribute $current, array $payload): bool
    {
        return ! $this->valuesEqual($current->external_id, $payload['external_id'])
            || ! $this->valuesEqual($current->name, $payload['name'])
            || ! $this->valuesEqual($current->data_type, $payload['data_type'])
            || ! $this->valuesEqual($current->is_required, $payload['is_required'])
            || ! $this->valuesEqual($current->allows_custom_value, $payload['allows_custom_value'])
            || ! $this->valuesEqual($current->is_active, $payload['is_active'])
            || ! $this->valuesEqual($current->sort_order, $payload['sort_order']);
    }
}
