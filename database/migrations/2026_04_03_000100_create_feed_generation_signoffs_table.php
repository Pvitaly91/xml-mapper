<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feed_generation_signoffs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->unsignedBigInteger('feed_profile_id');
            $table->unsignedBigInteger('feed_generation_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('reviewer_name')->nullable();
            $table->string('status', 32);
            $table->boolean('is_current')->default(true);
            $table->text('note')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->foreign('shop_id', 'fgs_shop_fk')->references('id')->on('shops')->cascadeOnDelete();
            $table->foreign('feed_profile_id', 'fgs_prof_fk')->references('id')->on('feed_profiles')->cascadeOnDelete();
            $table->foreign('feed_generation_id', 'fgs_gen_fk')->references('id')->on('feed_generations')->cascadeOnDelete();
            $table->foreign('user_id', 'fgs_user_fk')->references('id')->on('users')->nullOnDelete();

            $table->index(['feed_generation_id', 'is_current'], 'fgs_gen_cur_idx');
            $table->index(['feed_profile_id', 'status'], 'fgs_prof_sta_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feed_generation_signoffs');
    }
};
