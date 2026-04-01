<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_attributes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_connection_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code');
            $table->string('data_type')->default('string');
            $table->string('usage_scope')->default('variant');
            $table->boolean('is_variant_axis')->default(false);
            $table->timestamps();

            $table->unique(['shop_id', 'source_connection_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_attributes');
    }
};
