<?php

namespace App\Services\Dictionaries;

use App\Contracts\Dictionaries\DictionaryReaderInterface;
use App\Contracts\Dictionaries\DictionaryTypeImporterInterface;
use App\Contracts\Dictionaries\KastaDictionaryImportServiceInterface;
use App\Data\Dictionaries\DictionaryImportOptions;
use App\Models\DictionaryImport;
use App\Services\Dictionaries\Importers\KastaAttributesDictionaryImporter;
use App\Services\Dictionaries\Importers\KastaAttributeValuesDictionaryImporter;
use App\Services\Dictionaries\Importers\KastaCategoriesDictionaryImporter;
use App\Services\Dictionaries\Importers\SizeGridsDictionaryImporter;
use App\Services\Dictionaries\Readers\CsvDictionaryReader;
use App\Services\Dictionaries\Readers\JsonDictionaryReader;
use App\Services\Ops\ProcessLockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class KastaDictionaryImportService implements KastaDictionaryImportServiceInterface
{
    /**
     * @var array<string, DictionaryReaderInterface>
     */
    private array $readers;

    /**
     * @var array<string, DictionaryTypeImporterInterface>
     */
    private array $importers;

    public function __construct(
        JsonDictionaryReader $jsonReader,
        CsvDictionaryReader $csvReader,
        KastaCategoriesDictionaryImporter $categoriesImporter,
        KastaAttributesDictionaryImporter $attributesImporter,
        KastaAttributeValuesDictionaryImporter $attributeValuesImporter,
        SizeGridsDictionaryImporter $sizeGridsImporter,
        private readonly ProcessLockService $lockService,
    ) {
        $this->readers = [
            $jsonReader->format() => $jsonReader,
            $csvReader->format() => $csvReader,
        ];

        $this->importers = [
            $categoriesImporter->type() => $categoriesImporter,
            $attributesImporter->type() => $attributesImporter,
            $attributeValuesImporter->type() => $attributeValuesImporter,
            $sizeGridsImporter->type() => $sizeGridsImporter,
        ];
    }

    public function import(DictionaryImportOptions $options): DictionaryImport
    {
        $sourcePath = $this->resolveSourcePath($options);
        $format = $this->resolveFormat($options, $sourcePath);
        $checksum = hash_file('sha256', $sourcePath);

        if (! is_string($checksum)) {
            throw new RuntimeException(sprintf('Unable to calculate checksum for [%s].', $sourcePath));
        }

        $storedPath = $this->storeSourceFile(
            $sourcePath,
            $options->type,
            $checksum,
            $format,
            $options->originalFilename ?? basename($sourcePath)
        );

        return $this->lockService->runExclusive(
            $this->lockService->dictionaryImportKey($options->type, $checksum),
            (int) config('feed_mediator.locks.dictionary_import_ttl_seconds'),
            'Dictionary import for this file is already in progress.',
            function () use ($options, $format, $checksum, $storedPath, $sourcePath): DictionaryImport {
                if (! $options->dryRun && ! $options->allowDuplicateChecksum) {
                    $duplicate = DictionaryImport::query()
                        ->where('type', $options->type)
                        ->where('checksum', $checksum)
                        ->where('dry_run', false)
                        ->where('status', DictionaryImport::STATUS_COMPLETED)
                        ->latest('id')
                        ->first();

                    if ($duplicate instanceof DictionaryImport) {
                        return DictionaryImport::create([
                            'type' => $options->type,
                            'source_path' => $storedPath,
                            'original_filename' => $options->originalFilename ?? basename($sourcePath),
                            'source_format' => $format,
                            'checksum' => $checksum,
                            'rows_total' => $duplicate->rows_total,
                            'created_count' => 0,
                            'updated_count' => 0,
                            'skipped_count' => $duplicate->rows_total,
                            'deactivated_count' => 0,
                            'dry_run' => false,
                            'status' => DictionaryImport::STATUS_SKIPPED,
                            'started_at' => now(),
                            'finished_at' => now(),
                            'metadata' => [
                                'duplicate_of_import_id' => $duplicate->id,
                                'deactivate_missing' => $options->deactivateMissing,
                            ],
                            'initiated_by_user_id' => $options->initiatedByUserId,
                        ]);
                    }
                }

                $import = DictionaryImport::create([
                    'type' => $options->type,
                    'source_path' => $storedPath,
                    'original_filename' => $options->originalFilename ?? basename($sourcePath),
                    'source_format' => $format,
                    'checksum' => $checksum,
                    'dry_run' => $options->dryRun,
                    'status' => DictionaryImport::STATUS_RUNNING,
                    'started_at' => now(),
                    'metadata' => [
                        'deactivate_missing' => $options->deactivateMissing,
                        'provided_source_path' => $sourcePath,
                    ],
                    'initiated_by_user_id' => $options->initiatedByUserId,
                ]);

                try {
                    $reader = $this->readerFor($format);
                    $importer = $this->importerFor($options->type);
                    $absoluteStoredPath = Storage::disk(config('feed_mediator.storage_disk'))->path($storedPath);
                    $execute = fn () => $importer->import($reader->read($absoluteStoredPath), $options);
                    $result = $options->dryRun ? $execute() : DB::transaction($execute);

                    $import->update([
                        'rows_total' => $result->rowsTotal,
                        'created_count' => $result->createdCount,
                        'updated_count' => $result->updatedCount,
                        'skipped_count' => $result->skippedCount,
                        'deactivated_count' => $result->deactivatedCount,
                        'status' => DictionaryImport::STATUS_COMPLETED,
                        'finished_at' => now(),
                        'error_summary' => null,
                        'metadata' => array_merge($import->metadata ?? [], $result->metadata),
                    ]);

                    return $import->refresh();
                } catch (Throwable $exception) {
                    $import->update([
                        'status' => DictionaryImport::STATUS_FAILED,
                        'finished_at' => now(),
                        'error_summary' => $exception->getMessage(),
                    ]);

                    throw $exception;
                }
            }
        );
    }

    public function reimportLatest(string $type, bool $dryRun = false, bool $deactivateMissing = false, ?int $initiatedByUserId = null): DictionaryImport
    {
        $latest = DictionaryImport::query()
            ->where('type', $type)
            ->where('status', '!=', DictionaryImport::STATUS_RUNNING)
            ->latest('id')
            ->first();

        if (! $latest instanceof DictionaryImport) {
            throw new RuntimeException(sprintf('No previous dictionary import found for type [%s].', $type));
        }

        return $this->import(new DictionaryImportOptions(
            type: $type,
            filePath: Storage::disk(config('feed_mediator.storage_disk'))->path($latest->source_path),
            format: $latest->source_format,
            dryRun: $dryRun,
            deactivateMissing: $deactivateMissing,
            allowDuplicateChecksum: true,
            initiatedByUserId: $initiatedByUserId,
            originalFilename: $latest->original_filename,
        ));
    }

    public function importBundle(?string $directory = null, ?int $initiatedByUserId = null): array
    {
        $directory ??= (string) config('feed_mediator.kasta_dictionary_stub_path');
        $summary = [
            'categories' => 0,
            'attributes' => 0,
            'attribute_values' => 0,
            'size_grids' => 0,
        ];

        foreach ($this->legacyBundleFileMap() as $type => $filename) {
            $path = rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$filename;

            if (! is_file($path)) {
                continue;
            }

            $import = $this->import(new DictionaryImportOptions(
                type: $type,
                filePath: $path,
                format: 'json',
                initiatedByUserId: $initiatedByUserId,
            ));

            $summary[$this->summaryKey($type)] = $import->rows_total;
        }

        return $summary;
    }

    public function supportedTypes(): array
    {
        return array_keys($this->importers);
    }

    public function supportedFormats(): array
    {
        return array_keys($this->readers);
    }

    public function sampleFileFor(string $type, string $format = 'json'): string
    {
        return rtrim((string) config('feed_mediator.kasta_dictionary_sample_path'), DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR
            .$type.'.'.$format;
    }

    private function resolveSourcePath(DictionaryImportOptions $options): string
    {
        $path = $options->filePath ?: $this->sampleFileFor($options->type, $options->format ?: 'json');

        if (! is_file($path)) {
            throw new RuntimeException(sprintf('Dictionary source file [%s] does not exist.', $path));
        }

        return $path;
    }

    private function resolveFormat(DictionaryImportOptions $options, string $sourcePath): string
    {
        $format = $options->format ?: strtolower((string) pathinfo($sourcePath, PATHINFO_EXTENSION));

        if (! isset($this->readers[$format])) {
            throw new RuntimeException(sprintf('Unsupported dictionary format [%s].', $format));
        }

        return $format;
    }

    private function readerFor(string $format): DictionaryReaderInterface
    {
        return $this->readers[$format];
    }

    private function importerFor(string $type): DictionaryTypeImporterInterface
    {
        if (! isset($this->importers[$type])) {
            throw new RuntimeException(sprintf('Unsupported dictionary type [%s].', $type));
        }

        return $this->importers[$type];
    }

    private function storeSourceFile(string $sourcePath, string $type, string $checksum, string $format, string $originalFilename): string
    {
        $disk = Storage::disk(config('feed_mediator.storage_disk'));
        $slug = Str::slug(pathinfo($originalFilename, PATHINFO_FILENAME));
        $slug = $slug !== '' ? $slug : $type;
        $relativePath = trim((string) config('feed_mediator.kasta_dictionary_storage_directory'), '/')
            .'/'.$type.'/'.now()->format('Y/m/d').'/'.$slug.'-'.$checksum.'.'.$format;

        if (! $disk->exists($relativePath)) {
            $stream = fopen($sourcePath, 'rb');

            if ($stream === false) {
                throw new RuntimeException(sprintf('Unable to open source file [%s].', $sourcePath));
            }

            try {
                $disk->put($relativePath, $stream);
            } finally {
                fclose($stream);
            }
        }

        return $relativePath;
    }

    /**
     * @return array<string, string>
     */
    private function legacyBundleFileMap(): array
    {
        return [
            DictionaryImport::TYPE_KASTA_CATEGORIES => 'categories.json',
            DictionaryImport::TYPE_KASTA_ATTRIBUTES => 'attributes.json',
            DictionaryImport::TYPE_KASTA_ATTRIBUTE_VALUES => 'attribute_values.json',
            DictionaryImport::TYPE_SIZE_GRIDS => 'size_grids.json',
        ];
    }

    private function summaryKey(string $type): string
    {
        return match ($type) {
            DictionaryImport::TYPE_KASTA_CATEGORIES => 'categories',
            DictionaryImport::TYPE_KASTA_ATTRIBUTES => 'attributes',
            DictionaryImport::TYPE_KASTA_ATTRIBUTE_VALUES => 'attribute_values',
            DictionaryImport::TYPE_SIZE_GRIDS => 'size_grids',
            default => throw new RuntimeException(sprintf('Unsupported dictionary type [%s].', $type)),
        };
    }
}
