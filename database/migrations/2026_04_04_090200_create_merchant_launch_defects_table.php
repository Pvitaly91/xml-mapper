<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_launch_defects', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('merchant_launch_id')->constrained('merchant_launches')->cascadeOnDelete();
            $table->foreignId('merchant_launch_observation_id')->nullable()->constrained('merchant_launch_observations')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('feed_generation_id')->nullable()->constrained('feed_generations')->nullOnDelete();
            $table->foreignId('feed_item_id')->nullable()->constrained('feed_items')->nullOnDelete();
            $table->foreignId('feedback_record_id')->nullable()->constrained('feedback_records')->nullOnDelete();
            $table->foreignId('ops_alert_id')->nullable()->constrained('ops_alerts')->nullOnDelete();
            $table->string('type', 48);
            $table->string('severity', 16)->default('medium');
            $table->string('status', 16)->default('open');
            $table->string('title', 160);
            $table->text('note')->nullable();
            $table->text('resolution_note')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index('merchant_launch_id', 'mld_launch_idx');
            $table->index('type', 'mld_type_idx');
            $table->index('severity', 'mld_sev_idx');
            $table->index('status', 'mld_sta_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_launch_defects');
    }
};
