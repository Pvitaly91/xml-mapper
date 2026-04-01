<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_attribute_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_attribute_id')->constrained()->cascadeOnDelete();
            $table->string('raw_value');
            $table->string('normalized_value')->nullable();
            $table->string('value_hash', 64);
            $table->timestamps();

            $table->unique(['source_attribute_id', 'value_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_attribute_values');
    }
};
