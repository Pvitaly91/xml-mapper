<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kasta_categories', function (Blueprint $table) {
            $table->id();
            $table->string('external_id')->unique();
            $table->foreignId('parent_id')->nullable()->constrained('kasta_categories')->nullOnDelete();
            $table->string('name');
            $table->string('full_path')->nullable();
            $table->string('rz_id')->nullable()->unique();
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kasta_categories');
    }
};
