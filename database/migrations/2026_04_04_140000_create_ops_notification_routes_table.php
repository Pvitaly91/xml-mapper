<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ops_notification_routes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('shop_id')->nullable();
            $table->unsignedBigInteger('feed_profile_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('name', 120);
            $table->string('scope', 16)->default('global');
            $table->string('channel', 24);
            $table->string('event_family', 64)->nullable();
            $table->string('event_type', 120)->nullable();
            $table->string('minimum_severity', 16)->default('info');
            $table->boolean('enabled')->default(true);
            $table->timestamp('muted_until')->nullable();
            $table->string('quiet_hours_start', 5)->nullable();
            $table->string('quiet_hours_end', 5)->nullable();
            $table->string('quiet_hours_timezone', 64)->nullable();
            $table->string('target_label', 180)->nullable();
            $table->json('target')->nullable();
            $table->json('policy')->nullable();
            $table->timestamp('last_delivery_at')->nullable();
            $table->string('last_delivery_status', 32)->nullable();
            $table->timestamp('last_test_succeeded_at')->nullable();
            $table->timestamp('last_test_failed_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->foreign('shop_id', 'onr_shop_fk')->references('id')->on('shops')->nullOnDelete();
            $table->foreign('feed_profile_id', 'onr_prof_fk')->references('id')->on('feed_profiles')->nullOnDelete();
            $table->foreign('user_id', 'onr_user_fk')->references('id')->on('users')->nullOnDelete();

            $table->index(['scope', 'channel', 'enabled'], 'onr_scp_chn_idx');
            $table->index(['shop_id', 'channel', 'enabled'], 'onr_shop_chn_idx');
            $table->index(['feed_profile_id', 'channel', 'enabled'], 'onr_prof_chn_idx');
            $table->index(['event_family', 'event_type'], 'onr_evt_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ops_notification_routes');
    }
};
