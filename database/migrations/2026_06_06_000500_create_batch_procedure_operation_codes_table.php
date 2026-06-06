<?php

declare(strict_types=1);

use App\Models\BatchProcedureOperationCode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('batch_procedure_operation_codes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('batch_procedure_id')->constrained('batch_procedures')->cascadeOnDelete();
            $table->foreignId('operation_code_id')->constrained('operation_codes')->cascadeOnDelete();
            $table->string('status', 32)->default(BatchProcedureOperationCode::STATUS_ACTIVE)->index();
            $table->timestamps();

            $table->unique(['batch_procedure_id', 'operation_code_id'], 'batch_proc_op_code_unique');
            $table->index(['operation_code_id', 'status'], 'batch_proc_op_code_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('batch_procedure_operation_codes');
    }
};
