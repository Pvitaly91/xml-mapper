<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feed_generation_preview_links', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->unsignedBigInteger('feed_profile_id');
            $table->unsignedBigInteger('feed_generation_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->text('token');
            $table->string('last_smoke_check_status', 32)->nullable();
            $table->timestamp('last_smoke_check_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_accessed_at')->nullable();
            $table->text('note')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->foreign('shop_id', 'fgpl_shop_fk')->references('id')->on('shops')->cascadeOnDelete();
            $table->foreign('feed_profile_id', 'fgpl_prof_fk')->references('id')->on('feed_profiles')->cascadeOnDelete();
            $table->foreign('feed_generation_id', 'fgpl_gen_fk')->references('id')->on('feed_generations')->cascadeOnDelete();
            $table->foreign('user_id', 'fgpl_user_fk')->references('id')->on('users')->nullOnDelete();

            $table->index(['feed_generation_id', 'revoked_at'], 'fgpl_gen_rev_idx');
            $table->index(['feed_profile_id', 'expires_at'], 'fgpl_prof_exp_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feed_generation_preview_links');
    }
};
