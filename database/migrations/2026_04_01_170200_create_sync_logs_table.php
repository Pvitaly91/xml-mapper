<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_connection_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('source_import_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('feed_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('feed_generation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('level')->default('info');
            $table->string('event');
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'level', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_logs');
    }
};
