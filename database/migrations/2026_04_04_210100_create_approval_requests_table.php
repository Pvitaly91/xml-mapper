<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shop_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 120);
            $table->string('classification', 20);
            $table->string('environment_class', 20)->nullable();
            $table->string('environment_label', 60)->nullable();
            $table->string('status', 20)->default('pending');
            $table->boolean('requires_four_eyes')->default(false);
            $table->boolean('platform_admin_only')->default(false);
            $table->nullableMorphs('target');
            $table->string('target_label', 255)->nullable();
            $table->string('correlation_id', 64)->nullable();
            $table->string('reason', 500)->nullable();
            $table->text('note')->nullable();
            $table->json('payload_summary')->nullable();
            $table->text('payload')->nullable();
            $table->json('result_summary')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'status'], 'idx_apr_shop_stat');
            $table->index(['action', 'status'], 'idx_apr_act_stat');
            $table->index(['target_type', 'target_id'], 'idx_apr_target');
            $table->index(['requested_by_user_id', 'status'], 'idx_apr_req_stat');
            $table->index(['expires_at', 'status'], 'idx_apr_exp_stat');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_requests');
    }
};
