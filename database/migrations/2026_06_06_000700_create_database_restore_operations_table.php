<?php

declare(strict_types=1);

use App\Models\DatabaseRestoreOperation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('database_restore_operations', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('database_backup_id')->nullable()->constrained('database_backups')->nullOnDelete();
            $table->string('status', 32)->default(DatabaseRestoreOperation::STATUS_PENDING)->index();
            $table->string('target', 32);
            $table->string('mode', 32);
            $table->foreignId('planned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('executed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            // Plan expiry: the short-lived window in which the plan may execute.
            $table->timestamp('expires_at')->nullable();
            $table->string('confirmation_method', 32)->nullable();
            $table->foreignId('pre_restore_backup_id')->nullable()->constrained('database_backups')->nullOnDelete();
            $table->string('failure_reason', 1000)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('database_restore_operations');
    }
};
