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
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        Schema::table('teller_transactions', function (Blueprint $table): void {
            $table->foreignId('agency_id')->after('teller_session_id')->constrained('agencies')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE clients ADD CONSTRAINT clients_id_agency_unique UNIQUE (id, agency_id)');
        DB::statement('ALTER TABLE tills ADD CONSTRAINT tills_id_agency_unique UNIQUE (id, agency_id)');
        DB::statement('ALTER TABLE teller_sessions ADD CONSTRAINT teller_sessions_id_agency_unique UNIQUE (id, agency_id)');
        DB::statement('ALTER TABLE ledger_accounts ADD CONSTRAINT ledger_accounts_id_agency_unique UNIQUE (id, agency_id)');
        DB::statement('ALTER TABLE customer_accounts ADD CONSTRAINT customer_accounts_id_agency_unique UNIQUE (id, agency_id)');
        DB::statement('ALTER TABLE loans ADD CONSTRAINT loans_id_agency_unique UNIQUE (id, agency_id)');

        DB::statement('ALTER TABLE customer_accounts ADD CONSTRAINT customer_accounts_client_agency_foreign FOREIGN KEY (client_id, agency_id) REFERENCES clients (id, agency_id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE loans ADD CONSTRAINT loans_client_agency_foreign FOREIGN KEY (client_id, agency_id) REFERENCES clients (id, agency_id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE teller_sessions ADD CONSTRAINT teller_sessions_till_agency_foreign FOREIGN KEY (till_id, agency_id) REFERENCES tills (id, agency_id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE teller_transactions ADD CONSTRAINT teller_transactions_session_agency_foreign FOREIGN KEY (teller_session_id, agency_id) REFERENCES teller_sessions (id, agency_id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE teller_transactions ADD CONSTRAINT teller_transactions_client_agency_foreign FOREIGN KEY (client_id, agency_id) REFERENCES clients (id, agency_id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE teller_transactions ADD CONSTRAINT teller_transactions_account_agency_foreign FOREIGN KEY (customer_account_id, agency_id) REFERENCES customer_accounts (id, agency_id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE teller_transactions ADD CONSTRAINT teller_transactions_loan_agency_foreign FOREIGN KEY (loan_id, agency_id) REFERENCES loans (id, agency_id) ON DELETE RESTRICT');

        DB::statement('ALTER TABLE ledger_accounts DROP CONSTRAINT ledger_accounts_agency_id_code_unique');
        DB::statement('CREATE UNIQUE INDEX uniq_global_ledger_account_code ON ledger_accounts (code) WHERE agency_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX uniq_agency_ledger_account_code ON ledger_accounts (agency_id, code) WHERE agency_id IS NOT NULL');

        DB::statement('DROP INDEX IF EXISTS uniq_active_primary_staff_assignment');
        DB::statement('ALTER TABLE staff_agency_assignments ADD CONSTRAINT staff_assignment_dates_valid CHECK (ends_on IS NULL OR ends_on >= starts_on)');
        DB::statement("ALTER TABLE staff_agency_assignments ADD CONSTRAINT staff_primary_assignment_no_overlap EXCLUDE USING gist (user_id WITH =, daterange(starts_on, COALESCE(ends_on, 'infinity'::date), '[]') WITH &&) WHERE (is_primary = true AND status = 'active')");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE staff_agency_assignments DROP CONSTRAINT IF EXISTS staff_primary_assignment_no_overlap');
        DB::statement('ALTER TABLE staff_agency_assignments DROP CONSTRAINT IF EXISTS staff_assignment_dates_valid');
        DB::statement("CREATE UNIQUE INDEX uniq_active_primary_staff_assignment ON staff_agency_assignments (user_id) WHERE is_primary = true AND status = 'active'");

        DB::statement('DROP INDEX IF EXISTS uniq_agency_ledger_account_code');
        DB::statement('DROP INDEX IF EXISTS uniq_global_ledger_account_code');
        DB::statement('ALTER TABLE ledger_accounts ADD CONSTRAINT ledger_accounts_agency_id_code_unique UNIQUE (agency_id, code)');
        DB::statement('ALTER TABLE ledger_accounts DROP CONSTRAINT IF EXISTS ledger_accounts_id_agency_unique');

        DB::statement('ALTER TABLE teller_transactions DROP CONSTRAINT IF EXISTS teller_transactions_loan_agency_foreign');
        DB::statement('ALTER TABLE teller_transactions DROP CONSTRAINT IF EXISTS teller_transactions_account_agency_foreign');
        DB::statement('ALTER TABLE teller_transactions DROP CONSTRAINT IF EXISTS teller_transactions_client_agency_foreign');
        DB::statement('ALTER TABLE teller_transactions DROP CONSTRAINT IF EXISTS teller_transactions_session_agency_foreign');
        DB::statement('ALTER TABLE teller_sessions DROP CONSTRAINT IF EXISTS teller_sessions_till_agency_foreign');
        DB::statement('ALTER TABLE loans DROP CONSTRAINT IF EXISTS loans_client_agency_foreign');
        DB::statement('ALTER TABLE customer_accounts DROP CONSTRAINT IF EXISTS customer_accounts_client_agency_foreign');

        DB::statement('ALTER TABLE loans DROP CONSTRAINT IF EXISTS loans_id_agency_unique');
        DB::statement('ALTER TABLE customer_accounts DROP CONSTRAINT IF EXISTS customer_accounts_id_agency_unique');
        DB::statement('ALTER TABLE teller_sessions DROP CONSTRAINT IF EXISTS teller_sessions_id_agency_unique');
        DB::statement('ALTER TABLE tills DROP CONSTRAINT IF EXISTS tills_id_agency_unique');
        DB::statement('ALTER TABLE clients DROP CONSTRAINT IF EXISTS clients_id_agency_unique');

        Schema::table('teller_transactions', function (Blueprint $table): void {
            $table->dropForeign(['agency_id']);
            $table->dropColumn('agency_id');
        });
    }
};
