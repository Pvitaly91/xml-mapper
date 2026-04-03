<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('source_variants', function (Blueprint $table) {
            $table->index(['shop_id', 'source_connection_id', 'is_enabled', 'id'], 'srcv_conn_en_id_idx');
        });

        Schema::table('source_products', function (Blueprint $table) {
            $table->index(['source_connection_id', 'is_active', 'id'], 'srcp_conn_act_idx');
        });

        Schema::table('feed_items', function (Blueprint $table) {
            $table->index(['feed_profile_id', 'last_built_generation_id', 'status'], 'fitem_prof_gen_sta_idx');
        });

        Schema::table('validation_errors', function (Blueprint $table) {
            $table->index(['feed_profile_id', 'is_active', 'code', 'feed_item_id'], 'verr_prof_act_code_idx');
        });

        Schema::table('feedback_records', function (Blueprint $table) {
            $table->index(['feed_profile_id', 'status', 'resolution_status', 'imported_at'], 'fbr_prof_sta_res_idx');
        });
    }

    public function down(): void
    {
        Schema::table('feedback_records', function (Blueprint $table) {
            $table->dropIndex('fbr_prof_sta_res_idx');
        });

        Schema::table('validation_errors', function (Blueprint $table) {
            $table->dropIndex('verr_prof_act_code_idx');
        });

        Schema::table('feed_items', function (Blueprint $table) {
            $table->dropIndex('fitem_prof_gen_sta_idx');
        });

        Schema::table('source_products', function (Blueprint $table) {
            $table->dropIndex('srcp_conn_act_idx');
        });

        Schema::table('source_variants', function (Blueprint $table) {
            $table->dropIndex('srcv_conn_en_id_idx');
        });
    }
};
