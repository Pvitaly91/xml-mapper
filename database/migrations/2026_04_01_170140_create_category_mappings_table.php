<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('category_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('feed_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('kasta_category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('rz_id')->nullable();
            $table->string('mapping_strategy')->default('manual');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['feed_profile_id', 'source_category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_mappings');
    }
};
