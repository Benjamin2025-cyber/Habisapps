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
        Schema::create('loan_products', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->string('status', 32)->default('active')->index();
            $table->unsignedSmallInteger('min_term_count')->nullable();
            $table->unsignedSmallInteger('max_term_count')->nullable();
            $table->string('term_unit', 16)->nullable();
            $table->json('allowed_repayment_frequencies')->nullable();
            $table->boolean('requires_guarantor')->default(false);
            $table->boolean('requires_collateral')->default(false);
            $table->string('interest_policy_key', 128)->nullable();
            $table->string('penalty_policy_key', 128)->nullable();
            $table->string('repayment_allocation_policy_key', 128)->nullable();
            $table->string('fee_policy_key', 128)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loan_products');
    }
};
