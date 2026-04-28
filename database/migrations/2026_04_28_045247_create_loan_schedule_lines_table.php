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
        Schema::create('loan_schedule_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_schedule_snapshot_id')->constrained('loan_schedule_snapshots')->cascadeOnDelete();
            $table->unsignedSmallInteger('installment_number');
            $table->date('due_date');
            $table->bigInteger('principal_minor')->default(0);
            $table->bigInteger('interest_minor')->default(0);
            $table->bigInteger('fees_minor')->default(0);
            $table->bigInteger('insurance_minor')->default(0);
            $table->bigInteger('tax_minor')->default(0);
            $table->string('currency', 3);
            $table->string('status', 32)->default('scheduled')->index();
            $table->timestamps();

            $table->unique(['loan_schedule_snapshot_id', 'installment_number'], 'uniq_schedule_installment');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loan_schedule_lines');
    }
};
