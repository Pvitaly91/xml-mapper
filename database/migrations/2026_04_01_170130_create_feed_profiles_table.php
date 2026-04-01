<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feed_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('published_generation_id')->nullable();
            $table->string('name');
            $table->string('code');
            $table->string('public_token', 64)->unique();
            $table->string('status')->default('draft');
            $table->string('currency', 3)->default('UAH');
            $table->string('language', 10)->default('uk');
            $table->boolean('include_unavailable')->default(false);
            $table->boolean('auto_sync')->default(true);
            $table->boolean('auto_build')->default(true);
            $table->unsignedInteger('build_interval_minutes')->default(60);
            $table->timestamp('last_built_at')->nullable();
            $table->timestamp('next_build_at')->nullable();
            $table->string('published_path')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->unique(['shop_id', 'code']);
            $table->index(['shop_id', 'status', 'next_build_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feed_profiles');
    }
};
