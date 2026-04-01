<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kasta_attribute_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kasta_attribute_id')->constrained()->cascadeOnDelete();
            $table->string('external_id')->nullable();
            $table->string('value');
            $table->string('normalized_value')->nullable();
            $table->string('value_hash', 64);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['kasta_attribute_id', 'value_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kasta_attribute_values');
    }
};
