<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feed_generation_smoke_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('feed_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('feed_generation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('trigger_source', 32)->default('automatic');
            $table->string('status', 32)->default('failed');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('content_type')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->unsignedInteger('offers_total')->nullable();
            $table->unsignedInteger('categories_total')->nullable();
            $table->unsignedBigInteger('response_size_bytes')->nullable();
            $table->string('response_checksum', 64)->nullable();
            $table->string('expected_checksum', 64)->nullable();
            $table->json('warnings')->nullable();
            $table->json('errors')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['feed_generation_id', 'checked_at'], 'fgsc_gen_chk_idx');
            $table->index(['feed_profile_id', 'status'], 'fgsc_prof_sta_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feed_generation_smoke_checks');
    }
};
