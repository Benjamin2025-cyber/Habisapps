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
        Schema::create('batch_runs', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('batch_procedure_id')->constrained('batch_procedures')->restrictOnDelete();
            $table->foreignId('agency_id')->nullable()->constrained('agencies')->nullOnDelete();
            $table->date('business_date')->index();
            $table->string('status', 32)->default('pending')->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->foreignId('operator_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('idempotency_key', 128)->nullable();
            $table->json('summary_payload')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->index(['batch_procedure_id', 'business_date', 'status']);
            $table->unique(['batch_procedure_id', 'agency_id', 'business_date'], 'uniq_batch_scope_date');
            $table->unique('idempotency_key');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('batch_runs');
    }
};
