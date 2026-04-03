<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feedback_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->unsignedBigInteger('feed_profile_id');
            $table->unsignedBigInteger('feedback_import_id');
            $table->unsignedBigInteger('feed_generation_id')->nullable();
            $table->unsignedBigInteger('feed_item_id')->nullable();
            $table->unsignedBigInteger('source_product_id')->nullable();
            $table->unsignedBigInteger('source_variant_id')->nullable();
            $table->unsignedBigInteger('resolution_user_id')->nullable();
            $table->string('status', 32)->default('unknown');
            $table->string('resolution_status', 32)->default('open');
            $table->string('external_item_reference')->nullable();
            $table->string('offer_id')->nullable();
            $table->string('vendor_code')->nullable();
            $table->string('article')->nullable();
            $table->string('rejection_reason_code')->nullable();
            $table->text('rejection_reason_message')->nullable();
            $table->json('raw_payload')->nullable();
            $table->text('resolution_note')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->foreign('shop_id', 'fbr_shop_fk')->references('id')->on('shops')->cascadeOnDelete();
            $table->foreign('feed_profile_id', 'fbr_prof_fk')->references('id')->on('feed_profiles')->cascadeOnDelete();
            $table->foreign('feedback_import_id', 'fbr_imp_fk')->references('id')->on('feedback_imports')->cascadeOnDelete();
            $table->foreign('feed_generation_id', 'fbr_gen_fk')->references('id')->on('feed_generations')->nullOnDelete();
            $table->foreign('feed_item_id', 'fbr_item_fk')->references('id')->on('feed_items')->nullOnDelete();
            $table->foreign('source_product_id', 'fbr_prod_fk')->references('id')->on('source_products')->nullOnDelete();
            $table->foreign('source_variant_id', 'fbr_var_fk')->references('id')->on('source_variants')->nullOnDelete();
            $table->foreign('resolution_user_id', 'fbr_rusr_fk')->references('id')->on('users')->nullOnDelete();

            $table->index(['feed_profile_id', 'status'], 'fbr_prof_sta_idx');
            $table->index(['feed_profile_id', 'resolution_status'], 'fbr_prof_res_idx');
            $table->index(['feedback_import_id', 'status'], 'fbr_imp_sta_idx');
            $table->index(['feed_item_id', 'status'], 'fbr_item_sta_idx');
            $table->index(['rejection_reason_code', 'status'], 'fbr_rc_sta_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback_records');
    }
};
