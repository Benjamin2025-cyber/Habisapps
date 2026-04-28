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
        Schema::create('loans', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->foreignId('agency_id')->constrained('agencies')->restrictOnDelete();
            $table->foreignId('loan_product_id')->constrained('loan_products')->restrictOnDelete();
            $table->string('loan_number', 64)->unique();
            $table->bigInteger('requested_amount_minor');
            $table->bigInteger('approved_principal_minor')->nullable();
            $table->string('currency', 3);
            $table->date('applied_on');
            $table->date('approved_on')->nullable();
            $table->date('disbursed_on')->nullable();
            $table->date('closed_on')->nullable();
            $table->string('status', 32)->default('application')->index();
            $table->text('purpose')->nullable();
            $table->foreignId('sector_id')->nullable()->constrained('sectors')->nullOnDelete();
            $table->foreignId('sub_sector_id')->nullable()->constrained('sub_sectors')->nullOnDelete();
            $table->json('formula_policy_snapshot')->nullable();
            $table->timestamps();

            $table->index(['agency_id', 'status']);
            $table->index(['client_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loans');
    }
};
