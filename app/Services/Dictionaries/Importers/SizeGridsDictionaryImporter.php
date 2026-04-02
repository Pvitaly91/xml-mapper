<?php

namespace App\Services\Dictionaries\Importers;

use App\Contracts\Dictionaries\DictionaryTypeImporterInterface;
use App\Data\Dictionaries\DictionaryImportOptions;
use App\Data\Dictionaries\DictionaryImportResult;
use App\Models\DictionaryImport;
use App\Models\Shop;
use App\Models\SizeGrid;
use RuntimeException;

class SizeGridsDictionaryImporter extends AbstractDictionaryTypeImporter implements DictionaryTypeImporterInterface
{
    public function type(): string
    {
        return DictionaryImport::TYPE_SIZE_GRIDS;
    }

    public function import(iterable $rows, DictionaryImportOptions $options): DictionaryImportResult
    {
        $rows = $this->rows($rows);
        $shopSlugs = collect($rows)->map(fn (array $row) => $this->nullableString($row['shop_slug'] ?? null))->filter()->unique()->values();
        $shopMap = Shop::query()->whereIn('slug', $shopSlugs)->get()->keyBy('slug');
        $existing = SizeGrid::query()->get()->keyBy(fn (SizeGrid $sizeGrid) => $this->compositeKey((string) ($sizeGrid->shop_id ?? 0), $sizeGrid->code));
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $seenKeys = [];

        foreach ($rows as $row) {
            $shopSlug = $this->nullableString($row['shop_slug'] ?? null);
            $shopId = null;

            if ($shopSlug !== null) {
                $shop = $shopMap->get($shopSlug);

                if (! $shop instanceof Shop) {
                    throw new RuntimeException(sprintf('Unknown shop slug [%s] for size grid import.', $shopSlug));
                }

                $shopId = $shop->id;
            }

            $payload = [
                'code' => (string) $this->required($row, 'code'),
                'name' => (string) $this->required($row, 'name'),
                'schema' => $this->jsonField($row, 'schema', 'schema_json'),
                'is_active' => $this->boolean($row['is_active'] ?? null, true),
            ];

            $key = $this->compositeKey((string) ($shopId ?? 0), $payload['code']);
            $seenKeys[] = $key;
            $current = $existing->get($key);

            if (! $current instanceof SizeGrid) {
                $created++;
            } elseif ($this->hasChanges($current, $payload)) {
                $updated++;
            } else {
                $skipped++;
            }

            if ($options->dryRun) {
                continue;
            }

            $sizeGrid = $current instanceof SizeGrid
                ? $current
                : new SizeGrid(['shop_id' => $shopId, 'code' => $payload['code']]);

            $sizeGrid->fill([
                'name' => $payload['name'],
                'schema' => $payload['schema'],
                'is_active' => $payload['is_active'],
            ]);

            if (! $sizeGrid->exists || $sizeGrid->isDirty()) {
                $sizeGrid->save();
            }

            $existing->put($key, $sizeGrid);
        }

        $deactivated = 0;

        if ($options->deactivateMissing) {
            $missingIds = $existing
                ->filter(fn (SizeGrid $sizeGrid, string $key) => ! in_array($key, $seenKeys, true) && $sizeGrid->is_active)
                ->pluck('id')
                ->all();

            $deactivated = count($missingIds);

            if (! $options->dryRun && $missingIds !== []) {
                SizeGrid::query()->whereIn('id', $missingIds)->update(['is_active' => false]);
            }
        }

        return new DictionaryImportResult(count($rows), $created, $updated, $skipped, $deactivated);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function hasChanges(SizeGrid $current, array $payload): bool
    {
        return ! $this->valuesEqual($current->name, $payload['name'])
            || ! $this->valuesEqual($current->schema, $payload['schema'])
            || ! $this->valuesEqual($current->is_active, $payload['is_active']);
    }
}
