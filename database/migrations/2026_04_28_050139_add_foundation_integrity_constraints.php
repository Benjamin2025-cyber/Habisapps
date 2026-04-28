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
        Schema::table('teller_transactions', function (Blueprint $table): void {
            $table->string('review_status', 32)->default('pending')->index();
            $table->foreignId('reviewed_by_user_id')->nullable()->after('review_status')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by_user_id');
        });

        Schema::table('till_reconciliations', function (Blueprint $table): void {
            $table->string('review_status', 32)->default('pending')->index();
            $table->foreignId('reviewed_by_user_id')->nullable()->after('review_status')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by_user_id');
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('superseded_by_till_reconciliation_id')->nullable()->constrained('till_reconciliations')->nullOnDelete();
        });

        Schema::table('loan_status_transitions', function (Blueprint $table): void {
            $table->string('checker_decision', 32)->nullable()->index();
            $table->foreignId('checked_by_user_id')->nullable()->after('checker_decision')->constrained('users')->nullOnDelete();
            $table->timestamp('checked_at')->nullable()->after('checked_by_user_id');
        });

        Schema::table('journal_lines', function (Blueprint $table): void {
            $table->foreign('loan_id')->references('id')->on('loans')->restrictOnDelete();
        });

        Schema::table('teller_transactions', function (Blueprint $table): void {
            $table->foreign('loan_id')->references('id')->on('loans')->restrictOnDelete();
            $table->foreign('reversal_of_teller_transaction_id', 'teller_transactions_reversal_foreign')
                ->references('id')
                ->on('teller_transactions')
                ->restrictOnDelete();
        });

        DB::statement('ALTER TABLE batch_runs DROP CONSTRAINT uniq_batch_scope_date');
        DB::statement("CREATE UNIQUE INDEX uniq_successful_global_batch_run ON batch_runs (batch_procedure_id, business_date) WHERE agency_id IS NULL AND status = 'succeeded'");
        DB::statement("CREATE UNIQUE INDEX uniq_successful_agency_batch_run ON batch_runs (batch_procedure_id, agency_id, business_date) WHERE agency_id IS NOT NULL AND status = 'succeeded'");
        DB::statement("CREATE UNIQUE INDEX uniq_active_primary_staff_assignment ON staff_agency_assignments (user_id) WHERE is_primary = true AND status = 'active'");

        DB::statement('ALTER TABLE journal_lines ADD CONSTRAINT journal_lines_debit_non_negative CHECK (debit_minor >= 0)');
        DB::statement('ALTER TABLE journal_lines ADD CONSTRAINT journal_lines_credit_non_negative CHECK (credit_minor >= 0)');
        DB::statement('ALTER TABLE journal_lines ADD CONSTRAINT journal_lines_exactly_one_side_positive CHECK ((debit_minor > 0 AND credit_minor = 0) OR (credit_minor > 0 AND debit_minor = 0))');
        DB::statement('ALTER TABLE ledger_accounts ADD CONSTRAINT ledger_accounts_not_self_parent CHECK (parent_account_id IS NULL OR parent_account_id <> id)');
        DB::statement('ALTER TABLE denominations ADD CONSTRAINT denominations_value_positive CHECK (value_minor > 0)');
        DB::statement('ALTER TABLE teller_sessions ADD CONSTRAINT teller_sessions_opening_declaration_non_negative CHECK (opening_declaration_minor IS NULL OR opening_declaration_minor >= 0)');
        DB::statement('ALTER TABLE teller_sessions ADD CONSTRAINT teller_sessions_closing_declaration_non_negative CHECK (closing_declaration_minor IS NULL OR closing_declaration_minor >= 0)');
        DB::statement('ALTER TABLE teller_transactions ADD CONSTRAINT teller_transactions_amount_positive CHECK (amount_minor > 0)');
        DB::statement('ALTER TABLE till_reconciliation_lines ADD CONSTRAINT till_reconciliation_lines_declared_amount_non_negative CHECK (declared_amount_minor IS NULL OR declared_amount_minor >= 0)');
        DB::statement('ALTER TABLE loans ADD CONSTRAINT loans_requested_amount_positive CHECK (requested_amount_minor > 0)');
        DB::statement('ALTER TABLE loans ADD CONSTRAINT loans_approved_principal_positive CHECK (approved_principal_minor IS NULL OR approved_principal_minor > 0)');
        DB::statement('ALTER TABLE collaterals ADD CONSTRAINT collaterals_declared_value_positive CHECK (declared_value_minor IS NULL OR declared_value_minor > 0)');
        DB::statement('ALTER TABLE account_holds ADD CONSTRAINT account_holds_amount_positive CHECK (amount_minor > 0)');
        DB::statement('ALTER TABLE loan_schedule_lines ADD CONSTRAINT loan_schedule_lines_amounts_non_negative CHECK (principal_minor >= 0 AND interest_minor >= 0 AND fees_minor >= 0 AND insurance_minor >= 0 AND tax_minor >= 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE loan_schedule_lines DROP CONSTRAINT IF EXISTS loan_schedule_lines_amounts_non_negative');
        DB::statement('ALTER TABLE account_holds DROP CONSTRAINT IF EXISTS account_holds_amount_positive');
        DB::statement('ALTER TABLE collaterals DROP CONSTRAINT IF EXISTS collaterals_declared_value_positive');
        DB::statement('ALTER TABLE loans DROP CONSTRAINT IF EXISTS loans_approved_principal_positive');
        DB::statement('ALTER TABLE loans DROP CONSTRAINT IF EXISTS loans_requested_amount_positive');
        DB::statement('ALTER TABLE till_reconciliation_lines DROP CONSTRAINT IF EXISTS till_reconciliation_lines_declared_amount_non_negative');
        DB::statement('ALTER TABLE teller_transactions DROP CONSTRAINT IF EXISTS teller_transactions_amount_positive');
        DB::statement('ALTER TABLE teller_sessions DROP CONSTRAINT IF EXISTS teller_sessions_closing_declaration_non_negative');
        DB::statement('ALTER TABLE teller_sessions DROP CONSTRAINT IF EXISTS teller_sessions_opening_declaration_non_negative');
        DB::statement('ALTER TABLE denominations DROP CONSTRAINT IF EXISTS denominations_value_positive');
        DB::statement('ALTER TABLE ledger_accounts DROP CONSTRAINT IF EXISTS ledger_accounts_not_self_parent');
        DB::statement('ALTER TABLE journal_lines DROP CONSTRAINT IF EXISTS journal_lines_exactly_one_side_positive');
        DB::statement('ALTER TABLE journal_lines DROP CONSTRAINT IF EXISTS journal_lines_credit_non_negative');
        DB::statement('ALTER TABLE journal_lines DROP CONSTRAINT IF EXISTS journal_lines_debit_non_negative');

        DB::statement('DROP INDEX IF EXISTS uniq_active_primary_staff_assignment');
        DB::statement('DROP INDEX IF EXISTS uniq_successful_agency_batch_run');
        DB::statement('DROP INDEX IF EXISTS uniq_successful_global_batch_run');
        DB::statement('ALTER TABLE batch_runs ADD CONSTRAINT uniq_batch_scope_date UNIQUE (batch_procedure_id, agency_id, business_date)');

        Schema::table('teller_transactions', function (Blueprint $table): void {
            $table->dropForeign('teller_transactions_reversal_foreign');
            $table->dropForeign(['loan_id']);
        });

        Schema::table('journal_lines', function (Blueprint $table): void {
            $table->dropForeign(['loan_id']);
        });

        Schema::table('loan_status_transitions', function (Blueprint $table): void {
            $table->dropForeign(['checked_by_user_id']);
            $table->dropColumn(['checker_decision', 'checked_by_user_id', 'checked_at']);
        });

        Schema::table('till_reconciliations', function (Blueprint $table): void {
            $table->dropForeign(['reviewed_by_user_id']);
            $table->dropForeign(['superseded_by_till_reconciliation_id']);
            $table->dropColumn([
                'review_status',
                'reviewed_by_user_id',
                'reviewed_at',
                'revision',
                'superseded_by_till_reconciliation_id',
            ]);
        });

        Schema::table('teller_transactions', function (Blueprint $table): void {
            $table->dropForeign(['reviewed_by_user_id']);
            $table->dropColumn(['review_status', 'reviewed_by_user_id', 'reviewed_at']);
        });
    }
};
