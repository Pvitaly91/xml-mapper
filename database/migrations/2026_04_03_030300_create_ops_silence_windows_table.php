<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ops_silence_windows', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->unsignedBigInteger('feed_profile_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('cleared_by_user_id')->nullable();
            $table->timestamp('active_from')->nullable();
            $table->timestamp('active_to')->nullable();
            $table->timestamp('cleared_at')->nullable();
            $table->string('severity_threshold', 16)->default('critical');
            $table->text('note')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->foreign('shop_id', 'osw_shop_fk')->references('id')->on('shops')->cascadeOnDelete();
            $table->foreign('feed_profile_id', 'osw_prof_fk')->references('id')->on('feed_profiles')->cascadeOnDelete();
            $table->foreign('user_id', 'osw_user_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('cleared_by_user_id', 'osw_cusr_fk')->references('id')->on('users')->nullOnDelete();

            $table->index(['feed_profile_id', 'active_from', 'active_to'], 'osw_prof_act_idx');
            $table->index(['shop_id', 'active_from', 'active_to'], 'osw_shop_act_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ops_silence_windows');
    }
};
