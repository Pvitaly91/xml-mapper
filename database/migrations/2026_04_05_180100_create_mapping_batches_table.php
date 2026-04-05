<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mapping_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('feed_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('approval_request_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('executed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('rolled_back_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('batch_type', 32);
            $table->string('mapping_type', 32)->nullable();
            $table->string('status', 32);
            $table->string('strategy', 32)->default('safe');
            $table->string('risk_level', 32)->default('standard');
            $table->string('correlation_id')->nullable();
            $table->decimal('threshold', 5, 2)->nullable();
            $table->json('scope')->nullable();
            $table->text('reason')->nullable();
            $table->text('note')->nullable();
            $table->json('summary')->nullable();
            $table->json('warnings')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('rolled_back_at')->nullable();
            $table->timestamps();

            $table->index(['feed_profile_id', 'status'], 'mapb_prof_idx');
            $table->index(['shop_id', 'batch_type'], 'mapb_shop_idx');
            $table->index('approval_request_id', 'mapb_apr_idx');
        });

        Schema::create('mapping_batch_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mapping_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('feed_profile_id')->constrained()->cascadeOnDelete();
            $table->string('mapping_type', 32);
            $table->string('source_key');
            $table->string('target_key')->nullable();
            $table->string('status', 32);
            $table->boolean('is_manual_conflict')->default(false);
            $table->string('model_type')->nullable();
            $table->unsignedBigInteger('model_id')->nullable();
            $table->json('before_state')->nullable();
            $table->json('after_state')->nullable();
            $table->json('suggestion')->nullable();
            $table->string('warning')->nullable();
            $table->timestamps();

            $table->index(['mapping_batch_id', 'status'], 'mbe_batch_idx');
            $table->index(['feed_profile_id', 'mapping_type'], 'mbe_prof_idx');
            $table->index(['model_type', 'model_id'], 'mbe_model_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mapping_batch_entries');
        Schema::dropIfExists('mapping_batches');
    }
};
