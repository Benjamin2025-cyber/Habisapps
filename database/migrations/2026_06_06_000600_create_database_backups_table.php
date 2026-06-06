<?php

declare(strict_types=1);

use App\Models\DatabaseBackup;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('database_backups', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('filename');
            $table->string('disk', 64);
            // Raw storage path is persisted for the runner only; it is never
            // exposed to ordinary API responses (ADM-DB-002).
            $table->string('path');
            $table->string('status', 32)->default(DatabaseBackup::STATUS_PENDING)->index();
            $table->string('database_connection', 64);
            $table->string('database_driver', 32);
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('checksum_sha256', 64)->nullable();
            $table->boolean('encrypted')->default(false);
            $table->string('compression', 32)->nullable();
            $table->string('verification_status', 32)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('failure_reason', 1000)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['database_connection', 'status']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('database_backups');
    }
};
