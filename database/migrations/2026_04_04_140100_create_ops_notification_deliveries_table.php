<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ops_notification_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('ops_notification_route_id')->nullable();
            $table->unsignedBigInteger('shop_id')->nullable();
            $table->unsignedBigInteger('feed_profile_id')->nullable();
            $table->unsignedBigInteger('ops_alert_id')->nullable();
            $table->unsignedBigInteger('merchant_launch_id')->nullable();
            $table->unsignedBigInteger('feed_hypercare_window_id')->nullable();
            $table->unsignedBigInteger('pilot_run_id')->nullable();
            $table->string('event_family', 64)->nullable();
            $table->string('event_type', 120);
            $table->string('severity', 16)->default('info');
            $table->string('channel', 24);
            $table->string('target_label', 180)->nullable();
            $table->string('status', 32)->default('pending_delivery');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->boolean('is_test')->default(false);
            $table->string('correlation_id', 64)->nullable();
            $table->string('dedup_key', 180)->nullable();
            $table->string('summary', 180)->nullable();
            $table->text('rendered_payload')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->json('response_meta')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->foreign('ops_notification_route_id', 'ond_route_fk')->references('id')->on('ops_notification_routes')->nullOnDelete();
            $table->foreign('shop_id', 'ond_shop_fk')->references('id')->on('shops')->nullOnDelete();
            $table->foreign('feed_profile_id', 'ond_prof_fk')->references('id')->on('feed_profiles')->nullOnDelete();
            $table->foreign('ops_alert_id', 'ond_alert_fk')->references('id')->on('ops_alerts')->nullOnDelete();
            $table->foreign('merchant_launch_id', 'ond_lch_fk')->references('id')->on('merchant_launches')->nullOnDelete();
            $table->foreign('feed_hypercare_window_id', 'ond_hyp_fk')->references('id')->on('feed_hypercare_windows')->nullOnDelete();
            $table->foreign('pilot_run_id', 'ond_pilot_fk')->references('id')->on('pilot_runs')->nullOnDelete();

            $table->index(['status', 'next_retry_at'], 'ond_sta_rty_idx');
            $table->index(['channel', 'status', 'created_at'], 'ond_chn_sta_idx');
            $table->index(['feed_profile_id', 'created_at'], 'ond_prof_dt_idx');
            $table->index(['correlation_id'], 'ond_corr_idx');
            $table->index(['dedup_key', 'channel', 'created_at'], 'ond_dedup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ops_notification_deliveries');
    }
};
