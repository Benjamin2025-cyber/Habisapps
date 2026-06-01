<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teller_sessions', function (Blueprint $table): void {
            $table->foreignId('accounting_day_id')->nullable()->after('agency_id')->constrained('accounting_days')->nullOnDelete();
            $table->index('accounting_day_id');
        });

        Schema::table('teller_transactions', function (Blueprint $table): void {
            $table->foreignId('accounting_day_id')->nullable()->after('teller_session_id')->constrained('accounting_days')->nullOnDelete();
            $table->index('accounting_day_id');
        });

        if (Schema::hasTable('till_reconciliations')) {
            Schema::table('till_reconciliations', function (Blueprint $table): void {
                $table->foreignId('accounting_day_id')->nullable()->constrained('accounting_days')->nullOnDelete();
                $table->index('accounting_day_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('till_reconciliations')) {
            Schema::table('till_reconciliations', function (Blueprint $table): void {
                $table->dropForeign(['accounting_day_id']);
                $table->dropIndex(['accounting_day_id']);
                $table->dropColumn('accounting_day_id');
            });
        }

        Schema::table('teller_transactions', function (Blueprint $table): void {
            $table->dropForeign(['accounting_day_id']);
            $table->dropIndex(['accounting_day_id']);
            $table->dropColumn('accounting_day_id');
        });

        Schema::table('teller_sessions', function (Blueprint $table): void {
            $table->dropForeign(['accounting_day_id']);
            $table->dropIndex(['accounting_day_id']);
            $table->dropColumn('accounting_day_id');
        });
    }
};
