<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE loan_disbursements DROP CONSTRAINT IF EXISTS loan_disbursements_channel_allowed');
        DB::statement("ALTER TABLE loan_disbursements ADD CONSTRAINT loan_disbursements_channel_allowed CHECK (disbursement_channel IN ('transfer_account', 'cash'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE loan_disbursements DROP CONSTRAINT IF EXISTS loan_disbursements_channel_allowed');
        DB::statement("ALTER TABLE loan_disbursements ADD CONSTRAINT loan_disbursements_channel_allowed CHECK (disbursement_channel IN ('transfer_account'))");
    }
};
