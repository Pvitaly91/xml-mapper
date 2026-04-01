<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feed_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('feed_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_variant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('last_built_generation_id')->nullable()->constrained('feed_generations')->nullOnDelete();
            $table->string('status')->default('pending');
            $table->boolean('is_enabled')->default(true);
            $table->boolean('is_manual_override')->default(false);
            $table->string('excluded_reason')->nullable();
            $table->string('last_validation_hash', 64)->nullable();
            $table->timestamp('last_exported_at')->nullable();
            $table->timestamps();

            $table->unique(['feed_profile_id', 'source_variant_id']);
            $table->index(['feed_profile_id', 'status', 'is_enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feed_items');
    }
};
