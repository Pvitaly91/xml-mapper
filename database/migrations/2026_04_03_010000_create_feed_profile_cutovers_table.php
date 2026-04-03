<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feed_profile_cutovers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->unsignedBigInteger('feed_profile_id');
            $table->unsignedBigInteger('target_generation_id')->nullable();
            $table->unsignedBigInteger('published_generation_id')->nullable();
            $table->unsignedBigInteger('initiated_by_user_id')->nullable();
            $table->string('status', 32)->default('cutover_blocked');
            $table->boolean('is_current')->default(true);
            $table->text('note')->nullable();
            $table->timestamp('planned_window_starts_at')->nullable();
            $table->timestamp('planned_window_ends_at')->nullable();
            $table->timestamp('actual_published_at')->nullable();
            $table->timestamp('first_verified_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->foreign('shop_id', 'fpc_shop_fk')->references('id')->on('shops')->cascadeOnDelete();
            $table->foreign('feed_profile_id', 'fpc_prof_fk')->references('id')->on('feed_profiles')->cascadeOnDelete();
            $table->foreign('target_generation_id', 'fpc_tgen_fk')->references('id')->on('feed_generations')->nullOnDelete();
            $table->foreign('published_generation_id', 'fpc_pgen_fk')->references('id')->on('feed_generations')->nullOnDelete();
            $table->foreign('initiated_by_user_id', 'fpc_user_fk')->references('id')->on('users')->nullOnDelete();

            $table->index(['feed_profile_id', 'is_current'], 'fpc_prof_cur_idx');
            $table->index(['shop_id', 'status'], 'fpc_shop_sta_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feed_profile_cutovers');
    }
};
