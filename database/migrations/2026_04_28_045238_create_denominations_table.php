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
        Schema::create('denominations', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('code', 32);
            $table->string('label', 64);
            $table->bigInteger('value_minor');
            $table->string('currency', 3);
            $table->string('type', 32)->default('banknote');
            $table->string('status', 32)->default('active')->index();
            $table->timestamps();

            $table->unique(['currency', 'code']);
            $table->unique(['currency', 'value_minor']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('denominations');
    }
};
