<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code');
            $table->string('driver')->default('prom_yml');
            $table->string('status')->default('active');
            $table->text('source_url')->nullable();
            $table->text('credentials')->nullable();
            $table->json('options')->nullable();
            $table->unsignedInteger('sync_interval_minutes')->default(60);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('next_sync_at')->nullable();
            $table->timestamps();

            $table->unique(['shop_id', 'code']);
            $table->index(['shop_id', 'status', 'next_sync_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_connections');
    }
};
