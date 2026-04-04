<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_invites', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shop_membership_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('pending');
            $table->string('email');
            $table->string('token_hash', 64);
            $table->text('token_ciphertext')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_resent_at')->nullable();
            $table->timestamps();

            $table->unique(['token_hash'], 'uq_ainv_tok');
            $table->index(['user_id', 'status'], 'idx_ainv_user');
            $table->index(['shop_membership_id', 'status'], 'idx_ainv_mem');
            $table->index(['expires_at', 'status'], 'idx_ainv_exp');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_invites');
    }
};
