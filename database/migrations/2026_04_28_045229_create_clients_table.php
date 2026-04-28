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
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('agency_id')->constrained('agencies')->restrictOnDelete();
            $table->string('client_reference', 64);
            $table->string('first_name', 128);
            $table->string('last_name', 128);
            $table->string('middle_name', 128)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('place_of_birth', 255)->nullable();
            $table->string('gender', 32)->nullable();
            $table->string('phone_number', 32)->nullable();
            $table->string('email')->nullable();
            $table->string('address_line_1')->nullable();
            $table->string('address_line_2')->nullable();
            $table->string('city', 128)->nullable();
            $table->string('region', 128)->nullable();
            $table->string('occupation', 128)->nullable();
            $table->string('employer_name', 255)->nullable();
            $table->string('status', 32)->default('active')->index();
            $table->string('kyc_status', 32)->default('pending')->index();
            $table->date('onboarded_on')->nullable();
            $table->timestamps();

            $table->unique(['agency_id', 'client_reference']);
            $table->index(['agency_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
