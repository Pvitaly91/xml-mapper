<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('governance_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shop_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('approval_request_id')->nullable()->constrained()->nullOnDelete();
            $table->string('category', 40);
            $table->string('event_type', 120);
            $table->string('severity', 20)->default('info');
            $table->string('summary', 500);
            $table->nullableMorphs('target');
            $table->string('target_label', 255)->nullable();
            $table->string('correlation_id', 64)->nullable();
            $table->json('context')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'occurred_at'], 'idx_ga_shop_dt');
            $table->index(['user_id', 'occurred_at'], 'idx_ga_user_dt');
            $table->index(['event_type', 'occurred_at'], 'idx_ga_evt_dt');
            $table->index(['approval_request_id', 'occurred_at'], 'idx_ga_apr_dt');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('governance_audits');
    }
};
