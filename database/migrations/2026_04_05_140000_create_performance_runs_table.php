<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('performance_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id')->nullable();
            $table->unsignedBigInteger('feed_profile_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('run_type', 32);
            $table->string('status', 24)->default('running');
            $table->string('budget_status', 16)->default('within_budget');
            $table->string('environment_label', 64)->nullable();
            $table->string('label', 120)->nullable();
            $table->unsignedInteger('dataset_products')->default(0);
            $table->unsignedInteger('dataset_variants')->default(0);
            $table->unsignedInteger('dataset_images')->default(0);
            $table->unsignedInteger('processed_products')->default(0);
            $table->unsignedInteger('processed_variants')->default(0);
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->decimal('peak_memory_mb', 8, 2)->nullable();
            $table->json('stages')->nullable();
            $table->json('report_counts')->nullable();
            $table->json('summary')->nullable();
            $table->json('warnings')->nullable();
            $table->json('errors')->nullable();
            $table->text('note')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->foreign('shop_id', 'pfr_shop_fk')->references('id')->on('shops')->nullOnDelete();
            $table->foreign('feed_profile_id', 'pfr_prof_fk')->references('id')->on('feed_profiles')->nullOnDelete();
            $table->foreign('user_id', 'pfr_user_fk')->references('id')->on('users')->nullOnDelete();

            $table->index(['run_type', 'status', 'started_at'], 'pfr_typ_sta_idx');
            $table->index(['shop_id', 'started_at'], 'pfr_shop_dt_idx');
            $table->index(['feed_profile_id', 'started_at'], 'pfr_prof_dt_idx');
            $table->index(['budget_status', 'started_at'], 'pfr_bgt_dt_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performance_runs');
    }
};
