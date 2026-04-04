<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ops_alerts', function (Blueprint $table): void {
            $table->string('notification_state', 32)->nullable()->after('state');
            $table->string('correlation_id', 64)->nullable()->after('fingerprint');
            $table->timestamp('notification_last_delivery_at')->nullable()->after('escalated_at');
            $table->timestamp('notification_suppressed_at')->nullable()->after('notification_last_delivery_at');
            $table->timestamp('notification_escalated_at')->nullable()->after('notification_suppressed_at');
            $table->timestamp('notification_acknowledged_at')->nullable()->after('notification_escalated_at');
            $table->timestamp('notification_resolved_at')->nullable()->after('notification_acknowledged_at');
            $table->json('notification_meta')->nullable()->after('context');

            $table->index(['notification_state', 'last_raised_at'], 'oal_ntf_sta_idx');
            $table->index(['correlation_id'], 'oal_corr_idx');
        });
    }

    public function down(): void
    {
        Schema::table('ops_alerts', function (Blueprint $table): void {
            $table->dropIndex('oal_ntf_sta_idx');
            $table->dropIndex('oal_corr_idx');
            $table->dropColumn([
                'notification_state',
                'correlation_id',
                'notification_last_delivery_at',
                'notification_suppressed_at',
                'notification_escalated_at',
                'notification_acknowledged_at',
                'notification_resolved_at',
                'notification_meta',
            ]);
        });
    }
};
