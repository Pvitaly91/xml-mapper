<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('value_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('feed_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attribute_mapping_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_attribute_value_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('kasta_attribute_value_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_raw_value');
            $table->string('normalized_source_value')->nullable();
            $table->string('target_value')->nullable();
            $table->string('mapping_strategy')->default('manual');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['attribute_mapping_id', 'source_raw_value'], 'value_mappings_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('value_mappings');
    }
};
