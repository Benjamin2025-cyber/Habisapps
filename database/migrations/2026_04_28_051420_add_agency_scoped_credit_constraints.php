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
        Schema::table('journal_lines', function (Blueprint $table): void {
            $table->foreignId('agency_id')->after('journal_entry_id')->constrained('agencies')->restrictOnDelete();
        });

        Schema::table('collaterals', function (Blueprint $table): void {
            $table->foreignId('agency_id')->after('public_id')->constrained('agencies')->restrictOnDelete();
        });

        Schema::table('client_guarantors', function (Blueprint $table): void {
            $table->foreignId('agency_id')->after('public_id')->constrained('agencies')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE journal_entries ALTER COLUMN agency_id SET NOT NULL');
        DB::statement('ALTER TABLE journal_entries ADD CONSTRAINT journal_entries_id_agency_unique UNIQUE (id, agency_id)');
        DB::statement('ALTER TABLE journal_lines ADD CONSTRAINT journal_lines_entry_agency_foreign FOREIGN KEY (journal_entry_id, agency_id) REFERENCES journal_entries (id, agency_id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE journal_lines ADD CONSTRAINT journal_lines_ledger_agency_foreign FOREIGN KEY (ledger_account_id, agency_id) REFERENCES ledger_accounts (id, agency_id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE journal_lines ADD CONSTRAINT journal_lines_customer_agency_foreign FOREIGN KEY (customer_account_id, agency_id) REFERENCES customer_accounts (id, agency_id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE journal_lines ADD CONSTRAINT journal_lines_loan_agency_foreign FOREIGN KEY (loan_id, agency_id) REFERENCES loans (id, agency_id) ON DELETE RESTRICT');

        DB::statement('ALTER TABLE collaterals ADD CONSTRAINT collaterals_client_agency_foreign FOREIGN KEY (client_id, agency_id) REFERENCES clients (id, agency_id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE collaterals ADD CONSTRAINT collaterals_loan_agency_foreign FOREIGN KEY (loan_id, agency_id) REFERENCES loans (id, agency_id) ON DELETE RESTRICT');

        DB::statement('ALTER TABLE client_guarantors ADD CONSTRAINT client_guarantors_client_agency_foreign FOREIGN KEY (client_id, agency_id) REFERENCES clients (id, agency_id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE client_guarantors ADD CONSTRAINT client_guarantors_guarantor_agency_foreign FOREIGN KEY (guarantor_client_id, agency_id) REFERENCES clients (id, agency_id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE client_guarantors ADD CONSTRAINT client_guarantors_not_self CHECK (guarantor_client_id IS NULL OR guarantor_client_id <> client_id)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE client_guarantors DROP CONSTRAINT IF EXISTS client_guarantors_not_self');
        DB::statement('ALTER TABLE client_guarantors DROP CONSTRAINT IF EXISTS client_guarantors_guarantor_agency_foreign');
        DB::statement('ALTER TABLE client_guarantors DROP CONSTRAINT IF EXISTS client_guarantors_client_agency_foreign');

        DB::statement('ALTER TABLE collaterals DROP CONSTRAINT IF EXISTS collaterals_loan_agency_foreign');
        DB::statement('ALTER TABLE collaterals DROP CONSTRAINT IF EXISTS collaterals_client_agency_foreign');

        DB::statement('ALTER TABLE journal_lines DROP CONSTRAINT IF EXISTS journal_lines_loan_agency_foreign');
        DB::statement('ALTER TABLE journal_lines DROP CONSTRAINT IF EXISTS journal_lines_customer_agency_foreign');
        DB::statement('ALTER TABLE journal_lines DROP CONSTRAINT IF EXISTS journal_lines_ledger_agency_foreign');
        DB::statement('ALTER TABLE journal_lines DROP CONSTRAINT IF EXISTS journal_lines_entry_agency_foreign');
        DB::statement('ALTER TABLE journal_entries DROP CONSTRAINT IF EXISTS journal_entries_id_agency_unique');

        Schema::table('client_guarantors', function (Blueprint $table): void {
            $table->dropForeign(['agency_id']);
            $table->dropColumn('agency_id');
        });

        Schema::table('collaterals', function (Blueprint $table): void {
            $table->dropForeign(['agency_id']);
            $table->dropColumn('agency_id');
        });

        Schema::table('journal_lines', function (Blueprint $table): void {
            $table->dropForeign(['agency_id']);
            $table->dropColumn('agency_id');
        });

        DB::statement('ALTER TABLE journal_entries ALTER COLUMN agency_id DROP NOT NULL');
    }
};
