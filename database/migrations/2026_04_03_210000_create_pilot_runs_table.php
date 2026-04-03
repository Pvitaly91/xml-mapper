<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pilot_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shop_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('feed_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_connection_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('source_snapshot_id')->nullable()->constrained('promotion_snapshots')->nullOnDelete();
            $table->foreignId('candidate_generation_id')->nullable()->constrained('feed_generations')->nullOnDelete();
            $table->foreignId('published_generation_id')->nullable()->constrained('feed_generations')->nullOnDelete();
            $table->foreignId('initiated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('environment_class', 32);
            $table->string('environment_label', 64)->nullable();
            $table->string('state', 48)->default('planned');
            $table->string('current_step', 48)->nullable();
            $table->string('blocking_reason', 500)->nullable();
            $table->text('note')->nullable();
            $table->json('summary')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index('shop_id', 'pltrun_shop_idx');
            $table->index('feed_profile_id', 'pltrun_feed_idx');
            $table->index('state', 'pltrun_state_idx');
            $table->index('owner_user_id', 'pltrun_owner_idx');
            $table->index('current_step', 'pltrun_step_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pilot_runs');
    }
};
