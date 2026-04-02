<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const STABLE_OFFER_UNIQUE = 'src_var_shop_offer_uidx';

    private const EXTERNAL_OFFER_UNIQUE = 'src_var_shop_conn_ext_offer_uidx';

    private const PRODUCT_ENABLED_INDEX = 'src_var_product_enabled_idx';

    public function up(): void
    {
        if (! Schema::hasTable('source_variants')) {
            Schema::create('source_variants', function (Blueprint $table) {
                $table->id();
                $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
                $table->foreignId('source_connection_id')->constrained()->cascadeOnDelete();
                $table->foreignId('source_import_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('source_product_id')->constrained()->cascadeOnDelete();
                $table->string('external_offer_id')->nullable();
                $table->string('external_sku')->nullable();
                $table->string('stable_offer_id');
                $table->string('offer_identity_key');
                $table->string('export_key_hash', 64);
                $table->string('published_export_key_hash', 64)->nullable();
                $table->string('title')->nullable();
                $table->decimal('price', 12, 2)->nullable();
                $table->decimal('old_price', 12, 2)->nullable();
                $table->string('currency', 3)->default('UAH');
                $table->integer('quantity')->nullable();
                $table->boolean('is_available')->default(true);
                $table->string('color')->nullable();
                $table->string('size')->nullable();
                $table->string('barcode')->nullable();
                $table->json('images_json')->nullable();
                $table->json('attributes_snapshot')->nullable();
                $table->json('raw_payload')->nullable();
                $table->timestamp('last_seen_at')->nullable();
                $table->timestamp('first_published_at')->nullable();
                $table->timestamp('last_published_at')->nullable();
                $table->boolean('is_enabled')->default(true);
                $table->timestamps();

                $table->unique(['shop_id', 'stable_offer_id'], self::STABLE_OFFER_UNIQUE);
                $table->unique(['shop_id', 'source_connection_id', 'external_offer_id'], self::EXTERNAL_OFFER_UNIQUE);
                $table->index(['source_product_id', 'is_enabled'], self::PRODUCT_ENABLED_INDEX);
            });

            return;
        }

        Schema::table('source_variants', function (Blueprint $table) {
            if (! $this->hasIndex('source_variants', self::STABLE_OFFER_UNIQUE)) {
                $table->unique(['shop_id', 'stable_offer_id'], self::STABLE_OFFER_UNIQUE);
            }

            if (! $this->hasIndex('source_variants', self::EXTERNAL_OFFER_UNIQUE)) {
                $table->unique(['shop_id', 'source_connection_id', 'external_offer_id'], self::EXTERNAL_OFFER_UNIQUE);
            }

            if (! $this->hasIndex('source_variants', self::PRODUCT_ENABLED_INDEX)) {
                $table->index(['source_product_id', 'is_enabled'], self::PRODUCT_ENABLED_INDEX);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_variants');
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
