<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mapping_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shop_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('feed_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('rule_type', 32);
            $table->string('match_type', 32);
            $table->string('source_pattern')->nullable();
            $table->string('source_normalized')->nullable();
            $table->string('source_attribute_code', 120)->nullable();
            $table->string('source_category_path')->nullable();
            $table->string('vendor_scope')->nullable();
            $table->string('brand_scope')->nullable();
            $table->string('target_reference')->nullable();
            $table->string('target_label')->nullable();
            $table->json('target_payload')->nullable();
            $table->text('explanation')->nullable();
            $table->json('evidence')->nullable();
            $table->unsignedSmallInteger('priority')->default(100);
            $table->boolean('is_auto_apply_safe')->default(false);
            $table->boolean('is_active')->default(true);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['feed_profile_id', 'rule_type', 'is_active'], 'mapr_prof_idx');
            $table->index(['shop_id', 'rule_type', 'is_active'], 'mapr_shop_idx');
            $table->index(['rule_type', 'match_type', 'priority'], 'mapr_type_idx');
            $table->index('source_normalized', 'mapr_norm_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mapping_rules');
    }
};
