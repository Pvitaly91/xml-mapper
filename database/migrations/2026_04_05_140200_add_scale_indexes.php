<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feed_items', function (Blueprint $table) {
            $table->index(['feed_profile_id', 'status', 'last_built_generation_id'], 'fitem_prof_sta_gen_idx');
        });

        Schema::table('feedback_records', function (Blueprint $table) {
            $table->index(['feed_profile_id', 'resolution_status', 'status'], 'fbr_prof_res_sta_idx');
        });

        Schema::table('ops_notification_deliveries', function (Blueprint $table) {
            $table->index(['shop_id', 'status', 'created_at'], 'ond_shop_sta_dt_idx');
        });
    }

    public function down(): void
    {
        Schema::table('ops_notification_deliveries', function (Blueprint $table) {
            $table->dropIndex('ond_shop_sta_dt_idx');
        });

        Schema::table('feedback_records', function (Blueprint $table) {
            $table->dropIndex('fbr_prof_res_sta_idx');
        });

        Schema::table('feed_items', function (Blueprint $table) {
            $table->dropIndex('fitem_prof_sta_gen_idx');
        });
    }
};
