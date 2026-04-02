<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kasta_attributes', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('allows_custom_value');
            $table->index(['kasta_category_id', 'is_active']);
        });

        Schema::table('kasta_attribute_values', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('normalized_value');
            $table->index(['kasta_attribute_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::table('kasta_attribute_values', function (Blueprint $table) {
            $table->dropIndex(['kasta_attribute_id', 'is_active']);
            $table->dropColumn('is_active');
        });

        Schema::table('kasta_attributes', function (Blueprint $table) {
            $table->dropIndex(['kasta_category_id', 'is_active']);
            $table->dropColumn('is_active');
        });
    }
};
