<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotion_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shop_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('feed_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('environment_class', 32);
            $table->string('environment_label', 64)->nullable();
            $table->string('source_type', 32)->default('generated');
            $table->string('name', 120)->nullable();
            $table->string('checksum', 64);
            $table->string('mapping_fingerprint', 64)->nullable();
            $table->string('settings_fingerprint', 64)->nullable();
            $table->string('source_connection_fingerprint', 64)->nullable();
            $table->json('payload');
            $table->json('summary')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();

            $table->unique('checksum', 'ps_chk_uq');
            $table->index('feed_profile_id', 'ps_prof_idx');
            $table->index('shop_id', 'ps_shop_idx');
            $table->index('environment_class', 'ps_env_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_snapshots');
    }
};
