<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_launch_tuning_actions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('merchant_launch_id')->constrained('merchant_launches')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 48);
            $table->string('mode', 16)->default('normal');
            $table->string('reason', 500);
            $table->json('summary')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->index('merchant_launch_id', 'mlt_launch_idx');
            $table->index('type', 'mlt_type_idx');
            $table->index('mode', 'mlt_mode_idx');
            $table->index('applied_at', 'mlt_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_launch_tuning_actions');
    }
};
