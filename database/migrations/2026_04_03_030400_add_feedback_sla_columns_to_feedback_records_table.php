<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feedback_records', function (Blueprint $table) {
            $table->unsignedBigInteger('acknowledged_by_user_id')->nullable()->after('resolution_user_id');
            $table->timestamp('acknowledged_at')->nullable()->after('imported_at');

            $table->foreign('acknowledged_by_user_id', 'fbr_ausr_fk')->references('id')->on('users')->nullOnDelete();
            $table->index(['feed_profile_id', 'acknowledged_at'], 'fbr_prof_ack_idx');
        });
    }

    public function down(): void
    {
        Schema::table('feedback_records', function (Blueprint $table) {
            $table->dropForeign('fbr_ausr_fk');
            $table->dropIndex('fbr_prof_ack_idx');
            $table->dropColumn(['acknowledged_by_user_id', 'acknowledged_at']);
        });
    }
};
