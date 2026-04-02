<?php

use App\Models\FeedGeneration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feed_generations', function (Blueprint $table) {
            $table->string('release_status', 32)->default(FeedGeneration::RELEASE_STATUS_BUILT)->after('status');
            $table->timestamp('release_candidate_at')->nullable()->after('published_at');
            $table->timestamp('approved_at')->nullable()->after('release_candidate_at');
            $table->unsignedBigInteger('approved_by_user_id')->nullable()->after('approved_at');
            $table->string('last_smoke_check_status', 32)->nullable()->after('approved_by_user_id');
            $table->timestamp('last_smoke_check_at')->nullable()->after('last_smoke_check_status');

            $table->index(['feed_profile_id', 'release_status'], 'fg_rel_stat_idx');
            $table->index(['feed_profile_id', 'last_smoke_check_status'], 'fg_smk_stat_idx');
            $table->foreign('approved_by_user_id', 'fg_appr_user_fk')->references('id')->on('users')->nullOnDelete();
        });

        DB::table('feed_generations')
            ->whereNotNull('published_at')
            ->update(['release_status' => FeedGeneration::RELEASE_STATUS_PUBLISHED]);
    }

    public function down(): void
    {
        Schema::table('feed_generations', function (Blueprint $table) {
            $table->dropForeign('fg_appr_user_fk');
            $table->dropIndex('fg_rel_stat_idx');
            $table->dropIndex('fg_smk_stat_idx');
            $table->dropColumn([
                'release_status',
                'release_candidate_at',
                'approved_at',
                'approved_by_user_id',
                'last_smoke_check_status',
                'last_smoke_check_at',
            ]);
        });
    }
};
