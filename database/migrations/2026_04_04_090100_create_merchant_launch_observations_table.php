<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_launch_observations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('merchant_launch_id')->constrained('merchant_launches')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('feed_generation_id')->nullable()->constrained('feed_generations')->nullOnDelete();
            $table->foreignId('feed_item_id')->nullable()->constrained('feed_items')->nullOnDelete();
            $table->foreignId('feedback_import_id')->nullable()->constrained('feedback_imports')->nullOnDelete();
            $table->foreignId('ops_alert_id')->nullable()->constrained('ops_alerts')->nullOnDelete();
            $table->string('type', 48);
            $table->string('severity', 16)->default('medium');
            $table->string('source', 48)->default('operator');
            $table->text('note');
            $table->json('meta')->nullable();
            $table->timestamp('observed_at')->nullable();
            $table->timestamps();

            $table->index('merchant_launch_id', 'mlo_launch_idx');
            $table->index('type', 'mlo_type_idx');
            $table->index('severity', 'mlo_sev_idx');
            $table->index('observed_at', 'mlo_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_launch_observations');
    }
};
