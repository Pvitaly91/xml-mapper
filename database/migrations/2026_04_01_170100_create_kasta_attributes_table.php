<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kasta_attributes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kasta_category_id')->constrained()->cascadeOnDelete();
            $table->string('external_id')->nullable();
            $table->string('name');
            $table->string('code');
            $table->string('data_type')->default('string');
            $table->boolean('is_required')->default(false);
            $table->boolean('allows_custom_value')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['kasta_category_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kasta_attributes');
    }
};
