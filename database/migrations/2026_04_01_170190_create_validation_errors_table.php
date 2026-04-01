<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('validation_errors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('feed_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('source_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('feed_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code');
            $table->string('severity')->default('error');
            $table->text('message');
            $table->json('payload')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('detected_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['feed_profile_id', 'is_active']);
            $table->index(['source_variant_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('validation_errors');
    }
};
