<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const UNIQUE_INDEX = 'src_cat_shop_conn_ext_uidx';

    private const RZ_INDEX = 'src_cat_shop_rz_idx';

    public function up(): void
    {
        if (! Schema::hasTable('source_categories')) {
            Schema::create('source_categories', function (Blueprint $table) {
                $table->id();
                $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
                $table->foreignId('source_connection_id')->constrained()->cascadeOnDelete();
                $table->foreignId('parent_id')->nullable()->constrained('source_categories')->nullOnDelete();
                $table->string('external_id');
                $table->string('parent_external_id')->nullable();
                $table->string('name');
                $table->string('full_path')->nullable();
                $table->string('rz_id')->nullable();
                $table->boolean('is_active')->default(true);
                $table->json('raw_payload')->nullable();
                $table->timestamps();

                $table->unique(['shop_id', 'source_connection_id', 'external_id'], self::UNIQUE_INDEX);
                $table->index(['shop_id', 'rz_id'], self::RZ_INDEX);
            });

            return;
        }

        Schema::table('source_categories', function (Blueprint $table) {
            if (! $this->hasIndex('source_categories', self::UNIQUE_INDEX)) {
                $table->unique(['shop_id', 'source_connection_id', 'external_id'], self::UNIQUE_INDEX);
            }

            if (! $this->hasIndex('source_categories', self::RZ_INDEX)) {
                $table->index(['shop_id', 'rz_id'], self::RZ_INDEX);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_categories');
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
