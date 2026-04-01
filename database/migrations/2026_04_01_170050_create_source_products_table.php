<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_import_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('source_category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('group_key');
            $table->string('external_group_id')->nullable();
            $table->string('name');
            $table->string('vendor')->nullable();
            $table->string('article')->nullable();
            $table->string('brand')->nullable();
            $table->longText('description')->nullable();
            $table->text('primary_image_url')->nullable();
            $table->json('images_json')->nullable();
            $table->json('attributes_snapshot')->nullable();
            $table->json('raw_payload')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['shop_id', 'source_connection_id', 'group_key']);
            $table->index(['shop_id', 'vendor', 'article']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_products');
    }
};
