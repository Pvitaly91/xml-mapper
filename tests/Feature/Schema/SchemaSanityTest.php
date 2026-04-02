<?php

namespace Tests\Feature\Schema;

use App\Services\Setup\DatabaseSetupInspector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SchemaSanityTest extends TestCase
{
    use RefreshDatabase;

    public function test_clean_database_contains_required_admin_tables(): void
    {
        $inspector = app(DatabaseSetupInspector::class);

        foreach ($inspector->adminRequiredTables() as $table) {
            $this->assertTrue(Schema::hasTable($table), sprintf('Missing expected table [%s].', $table));
        }

        $this->assertTrue($inspector->adminReport()['schema_ready']);
        $this->assertSame([], $inspector->adminReport()['missing_tables']);
    }
}
