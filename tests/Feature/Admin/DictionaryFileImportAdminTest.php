<?php

namespace Tests\Feature\Admin;

use App\Models\DictionaryImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAdminContext;
use Tests\TestCase;

class DictionaryFileImportAdminTest extends TestCase
{
    use CreatesAdminContext;
    use RefreshDatabase;

    public function test_admin_can_open_dictionary_import_history_and_run_dry_run_import(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));

        $admin = $this->createAdminUser();

        $this->actingAs($admin)
            ->get(route('admin.dictionary-imports.index'))
            ->assertOk();

        $response = $this->actingAs($admin)->post(route('admin.dictionary-imports.store'), [
            'type' => DictionaryImport::TYPE_KASTA_CATEGORIES,
            'path' => base_path('database/samples/kasta-dictionaries/kasta_categories.json'),
            'dry_run' => 1,
        ]);

        $import = DictionaryImport::query()->latest('id')->firstOrFail();

        $response->assertRedirect(route('admin.dictionary-imports.show', $import));
        $this->assertTrue($import->dry_run);
        $this->assertDatabaseCount('kasta_categories', 0);

        $this->actingAs($admin)
            ->get(route('admin.dictionary-imports.show', $import))
            ->assertOk()
            ->assertSee('dry-run', false);
    }

    public function test_admin_can_reimport_latest_dictionary_file(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));

        $admin = $this->createAdminUser();

        $this->actingAs($admin)->post(route('admin.dictionary-imports.store'), [
            'type' => DictionaryImport::TYPE_KASTA_CATEGORIES,
            'path' => base_path('database/samples/kasta-dictionaries/kasta_categories.json'),
        ])->assertRedirect();

        $this->assertDatabaseCount('kasta_categories', 3);
        \App\Models\KastaCategory::query()->delete();

        $response = $this->actingAs($admin)->post(route('admin.dictionary-imports.reimport'), [
            'type' => DictionaryImport::TYPE_KASTA_CATEGORIES,
        ]);

        $latestImport = DictionaryImport::query()->latest('id')->firstOrFail();

        $response->assertRedirect(route('admin.dictionary-imports.show', $latestImport));
        $this->assertDatabaseCount('kasta_categories', 3);
    }
}
