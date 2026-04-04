<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_launches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shop_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('feed_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pilot_run_id')->nullable()->constrained('pilot_runs')->nullOnDelete();
            $table->foreignId('promotion_run_id')->nullable()->constrained('promotion_runs')->nullOnDelete();
            $table->foreignId('published_generation_id')->nullable()->constrained('feed_generations')->nullOnDelete();
            $table->foreignId('initiated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('environment_class', 32);
            $table->string('environment_label', 64)->nullable();
            $table->string('state', 32)->default('planned');
            $table->string('handover_state', 24)->default('handover_blocked');
            $table->timestamp('planned_start_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('actual_published_at')->nullable();
            $table->timestamp('actual_go_live_confirmed_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->unsignedInteger('expected_ready_items')->nullable();
            $table->unsignedInteger('expected_published_count')->nullable();
            $table->unsignedInteger('expected_first_pull_latency_ms')->nullable();
            $table->unsignedInteger('expected_feedback_total')->nullable();
            $table->unsignedInteger('expected_rejection_total')->nullable();
            $table->unsignedInteger('expected_sync_freshness_minutes')->nullable();
            $table->string('outcome', 120)->nullable();
            $table->text('note')->nullable();
            $table->json('summary')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index('shop_id', 'mlch_shop_idx');
            $table->index('feed_profile_id', 'mlch_feed_idx');
            $table->index('state', 'mlch_state_idx');
            $table->index('handover_state', 'mlch_hnd_idx');
            $table->index('owner_user_id', 'mlch_owner_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_launches');
    }
};
