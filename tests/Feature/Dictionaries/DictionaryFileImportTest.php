<?php

namespace Tests\Feature\Dictionaries;

use App\Contracts\Dictionaries\KastaDictionaryImportServiceInterface;
use App\Data\Dictionaries\DictionaryImportOptions;
use App\Models\DictionaryImport;
use App\Models\KastaCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DictionaryFileImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_json_import_persists_dictionary_rows_and_history(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));

        $import = app(KastaDictionaryImportServiceInterface::class)->import(new DictionaryImportOptions(
            type: DictionaryImport::TYPE_KASTA_CATEGORIES,
            filePath: base_path('database/samples/kasta-dictionaries/kasta_categories.json'),
        ));

        $this->assertSame(DictionaryImport::STATUS_COMPLETED, $import->status);
        $this->assertSame(3, $import->rows_total);
        $this->assertDatabaseCount('kasta_categories', 3);
        $this->assertDatabaseHas('dictionary_imports', [
            'id' => $import->id,
            'type' => DictionaryImport::TYPE_KASTA_CATEGORIES,
            'source_format' => 'json',
        ]);
    }

    public function test_csv_import_persists_dictionary_rows(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));
        $service = app(KastaDictionaryImportServiceInterface::class);

        $service->import(new DictionaryImportOptions(
            type: DictionaryImport::TYPE_KASTA_CATEGORIES,
            filePath: base_path('database/samples/kasta-dictionaries/kasta_categories.json'),
        ));

        $import = $service->import(new DictionaryImportOptions(
            type: DictionaryImport::TYPE_KASTA_ATTRIBUTES,
            filePath: base_path('database/samples/kasta-dictionaries/kasta_attributes.csv'),
            format: 'csv',
        ));

        $this->assertSame('csv', $import->source_format);
        $this->assertDatabaseCount('kasta_attributes', 5);
        $this->assertDatabaseHas('kasta_attributes', [
            'code' => 'material',
            'is_active' => true,
        ]);
    }

    public function test_dry_run_import_does_not_persist_dictionary_rows(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));

        $import = app(KastaDictionaryImportServiceInterface::class)->import(new DictionaryImportOptions(
            type: DictionaryImport::TYPE_KASTA_CATEGORIES,
            filePath: base_path('database/samples/kasta-dictionaries/kasta_categories.json'),
            dryRun: true,
        ));

        $this->assertTrue($import->dry_run);
        $this->assertSame(DictionaryImport::STATUS_COMPLETED, $import->status);
        $this->assertDatabaseCount('kasta_categories', 0);
        $this->assertSame(3, $import->created_count);
    }

    public function test_duplicate_checksum_import_is_skipped(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));
        $service = app(KastaDictionaryImportServiceInterface::class);

        $service->import(new DictionaryImportOptions(
            type: DictionaryImport::TYPE_KASTA_CATEGORIES,
            filePath: base_path('database/samples/kasta-dictionaries/kasta_categories.json'),
        ));

        $duplicate = $service->import(new DictionaryImportOptions(
            type: DictionaryImport::TYPE_KASTA_CATEGORIES,
            filePath: base_path('database/samples/kasta-dictionaries/kasta_categories.json'),
        ));

        $this->assertSame(DictionaryImport::STATUS_SKIPPED, $duplicate->status);
        $this->assertDatabaseCount('dictionary_imports', 2);
        $this->assertDatabaseCount('kasta_categories', 3);
    }

    public function test_reimport_latest_uses_stored_copy(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));
        $service = app(KastaDictionaryImportServiceInterface::class);

        $service->import(new DictionaryImportOptions(
            type: DictionaryImport::TYPE_KASTA_CATEGORIES,
            filePath: base_path('database/samples/kasta-dictionaries/kasta_categories.json'),
        ));

        KastaCategory::query()->delete();

        $reimport = $service->reimportLatest(DictionaryImport::TYPE_KASTA_CATEGORIES);

        $this->assertSame(DictionaryImport::STATUS_COMPLETED, $reimport->status);
        $this->assertDatabaseCount('kasta_categories', 3);
    }

    public function test_deactivate_missing_flag_marks_absent_rows_inactive(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));
        $service = app(KastaDictionaryImportServiceInterface::class);

        $service->import(new DictionaryImportOptions(
            type: DictionaryImport::TYPE_KASTA_CATEGORIES,
            filePath: base_path('database/samples/kasta-dictionaries/kasta_categories.json'),
        ));

        $reducedFile = tempnam(sys_get_temp_dir(), 'kasta-categories');
        file_put_contents($reducedFile, json_encode([
            [
                'external_id' => 'KASTA-TSHIRTS',
                'parent_external_id' => null,
                'name' => 'T-Shirts',
                'full_path' => 'Apparel > T-Shirts',
                'rz_id' => '2001',
                'is_active' => true,
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $service->import(new DictionaryImportOptions(
            type: DictionaryImport::TYPE_KASTA_CATEGORIES,
            filePath: $reducedFile,
            format: 'json',
            deactivateMissing: true,
            allowDuplicateChecksum: true,
        ));

        $this->assertSame(1, KastaCategory::query()->where('is_active', true)->count());
        $this->assertSame(2, KastaCategory::query()->where('is_active', false)->count());
    }
}
