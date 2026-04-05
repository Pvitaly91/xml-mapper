<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feed_item_mapping_exceptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('feed_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('feed_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('source_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('exception_type', 32);
            $table->string('target_key')->nullable();
            $table->string('target_value')->nullable();
            $table->string('target_label')->nullable();
            $table->string('reason');
            $table->boolean('is_active')->default(true);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['feed_item_id', 'exception_type', 'is_active'], 'fime_item_idx');
            $table->index(['feed_profile_id', 'exception_type'], 'fime_prof_idx');
            $table->index(['source_variant_id', 'is_active'], 'fime_var_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feed_item_mapping_exceptions');
    }
};
