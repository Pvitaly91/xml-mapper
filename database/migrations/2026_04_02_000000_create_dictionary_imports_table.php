<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dictionary_imports', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->string('source_path');
            $table->string('original_filename')->nullable();
            $table->string('source_format', 16);
            $table->string('checksum', 64);
            $table->unsignedInteger('rows_total')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('deactivated_count')->default(0);
            $table->boolean('dry_run')->default(false);
            $table->string('status')->default('running');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('error_summary')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('initiated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['type', 'status']);
            $table->index(['type', 'checksum']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dictionary_imports');
    }
};
