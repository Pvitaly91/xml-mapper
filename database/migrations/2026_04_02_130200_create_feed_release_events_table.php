<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feed_release_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('feed_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('feed_generation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 64);
            $table->text('reason')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();

            $table->index(['feed_profile_id', 'action', 'occurred_at'], 'fre_prof_act_idx');
            $table->index(['feed_generation_id', 'occurred_at'], 'fre_gen_occ_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feed_release_events');
    }
};
