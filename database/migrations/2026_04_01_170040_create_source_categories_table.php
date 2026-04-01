<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('source_categories')->nullOnDelete();
            $table->string('external_id');
            $table->string('parent_external_id')->nullable();
            $table->string('name');
            $table->string('full_path')->nullable();
            $table->string('rz_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->unique(['shop_id', 'source_connection_id', 'external_id']);
            $table->index(['shop_id', 'rz_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_categories');
    }
};
