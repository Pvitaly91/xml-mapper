<?php

namespace Tests\Feature\Admin;

use Database\Seeders\KastaDictionarySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAdminContext;
use Tests\TestCase;

class DictionaryImportWorkflowTest extends TestCase
{
    use CreatesAdminContext;
    use RefreshDatabase;

    public function test_admin_can_import_dictionaries_from_stub_path(): void
    {
        $admin = $this->createAdminUser();

        $this->actingAs($admin)
            ->post(route('admin.dictionaries.import'))
            ->assertRedirect();

        $this->assertDatabaseCount('kasta_categories', 3);
        $this->assertDatabaseCount('kasta_attributes', 5);
        $this->assertDatabaseCount('kasta_attribute_values', 6);
        $this->assertDatabaseCount('size_grids', 2);
    }

    public function test_dictionary_commands_and_seeder_are_idempotent(): void
    {
        $this->artisan('kasta:import-dictionaries')->assertSuccessful();
        $this->artisan('kasta:reimport-dictionaries')->assertSuccessful();
        $this->artisan('db:seed', ['--class' => KastaDictionarySeeder::class])->assertSuccessful();

        $this->assertDatabaseCount('kasta_categories', 3);
        $this->assertDatabaseCount('kasta_attributes', 5);
        $this->assertDatabaseCount('kasta_attribute_values', 6);
        $this->assertDatabaseCount('size_grids', 2);
    }
}
