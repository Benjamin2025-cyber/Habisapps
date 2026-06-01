<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('batch_runs', function (Blueprint $table): void {
            $table->foreignId('accounting_day_id')->nullable()->after('agency_id')->constrained('accounting_days')->nullOnDelete();
            $table->index('accounting_day_id');
        });
    }

    public function down(): void
    {
        Schema::table('batch_runs', function (Blueprint $table): void {
            $table->dropForeign(['accounting_day_id']);
            $table->dropIndex(['accounting_day_id']);
            $table->dropColumn('accounting_day_id');
        });
    }
};
