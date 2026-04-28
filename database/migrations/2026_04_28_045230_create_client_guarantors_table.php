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
        Schema::create('client_guarantors', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('guarantor_client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->string('guarantor_full_name')->nullable();
            $table->string('guarantor_phone_number', 32)->nullable();
            $table->string('relationship_type', 64)->nullable();
            $table->string('status', 32)->default('active')->index();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->string('verification_status', 32)->default('pending')->index();
            $table->foreignId('document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->timestamps();

            $table->index(['client_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('client_guarantors');
    }
};
