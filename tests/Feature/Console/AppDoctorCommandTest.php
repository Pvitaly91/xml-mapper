<?php

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AppDoctorCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_app_doctor_reports_setup_required_when_schema_is_incomplete(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::drop('validation_errors');
        Schema::drop('feed_items');
        Schema::drop('source_variants');
        Schema::drop('source_products');
        Schema::enableForeignKeyConstraints();

        $this->artisan('app:doctor')
            ->expectsOutput('Database connection: OK')
            ->expectsOutput('Schema readiness: setup_required')
            ->assertExitCode(1);
    }
}
