<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attribute_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('feed_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('source_attribute_id')->constrained()->cascadeOnDelete();
            $table->foreignId('kasta_category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('kasta_attribute_id')->constrained()->cascadeOnDelete();
            $table->string('mapping_strategy')->default('manual');
            $table->boolean('is_required')->default(false);
            $table->string('default_value')->nullable();
            $table->boolean('use_variant_value')->default(true);
            $table->timestamps();

            $table->unique(['feed_profile_id', 'source_category_id', 'source_attribute_id', 'kasta_attribute_id'], 'attribute_mappings_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attribute_mappings');
    }
};
