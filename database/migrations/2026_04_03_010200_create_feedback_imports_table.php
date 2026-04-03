<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feedback_imports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->unsignedBigInteger('feed_profile_id');
            $table->unsignedBigInteger('feed_generation_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('format', 16);
            $table->string('status', 32)->default('imported');
            $table->string('original_filename')->nullable();
            $table->string('source_path')->nullable();
            $table->string('checksum', 64)->nullable();
            $table->unsignedInteger('matched_total')->default(0);
            $table->unsignedInteger('unmatched_total')->default(0);
            $table->unsignedInteger('accepted_total')->default(0);
            $table->unsignedInteger('rejected_total')->default(0);
            $table->unsignedInteger('warnings_total')->default(0);
            $table->json('meta')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();

            $table->foreign('shop_id', 'fbi_shop_fk')->references('id')->on('shops')->cascadeOnDelete();
            $table->foreign('feed_profile_id', 'fbi_prof_fk')->references('id')->on('feed_profiles')->cascadeOnDelete();
            $table->foreign('feed_generation_id', 'fbi_gen_fk')->references('id')->on('feed_generations')->nullOnDelete();
            $table->foreign('user_id', 'fbi_user_fk')->references('id')->on('users')->nullOnDelete();

            $table->index(['feed_profile_id', 'imported_at'], 'fbi_prof_imp_idx');
            $table->index(['shop_id', 'status'], 'fbi_shop_sta_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback_imports');
    }
};
