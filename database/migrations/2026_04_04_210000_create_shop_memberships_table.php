<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_memberships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shop_id')->nullable()->constrained()->nullOnDelete();
            $table->string('role', 40);
            $table->string('status', 20)->default('active');
            $table->foreignId('invited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'shop_id'], 'uq_sm_user_shop');
            $table->index(['shop_id', 'status'], 'idx_sm_shop_stat');
            $table->index(['user_id', 'status'], 'idx_sm_user_stat');
            $table->index(['role', 'status'], 'idx_sm_role_stat');
        });

        if (Schema::hasTable('users') && Schema::hasTable('shops')) {
            $now = now();

            $rows = DB::table('users')
                ->whereNotNull('shop_id')
                ->where('is_active', true)
                ->get(['id', 'shop_id']);

            foreach ($rows as $row) {
                $exists = DB::table('shop_memberships')
                    ->where('user_id', $row->id)
                    ->where('shop_id', $row->shop_id)
                    ->exists();

                if ($exists) {
                    continue;
                }

                DB::table('shop_memberships')->insert([
                    'user_id' => $row->id,
                    'shop_id' => $row->shop_id,
                    'role' => 'shop_admin',
                    'status' => 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_memberships');
    }
};
