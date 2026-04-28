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
        Schema::create('till_reconciliation_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('till_reconciliation_id')->constrained('till_reconciliations')->cascadeOnDelete();
            $table->foreignId('denomination_id')->constrained('denominations')->restrictOnDelete();
            $table->unsignedInteger('count')->default(0);
            $table->bigInteger('declared_amount_minor')->nullable();
            $table->timestamps();

            $table->unique(['till_reconciliation_id', 'denomination_id'], 'uniq_reconciliation_denomination');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('till_reconciliation_lines');
    }
};
