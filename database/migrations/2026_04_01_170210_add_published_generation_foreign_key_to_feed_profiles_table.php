<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feed_profiles', function (Blueprint $table) {
            $table->foreign('published_generation_id')
                ->references('id')
                ->on('feed_generations')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('feed_profiles', function (Blueprint $table) {
            $table->dropForeign(['published_generation_id']);
        });
    }
};
