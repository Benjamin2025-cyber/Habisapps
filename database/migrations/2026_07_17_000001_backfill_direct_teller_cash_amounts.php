<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('teller_transactions')
            ->whereIn('operation_code', [
                'loan_cash_disbursement',
                'loan_setup_charge_collection',
                'insurance_premium_collection',
            ])
            ->where('cash_amount_minor', 0)
            ->where('cheque_amount_minor', 0)
            ->where('transfer_amount_minor', 0)
            ->update([
                'payment_method' => 'cash',
                'cash_amount_minor' => DB::raw('amount_minor'),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Historical tender data cannot safely distinguish an original zero
        // from a value repaired by this migration, so this data fix is not
        // reversed.
    }
};
