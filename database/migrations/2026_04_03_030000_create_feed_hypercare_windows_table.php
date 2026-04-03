<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feed_hypercare_windows', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->unsignedBigInteger('feed_profile_id');
            $table->unsignedBigInteger('feed_generation_id')->nullable();
            $table->unsignedBigInteger('initiated_by_user_id')->nullable();
            $table->unsignedBigInteger('owner_user_id')->nullable();
            $table->string('status', 24)->default('planned');
            $table->unsignedTinyInteger('escalation_level')->default(0);
            $table->unsignedInteger('target_sla_minutes')->default(240);
            $table->unsignedInteger('monitoring_cadence_minutes')->default(60);
            $table->text('note')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('planned_end_at')->nullable();
            $table->timestamp('actual_end_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->foreign('shop_id', 'fhw_shop_fk')->references('id')->on('shops')->cascadeOnDelete();
            $table->foreign('feed_profile_id', 'fhw_prof_fk')->references('id')->on('feed_profiles')->cascadeOnDelete();
            $table->foreign('feed_generation_id', 'fhw_gen_fk')->references('id')->on('feed_generations')->nullOnDelete();
            $table->foreign('initiated_by_user_id', 'fhw_iusr_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('owner_user_id', 'fhw_ousr_fk')->references('id')->on('users')->nullOnDelete();

            $table->index(['feed_profile_id', 'status', 'planned_end_at'], 'fhw_prof_sta_idx');
            $table->index(['shop_id', 'status', 'planned_end_at'], 'fhw_shop_sta_idx');
            $table->index(['feed_generation_id', 'status'], 'fhw_gen_sta_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feed_hypercare_windows');
    }
};
