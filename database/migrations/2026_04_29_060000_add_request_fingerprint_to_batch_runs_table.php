<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('batch_runs', function (Blueprint $table): void {
            $table->string('request_fingerprint', 64)->nullable()->after('idempotency_key');
        });
    }

    public function down(): void
    {
        Schema::table('batch_runs', function (Blueprint $table): void {
            $table->dropColumn('request_fingerprint');
        });
    }
};
