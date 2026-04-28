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
        Schema::create('loan_schedule_snapshots', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('loan_id')->constrained('loans')->cascadeOnDelete();
            $table->string('formula_engine_key', 128);
            $table->string('formula_engine_version', 64)->nullable();
            $table->string('policy_snapshot_hash', 128);
            $table->foreignId('generated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('generated_at')->nullable();
            $table->string('status', 32)->default('active')->index();
            $table->timestamps();

            $table->index(['loan_id', 'generated_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loan_schedule_snapshots');
    }
};
