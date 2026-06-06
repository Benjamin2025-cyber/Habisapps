<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('media_storage_migrations', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('source_disk');
            $table->string('target_disk');
            $table->string('status')->default('pending'); // pending, running, completed, failed, cancelled
            $table->boolean('dry_run')->default(false);
            $table->unsignedBigInteger('total_candidates')->default(0);
            $table->unsignedBigInteger('processed_count')->default(0);
            $table->unsignedBigInteger('migrated_count')->default(0);
            $table->unsignedBigInteger('failed_count')->default(0);
            $table->unsignedBigInteger('total_bytes')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            // Nullable so system/CLI-triggered migrations (no authenticated
            // operator) can be tracked. API-triggered runs always set it.
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('failure_summary')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('requested_by_user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('media_storage_migrations');
    }
};
