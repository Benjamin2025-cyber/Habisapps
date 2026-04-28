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
        Schema::create('ledger_accounts', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('agency_id')->nullable()->constrained('agencies')->nullOnDelete();
            $table->string('code', 64);
            $table->string('name');
            $table->string('account_class', 32);
            $table->string('account_type', 64)->nullable();
            $table->foreignId('parent_account_id')->nullable()->constrained('ledger_accounts')->nullOnDelete();
            $table->string('normal_balance_side', 6);
            $table->string('status', 32)->default('active')->index();
            $table->timestamps();

            $table->unique(['agency_id', 'code']);
            $table->index(['agency_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ledger_accounts');
    }
};
