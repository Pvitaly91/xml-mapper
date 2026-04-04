<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('account_state', 40)->default('active')->after('is_active');
            $table->unsignedSmallInteger('failed_login_attempts')->default(0)->after('account_state');
            $table->timestamp('locked_until')->nullable()->after('failed_login_attempts');
            $table->timestamp('last_login_at')->nullable()->after('locked_until');
            $table->string('last_login_ip', 45)->nullable()->after('last_login_at');
            $table->timestamp('password_changed_at')->nullable()->after('last_login_ip');
            $table->timestamp('password_reset_required_at')->nullable()->after('password_changed_at');
            $table->timestamp('invite_accepted_at')->nullable()->after('password_reset_required_at');
            $table->text('mfa_secret')->nullable()->after('invite_accepted_at');
            $table->text('mfa_pending_secret')->nullable()->after('mfa_secret');
            $table->json('mfa_recovery_codes')->nullable()->after('mfa_pending_secret');
            $table->timestamp('mfa_enabled_at')->nullable()->after('mfa_recovery_codes');
            $table->timestamp('mfa_last_verified_at')->nullable()->after('mfa_enabled_at');

            $table->index(['account_state'], 'idx_usr_state');
            $table->index(['locked_until'], 'idx_usr_lockd');
            $table->index(['mfa_enabled_at'], 'idx_usr_mfa');
        });

        DB::table('users')
            ->whereNull('password_changed_at')
            ->update([
                'account_state' => DB::raw("CASE WHEN is_active = 1 THEN 'active' ELSE 'suspended' END"),
                'password_changed_at' => now(),
            ]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex('idx_usr_state');
            $table->dropIndex('idx_usr_lockd');
            $table->dropIndex('idx_usr_mfa');
            $table->dropColumn([
                'account_state',
                'failed_login_attempts',
                'locked_until',
                'last_login_at',
                'last_login_ip',
                'password_changed_at',
                'password_reset_required_at',
                'invite_accepted_at',
                'mfa_secret',
                'mfa_pending_secret',
                'mfa_recovery_codes',
                'mfa_enabled_at',
                'mfa_last_verified_at',
            ]);
        });
    }
};
