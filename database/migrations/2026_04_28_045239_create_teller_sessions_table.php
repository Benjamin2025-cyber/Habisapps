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
        Schema::create('teller_sessions', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('till_id')->constrained('tills')->restrictOnDelete();
            $table->foreignId('agency_id')->constrained('agencies')->restrictOnDelete();
            $table->foreignId('teller_user_id')->constrained('users')->restrictOnDelete();
            $table->date('business_date')->index();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->bigInteger('opening_declaration_minor')->nullable();
            $table->bigInteger('closing_declaration_minor')->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('status', 32)->default('open')->index();
            $table->timestamps();

            $table->index(['teller_user_id', 'status']);
            $table->index(['till_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('teller_sessions');
    }
};
