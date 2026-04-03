<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotion_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shop_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('source_feed_profile_id')->nullable()->constrained('feed_profiles')->nullOnDelete();
            $table->foreignId('target_feed_profile_id')->nullable()->constrained('feed_profiles')->nullOnDelete();
            $table->foreignId('source_snapshot_id')->nullable()->constrained('promotion_snapshots')->nullOnDelete();
            $table->foreignId('target_snapshot_id')->nullable()->constrained('promotion_snapshots')->nullOnDelete();
            $table->foreignId('result_snapshot_id')->nullable()->constrained('promotion_snapshots')->nullOnDelete();
            $table->foreignId('rollback_of_promotion_run_id')->nullable()->constrained('promotion_runs')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_environment', 32)->nullable();
            $table->string('target_environment', 32)->nullable();
            $table->string('mode', 24);
            $table->string('strategy', 32)->nullable();
            $table->string('status', 24)->default('running');
            $table->string('reason', 500)->nullable();
            $table->json('summary')->nullable();
            $table->json('warnings')->nullable();
            $table->json('errors')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index('target_feed_profile_id', 'pr_tgt_idx');
            $table->index('source_feed_profile_id', 'pr_src_idx');
            $table->index('source_snapshot_id', 'pr_srcsnap_idx');
            $table->index('target_snapshot_id', 'pr_tgtsnap_idx');
            $table->index('mode', 'pr_mode_idx');
            $table->index('status', 'pr_stat_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_runs');
    }
};
