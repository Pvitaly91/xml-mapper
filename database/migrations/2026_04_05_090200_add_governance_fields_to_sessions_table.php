<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sessions', function (Blueprint $table): void {
            $table->timestamp('created_at')->nullable()->after('user_agent');
            $table->timestamp('last_seen_at')->nullable()->after('created_at');
            $table->string('device_label', 120)->nullable()->after('last_seen_at');
            $table->timestamp('mfa_verified_at')->nullable()->after('device_label');
            $table->timestamp('revoked_at')->nullable()->after('mfa_verified_at');
            $table->foreignId('revoked_by_user_id')->nullable()->after('revoked_at')->constrained('users')->nullOnDelete();
            $table->text('break_glass_reason')->nullable()->after('revoked_by_user_id');
            $table->timestamp('break_glass_started_at')->nullable()->after('break_glass_reason');
            $table->timestamp('break_glass_expires_at')->nullable()->after('break_glass_started_at');
            $table->timestamp('break_glass_ended_at')->nullable()->after('break_glass_expires_at');

            $table->index(['user_id', 'last_seen_at'], 'idx_ses_user');
            $table->index(['revoked_at', 'last_seen_at'], 'idx_ses_rev');
            $table->index(['break_glass_expires_at'], 'idx_ses_brk');
        });

        DB::table('sessions')->update([
            'created_at' => DB::raw('CURRENT_TIMESTAMP'),
            'last_seen_at' => DB::raw('CURRENT_TIMESTAMP'),
        ]);
    }

    public function down(): void
    {
        Schema::table('sessions', function (Blueprint $table): void {
            $table->dropIndex('idx_ses_user');
            $table->dropIndex('idx_ses_rev');
            $table->dropIndex('idx_ses_brk');
            $table->dropConstrainedForeignId('revoked_by_user_id');
            $table->dropColumn([
                'created_at',
                'last_seen_at',
                'device_label',
                'mfa_verified_at',
                'revoked_at',
                'break_glass_reason',
                'break_glass_started_at',
                'break_glass_expires_at',
                'break_glass_ended_at',
            ]);
        });
    }
};
