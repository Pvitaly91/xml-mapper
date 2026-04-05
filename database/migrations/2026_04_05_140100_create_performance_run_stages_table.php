<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('performance_run_stages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('performance_run_id');
            $table->string('stage', 32);
            $table->string('status', 24)->default('running');
            $table->string('budget_status', 16)->default('within_budget');
            $table->unsignedInteger('processed_products')->default(0);
            $table->unsignedInteger('processed_variants')->default(0);
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('report_count')->default(0);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->decimal('peak_memory_mb', 8, 2)->nullable();
            $table->json('warnings')->nullable();
            $table->json('errors')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->foreign('performance_run_id', 'pfs_run_fk')->references('id')->on('performance_runs')->cascadeOnDelete();

            $table->unique(['performance_run_id', 'stage'], 'pfs_run_stg_uq');
            $table->index(['status', 'started_at'], 'pfs_sta_dt_idx');
            $table->index(['budget_status', 'started_at'], 'pfs_bgt_dt_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performance_run_stages');
    }
};
