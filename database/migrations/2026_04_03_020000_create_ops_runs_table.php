<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ops_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id')->nullable();
            $table->unsignedBigInteger('feed_profile_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('type', 32);
            $table->string('status', 32)->default('running');
            $table->string('artifact_path')->nullable();
            $table->unsignedBigInteger('artifact_size_bytes')->nullable();
            $table->json('summary')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->foreign('shop_id', 'opsr_shop_fk')->references('id')->on('shops')->nullOnDelete();
            $table->foreign('feed_profile_id', 'opsr_prof_fk')->references('id')->on('feed_profiles')->nullOnDelete();
            $table->foreign('user_id', 'opsr_user_fk')->references('id')->on('users')->nullOnDelete();

            $table->index(['type', 'status', 'started_at'], 'opsr_typ_sta_idx');
            $table->index(['shop_id', 'type', 'started_at'], 'opsr_shop_typ_idx');
            $table->index(['feed_profile_id', 'type', 'started_at'], 'opsr_prof_typ_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ops_runs');
    }
};
