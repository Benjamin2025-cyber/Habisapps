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
        Schema::table('documents', function (Blueprint $table): void {
            $table->foreignId('agency_id')->after('public_id')->constrained('agencies')->restrictOnDelete();
        });

        Schema::table('client_identity_documents', function (Blueprint $table): void {
            $table->foreignId('agency_id')->after('public_id')->constrained('agencies')->restrictOnDelete();
        });

        Schema::table('client_proxies', function (Blueprint $table): void {
            $table->foreignId('agency_id')->after('public_id')->constrained('agencies')->restrictOnDelete();
        });

        Schema::table('loan_status_transitions', function (Blueprint $table): void {
            $table->foreignId('agency_id')->after('public_id')->constrained('agencies')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE ledger_accounts ALTER COLUMN agency_id SET NOT NULL');
        DB::statement('ALTER TABLE ledger_accounts DROP CONSTRAINT IF EXISTS ledger_accounts_agency_id_code_unique');
        DB::statement('DROP INDEX IF EXISTS uniq_global_ledger_account_code');
        DB::statement('DROP INDEX IF EXISTS uniq_agency_ledger_account_code');
        DB::statement('ALTER TABLE ledger_accounts ADD CONSTRAINT ledger_accounts_agency_code_unique UNIQUE (agency_id, code)');
        DB::statement('ALTER TABLE customer_accounts ADD CONSTRAINT customer_accounts_ledger_agency_foreign FOREIGN KEY (ledger_account_id, agency_id) REFERENCES ledger_accounts (id, agency_id) ON DELETE RESTRICT');

        DB::statement('ALTER TABLE documents ALTER COLUMN agency_id SET NOT NULL');
        DB::statement('ALTER TABLE documents DROP CONSTRAINT documents_disk_path_unique');
        DB::statement('ALTER TABLE documents ADD CONSTRAINT documents_id_agency_unique UNIQUE (id, agency_id)');
        DB::statement('CREATE UNIQUE INDEX documents_agency_disk_path_unique ON documents (agency_id, disk, path)');

        DB::statement('ALTER TABLE client_identity_documents ADD CONSTRAINT client_identity_documents_client_agency_foreign FOREIGN KEY (client_id, agency_id) REFERENCES clients (id, agency_id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE client_identity_documents ADD CONSTRAINT client_identity_documents_document_agency_foreign FOREIGN KEY (document_id, agency_id) REFERENCES documents (id, agency_id) ON DELETE RESTRICT');

        DB::statement('ALTER TABLE client_proxies ADD CONSTRAINT client_proxies_client_agency_foreign FOREIGN KEY (client_id, agency_id) REFERENCES clients (id, agency_id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE client_proxies ADD CONSTRAINT client_proxies_document_agency_foreign FOREIGN KEY (document_id, agency_id) REFERENCES documents (id, agency_id) ON DELETE RESTRICT');

        DB::statement('ALTER TABLE collaterals ADD CONSTRAINT collaterals_document_agency_foreign FOREIGN KEY (document_id, agency_id) REFERENCES documents (id, agency_id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE client_guarantors ADD CONSTRAINT client_guarantors_document_agency_foreign FOREIGN KEY (document_id, agency_id) REFERENCES documents (id, agency_id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE loan_status_transitions ADD CONSTRAINT loan_status_transitions_loan_agency_foreign FOREIGN KEY (loan_id, agency_id) REFERENCES loans (id, agency_id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE loan_status_transitions ADD CONSTRAINT loan_status_transitions_document_agency_foreign FOREIGN KEY (document_id, agency_id) REFERENCES documents (id, agency_id) ON DELETE RESTRICT');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE loan_status_transitions DROP CONSTRAINT IF EXISTS loan_status_transitions_document_agency_foreign');
        DB::statement('ALTER TABLE loan_status_transitions DROP CONSTRAINT IF EXISTS loan_status_transitions_loan_agency_foreign');
        DB::statement('ALTER TABLE client_guarantors DROP CONSTRAINT IF EXISTS client_guarantors_document_agency_foreign');
        DB::statement('ALTER TABLE collaterals DROP CONSTRAINT IF EXISTS collaterals_document_agency_foreign');
        DB::statement('ALTER TABLE client_proxies DROP CONSTRAINT IF EXISTS client_proxies_document_agency_foreign');
        DB::statement('ALTER TABLE client_proxies DROP CONSTRAINT IF EXISTS client_proxies_client_agency_foreign');
        DB::statement('ALTER TABLE client_identity_documents DROP CONSTRAINT IF EXISTS client_identity_documents_document_agency_foreign');
        DB::statement('ALTER TABLE client_identity_documents DROP CONSTRAINT IF EXISTS client_identity_documents_client_agency_foreign');
        DB::statement('DROP INDEX IF EXISTS documents_agency_disk_path_unique');
        DB::statement('ALTER TABLE documents DROP CONSTRAINT IF EXISTS documents_id_agency_unique');
        DB::statement('ALTER TABLE documents DROP CONSTRAINT IF EXISTS documents_disk_path_unique');
        DB::statement('ALTER TABLE ledger_accounts DROP CONSTRAINT IF EXISTS ledger_accounts_agency_code_unique');
        DB::statement('ALTER TABLE customer_accounts DROP CONSTRAINT IF EXISTS customer_accounts_ledger_agency_foreign');
        DB::statement('CREATE UNIQUE INDEX uniq_global_ledger_account_code ON ledger_accounts (code) WHERE agency_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX uniq_agency_ledger_account_code ON ledger_accounts (agency_id, code) WHERE agency_id IS NOT NULL');
        DB::statement('ALTER TABLE ledger_accounts ALTER COLUMN agency_id DROP NOT NULL');

        Schema::table('loan_status_transitions', function (Blueprint $table): void {
            $table->dropForeign(['agency_id']);
            $table->dropColumn('agency_id');
        });

        Schema::table('client_proxies', function (Blueprint $table): void {
            $table->dropForeign(['agency_id']);
            $table->dropColumn('agency_id');
        });

        Schema::table('client_identity_documents', function (Blueprint $table): void {
            $table->dropForeign(['agency_id']);
            $table->dropColumn('agency_id');
        });

        Schema::table('documents', function (Blueprint $table): void {
            $table->dropForeign(['agency_id']);
            $table->dropColumn('agency_id');
        });

        DB::statement('CREATE UNIQUE INDEX documents_disk_path_unique ON documents (disk, path)');
    }
};
