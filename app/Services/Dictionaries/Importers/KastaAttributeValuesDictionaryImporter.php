<?php

namespace App\Services\Dictionaries\Importers;

use App\Contracts\Dictionaries\DictionaryTypeImporterInterface;
use App\Data\Dictionaries\DictionaryImportOptions;
use App\Data\Dictionaries\DictionaryImportResult;
use App\Models\DictionaryImport;
use App\Models\KastaAttribute;
use App\Models\KastaAttributeValue;
use App\Support\Canonicalizer;
use RuntimeException;

class KastaAttributeValuesDictionaryImporter extends AbstractDictionaryTypeImporter implements DictionaryTypeImporterInterface
{
    public function type(): string
    {
        return DictionaryImport::TYPE_KASTA_ATTRIBUTE_VALUES;
    }

    public function import(iterable $rows, DictionaryImportOptions $options): DictionaryImportResult
    {
        $rows = $this->rows($rows);
        $attributeMap = KastaAttribute::query()
            ->with('kastaCategory')
            ->get()
            ->keyBy(fn (KastaAttribute $attribute) => $this->compositeKey($attribute->kastaCategory->external_id, $attribute->code));
        $existing = KastaAttributeValue::query()
            ->with('kastaAttribute.kastaCategory')
            ->get()
            ->keyBy(fn (KastaAttributeValue $value) => $this->compositeKey(
                $value->kastaAttribute->kastaCategory->external_id,
                $value->kastaAttribute->code,
                $value->value_hash
            ));
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $seenKeys = [];

        foreach ($rows as $row) {
            $categoryExternalId = (string) $this->required($row, 'kasta_category_external_id');
            $attributeCode = (string) $this->required($row, 'kasta_attribute_code');
            $attribute = $attributeMap->get($this->compositeKey($categoryExternalId, $attributeCode));

            if (! $attribute instanceof KastaAttribute) {
                throw new RuntimeException(sprintf('Unknown attribute [%s] in category [%s] for value import.', $attributeCode, $categoryExternalId));
            }

            $value = (string) $this->required($row, 'value');
            $payload = [
                'external_id' => $this->nullableString($row['external_id'] ?? null),
                'value' => $value,
                'normalized_value' => Canonicalizer::normalizeText(mb_strtolower($value)),
                'is_active' => $this->boolean($row['is_active'] ?? null, true),
                'sort_order' => $this->integer($row['sort_order'] ?? null, 0),
            ];

            $key = $this->compositeKey($categoryExternalId, $attributeCode, Canonicalizer::fingerprint($value));
            $seenKeys[] = $key;
            $current = $existing->get($key);

            if (! $current instanceof KastaAttributeValue) {
                $created++;
            } elseif ($this->hasChanges($current, $payload)) {
                $updated++;
            } else {
                $skipped++;
            }

            if ($options->dryRun) {
                continue;
            }

            $attributeValue = $current instanceof KastaAttributeValue
                ? $current
                : new KastaAttributeValue([
                    'kasta_attribute_id' => $attribute->id,
                    'value_hash' => Canonicalizer::fingerprint($value),
                ]);

            $attributeValue->fill([
                'external_id' => $payload['external_id'],
                'value' => $payload['value'],
                'normalized_value' => $payload['normalized_value'],
                'is_active' => $payload['is_active'],
                'sort_order' => $payload['sort_order'],
            ]);

            if (! $attributeValue->exists || $attributeValue->isDirty()) {
                $attributeValue->save();
            }

            $existing->put($key, $attributeValue);
        }

        $deactivated = 0;

        if ($options->deactivateMissing) {
            $missingIds = $existing
                ->filter(fn (KastaAttributeValue $value, string $key) => ! in_array($key, $seenKeys, true) && $value->is_active)
                ->pluck('id')
                ->all();

            $deactivated = count($missingIds);

            if (! $options->dryRun && $missingIds !== []) {
                KastaAttributeValue::query()->whereIn('id', $missingIds)->update(['is_active' => false]);
            }
        }

        return new DictionaryImportResult(count($rows), $created, $updated, $skipped, $deactivated);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function hasChanges(KastaAttributeValue $current, array $payload): bool
    {
        return ! $this->valuesEqual($current->external_id, $payload['external_id'])
            || ! $this->valuesEqual($current->value, $payload['value'])
            || ! $this->valuesEqual($current->normalized_value, $payload['normalized_value'])
            || ! $this->valuesEqual($current->is_active, $payload['is_active'])
            || ! $this->valuesEqual($current->sort_order, $payload['sort_order']);
    }
}
