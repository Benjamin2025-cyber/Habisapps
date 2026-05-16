<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->foreignId('kyc_submitted_by_user_id')->nullable()->after('kyc_submitted_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropForeign(['kyc_submitted_by_user_id']);
            $table->dropColumn('kyc_submitted_by_user_id');
        });
    }
};
