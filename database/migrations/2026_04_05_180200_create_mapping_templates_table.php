<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mapping_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shop_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('feed_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('scope', 32);
            $table->string('template_type', 32)->default('mapping_bundle');
            $table->unsignedInteger('version')->default(1);
            $table->string('fingerprint', 64);
            $table->boolean('is_active')->default(true);
            $table->json('payload');
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'scope', 'is_active'], 'mapt_shop_idx');
            $table->index(['feed_profile_id', 'scope'], 'mapt_prof_idx');
            $table->index('fingerprint', 'mapt_fp_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mapping_templates');
    }
};
