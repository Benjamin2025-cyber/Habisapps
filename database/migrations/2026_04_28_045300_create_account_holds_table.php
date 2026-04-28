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
        Schema::create('account_holds', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('customer_account_id')->constrained('customer_accounts')->restrictOnDelete();
            $table->bigInteger('amount_minor');
            $table->string('currency', 3);
            $table->string('reason_type', 64);
            $table->string('status', 32)->default('active')->index();
            $table->timestamp('placed_at')->nullable();
            $table->foreignId('placed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('released_at')->nullable();
            $table->foreignId('released_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reference', 128)->nullable();
            $table->timestamps();

            $table->index(['customer_account_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_holds');
    }
};
