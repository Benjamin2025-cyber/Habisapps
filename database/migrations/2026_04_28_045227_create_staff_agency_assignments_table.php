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
        Schema::create('staff_agency_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('agency_id')->constrained('agencies')->restrictOnDelete();
            $table->string('role_at_agency', 64)->default('staff');
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->string('status', 32)->default('active')->index();
            $table->timestamps();

            $table->index(['user_id', 'status', 'starts_on']);
            $table->index(['agency_id', 'status']);
            $table->unique(['user_id', 'agency_id', 'starts_on'], 'uniq_staff_agency_start');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('staff_agency_assignments');
    }
};
