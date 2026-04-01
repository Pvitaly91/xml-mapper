<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feed_generations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('feed_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_import_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('pending');
            $table->unsignedInteger('items_total')->default(0);
            $table->unsignedInteger('valid_items_total')->default(0);
            $table->unsignedInteger('invalid_items_total')->default(0);
            $table->string('file_path')->nullable();
            $table->string('published_path')->nullable();
            $table->string('checksum', 64)->nullable();
            $table->timestamp('built_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->text('error_message')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['feed_profile_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feed_generations');
    }
};
