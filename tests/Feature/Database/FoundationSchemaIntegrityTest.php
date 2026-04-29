<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class FoundationSchemaIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_journal_line_requires_exactly_one_positive_side(): void
    {
        $agencyId = $this->createAgency('JL01');
        $ledgerAccountId = $this->createLedgerAccount($agencyId);
        $journalEntryId = $this->createJournalEntry($agencyId);

        $this->expectException(QueryException::class);

        DB::table('journal_lines')->insert([
            'journal_entry_id' => $journalEntryId,
            'agency_id' => $agencyId,
            'ledger_account_id' => $ledgerAccountId,
            'debit_minor' => 1000,
            'credit_minor' => 1000,
            'currency' => 'XAF',
        ]);
    }

    public function test_only_one_active_primary_staff_assignment_is_allowed(): void
    {
        $user = User::factory()->createOne();
        $firstAgencyId = $this->createAgency('A001');
        $secondAgencyId = $this->createAgency('A002');

        DB::table('staff_agency_assignments')->insert([
            'public_id' => (string) Str::ulid(),
            'user_id' => $user->id,
            'agency_id' => $firstAgencyId,
            'role_at_agency' => 'cashier',
            'starts_on' => '2026-01-01',
            'is_primary' => true,
            'status' => 'active',
        ]);

        $this->expectException(QueryException::class);

        DB::table('staff_agency_assignments')->insert([
            'public_id' => (string) Str::ulid(),
            'user_id' => $user->id,
            'agency_id' => $secondAgencyId,
            'role_at_agency' => 'cashier',
            'starts_on' => '2026-01-02',
            'is_primary' => true,
            'status' => 'active',
        ]);
    }

    public function test_duplicate_successful_global_batch_runs_are_rejected(): void
    {
        $procedureId = $this->createBatchProcedure();

        DB::table('batch_runs')->insert([
            'public_id' => (string) Str::ulid(),
            'batch_procedure_id' => $procedureId,
            'business_date' => '2026-04-28',
            'status' => 'succeeded',
        ]);

        $this->expectException(QueryException::class);

        DB::table('batch_runs')->insert([
            'public_id' => (string) Str::ulid(),
            'batch_procedure_id' => $procedureId,
            'business_date' => '2026-04-28',
            'status' => 'succeeded',
        ]);
    }

    public function test_duplicate_successful_agency_batch_runs_are_rejected_but_pending_retries_are_allowed(): void
    {
        $procedureId = $this->createBatchProcedure();
        $agencyId = $this->createAgency();

        DB::table('batch_runs')->insert([
            'public_id' => (string) Str::ulid(),
            'batch_procedure_id' => $procedureId,
            'agency_id' => $agencyId,
            'business_date' => '2026-04-28',
            'status' => 'pending',
        ]);

        DB::table('batch_runs')->insert([
            'public_id' => (string) Str::ulid(),
            'batch_procedure_id' => $procedureId,
            'agency_id' => $agencyId,
            'business_date' => '2026-04-28',
            'status' => 'pending',
        ]);

        DB::table('batch_runs')->insert([
            'public_id' => (string) Str::ulid(),
            'batch_procedure_id' => $procedureId,
            'agency_id' => $agencyId,
            'business_date' => '2026-04-28',
            'status' => 'succeeded',
        ]);

        $this->expectException(QueryException::class);

        DB::table('batch_runs')->insert([
            'public_id' => (string) Str::ulid(),
            'batch_procedure_id' => $procedureId,
            'agency_id' => $agencyId,
            'business_date' => '2026-04-28',
            'status' => 'succeeded',
        ]);
    }

    public function test_journal_line_loan_reference_must_exist(): void
    {
        $agencyId = $this->createAgency('JL02');
        $ledgerAccountId = $this->createLedgerAccount($agencyId);
        $journalEntryId = $this->createJournalEntry($agencyId);

        $this->expectException(QueryException::class);

        DB::table('journal_lines')->insert([
            'journal_entry_id' => $journalEntryId,
            'agency_id' => $agencyId,
            'ledger_account_id' => $ledgerAccountId,
            'loan_id' => 999999,
            'debit_minor' => 1000,
            'credit_minor' => 0,
            'currency' => 'XAF',
        ]);
    }

    public function test_journal_line_agency_must_match_entry_and_ledger_account(): void
    {
        $entryAgencyId = $this->createAgency('JL03');
        $otherAgencyId = $this->createAgency('JL04');
        $journalEntryId = $this->createJournalEntry($entryAgencyId);
        $ledgerAccountId = $this->createLedgerAccount($otherAgencyId);

        $this->expectException(QueryException::class);

        DB::table('journal_lines')->insert([
            'journal_entry_id' => $journalEntryId,
            'agency_id' => $entryAgencyId,
            'ledger_account_id' => $ledgerAccountId,
            'debit_minor' => 1000,
            'credit_minor' => 0,
            'currency' => 'XAF',
        ]);
    }

    public function test_money_amounts_reject_negative_or_zero_values_where_required(): void
    {
        $this->expectException(QueryException::class);

        DB::table('denominations')->insert([
            'public_id' => (string) Str::ulid(),
            'code' => 'BAD',
            'label' => 'Invalid',
            'value_minor' => 0,
            'currency' => 'XAF',
            'type' => 'banknote',
        ]);
    }

    public function test_ledger_account_cannot_parent_itself(): void
    {
        $this->expectException(QueryException::class);

        DB::table('ledger_accounts')->insert([
            'id' => 9001,
            'public_id' => (string) Str::ulid(),
            'code' => 'SELF',
            'name' => 'Self Parent',
            'account_class' => 'asset',
            'parent_account_id' => 9001,
            'normal_balance_side' => 'debit',
        ]);
    }

    public function test_internal_teller_transaction_without_client_account_or_loan_is_allowed(): void
    {
        $agencyId = $this->createAgency('TT01');
        $sessionId = $this->createTellerSession($agencyId);

        DB::table('teller_transactions')->insert([
            'public_id' => (string) Str::ulid(),
            'teller_session_id' => $sessionId,
            'agency_id' => $agencyId,
            'transaction_type' => 'cash_movement',
            'amount_minor' => 5000,
            'currency' => 'XAF',
            'status' => 'posted',
            'reference' => 'TX-'.Str::ulid(),
        ]);

        $this->assertDatabaseCount('teller_transactions', 1);
    }

    public function test_ledger_account_codes_are_unique_within_an_agency(): void
    {
        $agencyId = $this->createAgency('LG01');
        $this->createLedgerAccount($agencyId, '1000');

        $this->expectException(QueryException::class);

        $this->createLedgerAccount($agencyId, '1000');
    }

    public function test_customer_account_agency_must_match_client_agency(): void
    {
        $clientAgencyId = $this->createAgency('CA01');
        $otherAgencyId = $this->createAgency('CA02');
        $clientId = $this->createClient($clientAgencyId);

        $this->expectException(QueryException::class);

        DB::table('customer_accounts')->insert([
            'public_id' => (string) Str::ulid(),
            'client_id' => $clientId,
            'agency_id' => $otherAgencyId,
            'account_number' => 'ACC-'.Str::ulid(),
            'opened_on' => '2026-04-28',
            'status' => 'active',
        ]);
    }

    public function test_customer_account_ledger_account_must_match_agency(): void
    {
        $customerAgencyId = $this->createAgency('CA03');
        $otherAgencyId = $this->createAgency('CA04');
        $clientId = $this->createClient($customerAgencyId);
        $otherLedgerAccountId = $this->createLedgerAccount($otherAgencyId);

        $this->expectException(QueryException::class);

        DB::table('customer_accounts')->insert([
            'public_id' => (string) Str::ulid(),
            'client_id' => $clientId,
            'agency_id' => $customerAgencyId,
            'ledger_account_id' => $otherLedgerAccountId,
            'account_number' => 'ACC-'.Str::ulid(),
            'opened_on' => '2026-04-28',
            'status' => 'active',
        ]);
    }

    public function test_loan_agency_must_match_client_agency(): void
    {
        $clientAgencyId = $this->createAgency('LA01');
        $otherAgencyId = $this->createAgency('LA02');
        $clientId = $this->createClient($clientAgencyId);
        $productId = $this->createLoanProduct();

        $this->expectException(QueryException::class);

        DB::table('loans')->insert([
            'public_id' => (string) Str::ulid(),
            'client_id' => $clientId,
            'agency_id' => $otherAgencyId,
            'loan_product_id' => $productId,
            'loan_number' => 'LN-'.Str::ulid(),
            'requested_amount_minor' => 100000,
            'currency' => 'XAF',
            'applied_on' => '2026-04-28',
            'status' => 'application',
        ]);
    }

    public function test_collateral_agency_must_match_client_and_loan_agency(): void
    {
        $agencyA = $this->createAgency('CO01');
        $agencyB = $this->createAgency('CO02');
        $clientId = $this->createClient($agencyA);
        $loanId = $this->createLoan($agencyA);

        $this->expectException(QueryException::class);

        DB::table('collaterals')->insert([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agencyB,
            'client_id' => $clientId,
            'loan_id' => $loanId,
            'collateral_type' => 'vehicle',
            'status' => 'active',
        ]);
    }

    public function test_document_attachments_cannot_cross_agencies(): void
    {
        $agencyA = $this->createAgency('DO01');
        $agencyB = $this->createAgency('DO02');
        $clientId = $this->createClient($agencyA);
        $documentId = $this->createDocument($agencyA);

        $this->expectException(QueryException::class);

        DB::table('client_identity_documents')->insert([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agencyB,
            'client_id' => $clientId,
            'document_type' => 'national_id',
            'document_number' => 'ID-'.Str::ulid(),
            'verification_status' => 'pending',
            'document_id' => $documentId,
            'status' => 'active',
        ]);
    }

    public function test_client_guarantor_cannot_be_self_and_must_stay_in_same_agency(): void
    {
        $agencyA = $this->createAgency('GU01');
        $agencyB = $this->createAgency('GU02');
        $clientId = $this->createClient($agencyA);
        $guarantorId = $this->createClient($agencyA);

        DB::table('client_guarantors')->insert([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agencyA,
            'client_id' => $clientId,
            'guarantor_client_id' => $guarantorId,
            'relationship_type' => 'spouse',
            'status' => 'active',
            'verification_status' => 'pending',
        ]);

        $this->expectException(QueryException::class);

        DB::table('client_guarantors')->insert([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agencyB,
            'client_id' => $clientId,
            'guarantor_client_id' => $clientId,
            'relationship_type' => 'self',
            'status' => 'active',
            'verification_status' => 'pending',
        ]);
    }

    public function test_teller_transaction_agency_must_match_session_and_client(): void
    {
        $sessionAgencyId = $this->createAgency('TA01');
        $clientAgencyId = $this->createAgency('TA02');
        $sessionId = $this->createTellerSession($sessionAgencyId);
        $clientId = $this->createClient($clientAgencyId);

        $this->expectException(QueryException::class);

        DB::table('teller_transactions')->insert([
            'public_id' => (string) Str::ulid(),
            'teller_session_id' => $sessionId,
            'agency_id' => $sessionAgencyId,
            'transaction_type' => 'deposit',
            'client_id' => $clientId,
            'amount_minor' => 1000,
            'currency' => 'XAF',
            'status' => 'posted',
            'reference' => 'TX-'.Str::ulid(),
        ]);
    }

    public function test_primary_staff_assignment_date_ranges_cannot_overlap_but_can_follow_each_other(): void
    {
        $user = User::factory()->createOne();
        $agencyId = $this->createAgency('SA01');

        DB::table('staff_agency_assignments')->insert([
            'public_id' => (string) Str::ulid(),
            'user_id' => $user->id,
            'agency_id' => $agencyId,
            'role_at_agency' => 'manager',
            'starts_on' => '2026-01-01',
            'ends_on' => '2026-01-31',
            'is_primary' => true,
            'status' => 'active',
        ]);

        DB::table('staff_agency_assignments')->insert([
            'public_id' => (string) Str::ulid(),
            'user_id' => $user->id,
            'agency_id' => $agencyId,
            'role_at_agency' => 'manager',
            'starts_on' => '2026-02-01',
            'ends_on' => '2026-02-28',
            'is_primary' => true,
            'status' => 'active',
        ]);

        $this->expectException(QueryException::class);

        DB::table('staff_agency_assignments')->insert([
            'public_id' => (string) Str::ulid(),
            'user_id' => $user->id,
            'agency_id' => $agencyId,
            'role_at_agency' => 'manager',
            'starts_on' => '2026-02-15',
            'ends_on' => '2026-03-15',
            'is_primary' => true,
            'status' => 'active',
        ]);
    }

    public function test_agency_deletion_is_restricted_while_staff_membership_exists(): void
    {
        $agencyId = $this->createAgency('SR01');
        User::factory()->createOne([
            'agency_id' => $agencyId,
        ]);

        $this->expectException(QueryException::class);

        DB::table('agencies')->where('id', $agencyId)->delete();
    }

    private function createAgency(string $code = 'AG001'): int
    {
        return DB::table('agencies')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'code' => $code,
            'name' => 'Agency '.$code,
            'status' => 'active',
        ]);
    }

    private function createBatchProcedure(): int
    {
        return DB::table('batch_procedures')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'code' => 'END_OF_DAY',
            'name' => 'End Of Day',
            'status' => 'active',
        ]);
    }

    private function createClient(int $agencyId): int
    {
        return DB::table('clients')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agencyId,
            'client_reference' => 'CL-'.Str::ulid(),
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'status' => 'active',
            'kyc_status' => 'pending',
        ]);
    }

    private function createLoanProduct(): int
    {
        return DB::table('loan_products')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'code' => 'LP-'.Str::ulid(),
            'name' => 'Standard Loan',
            'status' => 'active',
        ]);
    }

    private function createLoan(int $agencyId): int
    {
        $clientId = $this->createClient($agencyId);
        $productId = $this->createLoanProduct();

        return DB::table('loans')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'client_id' => $clientId,
            'agency_id' => $agencyId,
            'loan_product_id' => $productId,
            'loan_number' => 'LN-'.Str::ulid(),
            'requested_amount_minor' => 100000,
            'currency' => 'XAF',
            'applied_on' => '2026-04-28',
            'status' => 'application',
        ]);
    }

    private function createTellerSession(int $agencyId): int
    {
        $tillId = DB::table('tills')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agencyId,
            'code' => 'TILL-'.Str::ulid(),
            'name' => 'Main Till',
            'type' => 'counter',
            'status' => 'active',
        ]);

        $user = User::factory()->createOne();

        return DB::table('teller_sessions')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'till_id' => $tillId,
            'agency_id' => $agencyId,
            'teller_user_id' => $user->id,
            'business_date' => '2026-04-28',
            'status' => 'open',
        ]);
    }

    private function createJournalEntry(int $agencyId): int
    {
        return DB::table('journal_entries')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agencyId,
            'reference' => 'JE-'.Str::ulid(),
            'business_date' => '2026-04-28',
            'status' => 'draft',
        ]);
    }

    private function createLedgerAccount(int $agencyId, ?string $code = null): int
    {
        return DB::table('ledger_accounts')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agencyId,
            'code' => $code ?? '1000-'.Str::ulid(),
            'name' => 'Cash',
            'account_class' => 'asset',
            'normal_balance_side' => 'debit',
            'status' => 'active',
        ]);
    }

    private function createDocument(int $agencyId): int
    {
        return DB::table('documents')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agencyId,
            'category' => 'kyc',
            'title' => 'Identity Document',
            'disk' => 'local',
            'path' => 'documents/'.Str::ulid().'.pdf',
            'original_name' => 'identity.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1234,
            'checksum_sha256' => hash('sha256', 'identity'),
            'status' => 'active',
        ]);
    }
}
