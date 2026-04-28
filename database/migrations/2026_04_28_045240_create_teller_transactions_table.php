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
        Schema::create('teller_transactions', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('teller_session_id')->constrained('teller_sessions')->restrictOnDelete();
            $table->string('transaction_type', 64)->index();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('customer_account_id')->nullable()->constrained('customer_accounts')->nullOnDelete();
            $table->unsignedBigInteger('loan_id')->nullable()->index();
            $table->bigInteger('amount_minor');
            $table->string('currency', 3);
            $table->string('status', 32)->default('posted')->index();
            $table->string('reference', 64)->unique();
            $table->string('idempotency_key', 128)->nullable()->unique();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->unsignedBigInteger('reversal_of_teller_transaction_id')->nullable()->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('teller_transactions');
    }
};
