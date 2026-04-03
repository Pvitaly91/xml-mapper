<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ops_policy_results', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->unsignedBigInteger('feed_profile_id');
            $table->unsignedBigInteger('feed_generation_id')->nullable();
            $table->unsignedBigInteger('feed_hypercare_window_id')->nullable();
            $table->string('policy_key', 64);
            $table->string('status', 16)->default('ok');
            $table->string('summary', 255);
            $table->timestamp('due_at')->nullable();
            $table->timestamp('evaluated_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->foreign('shop_id', 'opr_shop_fk')->references('id')->on('shops')->cascadeOnDelete();
            $table->foreign('feed_profile_id', 'opr_prof_fk')->references('id')->on('feed_profiles')->cascadeOnDelete();
            $table->foreign('feed_generation_id', 'opr_gen_fk')->references('id')->on('feed_generations')->nullOnDelete();
            $table->foreign('feed_hypercare_window_id', 'opr_hyp_fk')->references('id')->on('feed_hypercare_windows')->nullOnDelete();

            $table->unique(['feed_profile_id', 'feed_hypercare_window_id', 'policy_key'], 'opr_hyp_pol_unq');
            $table->index(['feed_profile_id', 'status', 'evaluated_at'], 'opr_prof_sta_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ops_policy_results');
    }
};
