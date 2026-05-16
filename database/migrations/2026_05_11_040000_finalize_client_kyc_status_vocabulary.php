<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('clients')
            ->where('kyc_status', 'pending')
            ->update(['kyc_status' => 'draft']);

        DB::statement("ALTER TABLE clients ALTER COLUMN kyc_status SET DEFAULT 'draft'");
        DB::statement("ALTER TABLE clients ADD CONSTRAINT clients_kyc_status_allowed CHECK (kyc_status IN ('draft', 'pending_review', 'verified', 'rejected', 'suspended', 'archived'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE clients DROP CONSTRAINT IF EXISTS clients_kyc_status_allowed');
        DB::statement("ALTER TABLE clients ALTER COLUMN kyc_status SET DEFAULT 'pending'");
    }
};
