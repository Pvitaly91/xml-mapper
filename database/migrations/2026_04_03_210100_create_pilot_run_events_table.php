<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pilot_run_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pilot_run_id')->constrained('pilot_runs')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_type', 32)->default('transition');
            $table->string('step', 48)->nullable();
            $table->string('from_state', 48)->nullable();
            $table->string('to_state', 48)->nullable();
            $table->string('status', 24)->default('info');
            $table->string('title', 160);
            $table->text('message')->nullable();
            $table->string('blocking_reason', 500)->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();

            $table->index('pilot_run_id', 'pltevt_run_idx');
            $table->index('event_type', 'pltevt_type_idx');
            $table->index('step', 'pltevt_step_idx');
            $table->index('occurred_at', 'pltevt_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pilot_run_events');
    }
};
