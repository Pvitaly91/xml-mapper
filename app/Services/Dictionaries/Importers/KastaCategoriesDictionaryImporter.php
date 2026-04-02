<?php

namespace App\Services\Dictionaries\Importers;

use App\Contracts\Dictionaries\DictionaryTypeImporterInterface;
use App\Data\Dictionaries\DictionaryImportOptions;
use App\Data\Dictionaries\DictionaryImportResult;
use App\Models\DictionaryImport;
use App\Models\KastaCategory;
use Illuminate\Support\Collection;

class KastaCategoriesDictionaryImporter extends AbstractDictionaryTypeImporter implements DictionaryTypeImporterInterface
{
    public function type(): string
    {
        return DictionaryImport::TYPE_KASTA_CATEGORIES;
    }

    public function import(iterable $rows, DictionaryImportOptions $options): DictionaryImportResult
    {
        $rows = $this->rows($rows);
        $preparedRows = [];

        foreach ($rows as $row) {
            $preparedRows[] = [
                'external_id' => (string) $this->required($row, 'external_id'),
                'parent_external_id' => $this->nullableString($row['parent_external_id'] ?? null),
                'name' => (string) $this->required($row, 'name'),
                'full_path' => $this->nullableString($row['full_path'] ?? null),
                'rz_id' => $this->nullableString($row['rz_id'] ?? null),
                'is_active' => $this->boolean($row['is_active'] ?? null, true),
                'metadata' => $this->jsonField($row, 'metadata', 'metadata_json'),
            ];
        }

        $preparedMap = collect($preparedRows)->keyBy('external_id');
        $existing = KastaCategory::query()->with('parent')->get()->keyBy('external_id');
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $seenKeys = [];

        foreach ($preparedRows as $row) {
            $row['resolved_full_path'] = $row['full_path'] ?? $this->buildPath($row, $preparedMap, $existing);
            $seenKeys[] = $row['external_id'];
            $current = $existing->get($row['external_id']);

            if (! $current instanceof KastaCategory) {
                $created++;
            } elseif ($this->hasChanges($current, $row)) {
                $updated++;
            } else {
                $skipped++;
            }

            if ($options->dryRun) {
                continue;
            }

            $category = $current instanceof KastaCategory
                ? $current
                : new KastaCategory(['external_id' => $row['external_id']]);

            $category->fill([
                'name' => $row['name'],
                'rz_id' => $row['rz_id'],
                'is_active' => $row['is_active'],
                'metadata' => $row['metadata'],
            ]);

            if (! $category->exists || $category->isDirty()) {
                $category->save();
            }

            $existing->put($row['external_id'], $category);
        }

        if (! $options->dryRun) {
            foreach ($preparedRows as $row) {
                $category = $existing->get($row['external_id']);
                $parent = $row['parent_external_id'] ? $existing->get($row['parent_external_id']) : null;
                $resolvedPath = $row['full_path'] ?? $this->buildPath($row, $preparedMap, $existing);

                if (! $category instanceof KastaCategory) {
                    continue;
                }

                $category->forceFill([
                    'parent_id' => $parent?->id,
                    'full_path' => $resolvedPath,
                ]);

                if ($category->isDirty()) {
                    $category->save();
                }
            }
        }

        $deactivated = 0;

        if ($options->deactivateMissing) {
            $missingIds = $existing
                ->filter(fn (KastaCategory $category, string $externalId) => ! in_array($externalId, $seenKeys, true) && $category->is_active)
                ->pluck('id')
                ->all();

            $deactivated = count($missingIds);

            if (! $options->dryRun && $missingIds !== []) {
                KastaCategory::query()->whereIn('id', $missingIds)->update(['is_active' => false]);
            }
        }

        return new DictionaryImportResult(count($preparedRows), $created, $updated, $skipped, $deactivated);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  Collection<string, array<string, mixed>>  $preparedMap
     * @param  Collection<string, KastaCategory>  $existing
     */
    private function buildPath(array $row, Collection $preparedMap, Collection $existing, array $trail = []): string
    {
        $parentExternalId = $row['parent_external_id'];

        if ($parentExternalId === null) {
            return $row['name'];
        }

        if (in_array($row['external_id'], $trail, true)) {
            return $row['name'];
        }

        if ($preparedMap->has($parentExternalId)) {
            $parentRow = $preparedMap->get($parentExternalId);

            return $this->buildPath($parentRow, $preparedMap, $existing, [...$trail, $row['external_id']]).' > '.$row['name'];
        }

        $parent = $existing->get($parentExternalId);

        if ($parent instanceof KastaCategory) {
            return ($parent->full_path ?: $parent->name).' > '.$row['name'];
        }

        return $row['name'];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function hasChanges(KastaCategory $current, array $row): bool
    {
        return ! $this->valuesEqual($current->name, $row['name'])
            || ! $this->valuesEqual($current->rz_id, $row['rz_id'])
            || ! $this->valuesEqual($current->is_active, $row['is_active'])
            || ! $this->valuesEqual($current->metadata, $row['metadata'])
            || ! $this->valuesEqual($current->full_path, $row['resolved_full_path'])
            || ! $this->valuesEqual($current->parent?->external_id, $row['parent_external_id']);
    }
}
