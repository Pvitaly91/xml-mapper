<?php

namespace Tests\Feature\Health;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class HealthSchemaReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_endpoint_reports_setup_required_when_schema_is_incomplete(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::drop('validation_errors');
        Schema::drop('feed_items');
        Schema::drop('source_variants');
        Schema::drop('source_products');
        Schema::enableForeignKeyConstraints();

        $response = $this->get('/health');

        $response->assertStatus(503);
        $response->assertJsonPath('status', 'setup_required');
        $response->assertJsonPath('schema_ready', false);
        $response->assertJsonPath('setup_required', true);
        $response->assertJsonPath('checks.schema', 'setup_required');
        $response->assertJsonFragment(['source_products']);
    }
}
