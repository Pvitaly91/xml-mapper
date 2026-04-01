<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_connection_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('pending');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->string('source_checksum', 64)->nullable();
            $table->text('source_url_snapshot')->nullable();
            $table->string('temp_path')->nullable();
            $table->unsignedInteger('categories_total')->default(0);
            $table->unsignedInteger('offers_total')->default(0);
            $table->unsignedBigInteger('source_size_bytes')->default(0);
            $table->text('error_message')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['source_connection_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_imports');
    }
};
