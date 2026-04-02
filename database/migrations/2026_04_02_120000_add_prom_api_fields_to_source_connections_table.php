<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DRIVER_STATUS_INDEX = 'src_conn_drv_stat_idx';

    private const CHECK_STATUS_INDEX = 'src_conn_chk_stat_idx';

    public function up(): void
    {
        Schema::table('source_connections', function (Blueprint $table): void {
            if (! Schema::hasColumn('source_connections', 'api_base_url')) {
                $table->text('api_base_url')->nullable()->after('credentials');
            }

            if (! Schema::hasColumn('source_connections', 'api_token')) {
                $table->text('api_token')->nullable()->after('api_base_url');
            }

            if (! Schema::hasColumn('source_connections', 'api_version')) {
                $table->string('api_version', 32)->nullable()->after('api_token');
            }

            if (! Schema::hasColumn('source_connections', 'last_connection_check_at')) {
                $table->timestamp('last_connection_check_at')->nullable()->after('options');
            }

            if (! Schema::hasColumn('source_connections', 'last_connection_check_status')) {
                $table->string('last_connection_check_status', 32)->nullable()->after('last_connection_check_at');
            }

            if (! Schema::hasColumn('source_connections', 'last_connection_check_message')) {
                $table->text('last_connection_check_message')->nullable()->after('last_connection_check_status');
            }

            if (! Schema::hasColumn('source_connections', 'last_sync_status')) {
                $table->string('last_sync_status', 32)->nullable()->after('last_connection_check_message');
            }

            if (! Schema::hasColumn('source_connections', 'last_sync_message')) {
                $table->text('last_sync_message')->nullable()->after('last_sync_status');
            }

            if (! Schema::hasColumn('source_connections', 'last_sync_summary')) {
                $table->json('last_sync_summary')->nullable()->after('last_sync_message');
            }
        });

        DB::table('source_connections')
            ->whereNull('driver')
            ->update(['driver' => 'prom_yml']);

        Schema::table('source_connections', function (Blueprint $table): void {
            if (! $this->hasIndex('source_connections', self::DRIVER_STATUS_INDEX)) {
                $table->index(['driver', 'status', 'next_sync_at'], self::DRIVER_STATUS_INDEX);
            }

            if (! $this->hasIndex('source_connections', self::CHECK_STATUS_INDEX)) {
                $table->index(['driver', 'last_connection_check_status'], self::CHECK_STATUS_INDEX);
            }
        });
    }

    public function down(): void
    {
        Schema::table('source_connections', function (Blueprint $table): void {
            if ($this->hasIndex('source_connections', self::DRIVER_STATUS_INDEX)) {
                $table->dropIndex(self::DRIVER_STATUS_INDEX);
            }

            if ($this->hasIndex('source_connections', self::CHECK_STATUS_INDEX)) {
                $table->dropIndex(self::CHECK_STATUS_INDEX);
            }

            foreach ([
                'last_sync_summary',
                'last_sync_message',
                'last_sync_status',
                'last_connection_check_message',
                'last_connection_check_status',
                'last_connection_check_at',
                'api_version',
                'api_token',
                'api_base_url',
            ] as $column) {
                if (Schema::hasColumn('source_connections', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function hasIndex(string $table, string $index): bool
    {
        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            return collect(DB::select("SHOW INDEX FROM `$table`"))
                ->contains(fn (object $row): bool => $row->Key_name === $index);
        }

        if ($driver === 'sqlite') {
            return collect(DB::select("PRAGMA index_list('$table')"))
                ->contains(fn (object $row): bool => $row->name === $index);
        }

        return false;
    }
};
