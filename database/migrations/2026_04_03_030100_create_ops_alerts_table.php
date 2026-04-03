<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ops_alerts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->unsignedBigInteger('feed_profile_id')->nullable();
            $table->unsignedBigInteger('feed_generation_id')->nullable();
            $table->unsignedBigInteger('source_connection_id')->nullable();
            $table->unsignedBigInteger('feed_hypercare_window_id')->nullable();
            $table->unsignedBigInteger('acknowledged_by_user_id')->nullable();
            $table->unsignedBigInteger('resolved_by_user_id')->nullable();
            $table->unsignedBigInteger('silenced_by_user_id')->nullable();
            $table->string('source', 64);
            $table->string('state', 24)->default('raised');
            $table->string('severity', 16)->default('warning');
            $table->string('fingerprint', 120);
            $table->string('title', 180);
            $table->text('message');
            $table->string('reason', 255)->nullable();
            $table->text('note')->nullable();
            $table->unsignedTinyInteger('escalation_level')->default(0);
            $table->timestamp('first_raised_at')->nullable();
            $table->timestamp('last_raised_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('silenced_at')->nullable();
            $table->timestamp('escalated_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('false_positive_at')->nullable();
            $table->timestamp('last_reviewed_at')->nullable();
            $table->json('context')->nullable();
            $table->timestamps();

            $table->foreign('shop_id', 'oal_shop_fk')->references('id')->on('shops')->cascadeOnDelete();
            $table->foreign('feed_profile_id', 'oal_prof_fk')->references('id')->on('feed_profiles')->nullOnDelete();
            $table->foreign('feed_generation_id', 'oal_gen_fk')->references('id')->on('feed_generations')->nullOnDelete();
            $table->foreign('source_connection_id', 'oal_conn_fk')->references('id')->on('source_connections')->nullOnDelete();
            $table->foreign('feed_hypercare_window_id', 'oal_hyp_fk')->references('id')->on('feed_hypercare_windows')->nullOnDelete();
            $table->foreign('acknowledged_by_user_id', 'oal_ausr_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('resolved_by_user_id', 'oal_rusr_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('silenced_by_user_id', 'oal_susr_fk')->references('id')->on('users')->nullOnDelete();

            $table->index(['feed_profile_id', 'state', 'severity'], 'oal_prof_sta_idx');
            $table->index(['feed_hypercare_window_id', 'state', 'severity'], 'oal_hyp_sta_idx');
            $table->index(['fingerprint', 'state', 'last_raised_at'], 'oal_fgr_sta_idx');
            $table->index(['shop_id', 'state', 'last_raised_at'], 'oal_shop_sta_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ops_alerts');
    }
};
