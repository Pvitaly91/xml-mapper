<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feed_first_pull_verifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->unsignedBigInteger('feed_profile_id');
            $table->unsignedBigInteger('feed_generation_id');
            $table->unsignedBigInteger('feed_profile_cutover_id')->nullable();
            $table->unsignedBigInteger('feed_generation_smoke_check_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('status', 32)->default('failed');
            $table->unsignedInteger('latency_ms')->nullable();
            $table->unsignedBigInteger('response_size_bytes')->nullable();
            $table->unsignedInteger('offers_total')->nullable();
            $table->unsignedInteger('categories_total')->nullable();
            $table->string('response_checksum', 64)->nullable();
            $table->string('expected_checksum', 64)->nullable();
            $table->json('warnings')->nullable();
            $table->json('errors')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->foreign('shop_id', 'ffpv_shop_fk')->references('id')->on('shops')->cascadeOnDelete();
            $table->foreign('feed_profile_id', 'ffpv_prof_fk')->references('id')->on('feed_profiles')->cascadeOnDelete();
            $table->foreign('feed_generation_id', 'ffpv_gen_fk')->references('id')->on('feed_generations')->cascadeOnDelete();
            $table->foreign('feed_profile_cutover_id', 'ffpv_cut_fk')->references('id')->on('feed_profile_cutovers')->nullOnDelete();
            $table->foreign('feed_generation_smoke_check_id', 'ffpv_smk_fk')->references('id')->on('feed_generation_smoke_checks')->nullOnDelete();
            $table->foreign('user_id', 'ffpv_user_fk')->references('id')->on('users')->nullOnDelete();

            $table->index(['feed_profile_id', 'verified_at'], 'ffpv_prof_ver_idx');
            $table->index(['feed_generation_id', 'status'], 'ffpv_gen_sta_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feed_first_pull_verifications');
    }
};
