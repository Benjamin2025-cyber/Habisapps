<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('agency_id')->nullable()->after('job_title')->constrained('agencies')->nullOnDelete();
            $table->index(['agency_id', 'status']);
        });

        DB::statement('UPDATE users SET agency_id = agencies.id FROM agencies WHERE users.agency_code = agencies.code AND users.agency_id IS NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['agency_id', 'status']);
            $table->dropForeign(['agency_id']);
            $table->dropColumn('agency_id');
        });
    }
};
