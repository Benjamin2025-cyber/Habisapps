<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Loans;

use App\Application\Loans\AssessLoanArrearsAndPenalties;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\LoanScheduleSnapshot;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AssessLoanArrearsAndPenaltiesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        config(['formulas.policies.penalties_and_arrears.approved' => true]);
    }

    public function test_assesses_monthly_penalty_after_grace_days_without_compounding_prior_penalties(): void
    {
        $loan = $this->createLoanWithSchedule([
            [
                'due_date' => '2026-05-01',
                'principal_minor' => 50000,
                'interest_minor' => 5000,
                'fees_minor' => 0,
                'insurance_minor' => 0,
                'tax_minor' => 0,
                'penalty_minor' => 0,
            ],
        ]);

        $first = app(AssessLoanArrearsAndPenalties::class)->handle($loan, '2026-05-07');

        self::assertSame(6100, $first['assessed_penalty_minor']);
        $this->assertDatabaseHas('loan_arrears', [
            'loan_id' => $loan->id,
            'original_due_minor' => 55000,
            'paid_minor' => 0,
            'unpaid_minor' => 55000,
            'penalty_base_minor' => 55000,
            'status' => 'open',
        ]);
        $this->assertDatabaseHas('loan_schedule_lines', [
            'loan_schedule_snapshot_id' => $this->activeSnapshotId($loan),
            'penalty_minor' => 6100,
            'total_installment_minor' => 61100,
        ]);

        $sameMonth = app(AssessLoanArrearsAndPenalties::class)->handle($loan->refresh(), '2026-05-20');
        self::assertSame(0, $sameMonth['assessed_penalty_minor']);
        $this->assertDatabaseHas('loan_schedule_lines', [
            'loan_schedule_snapshot_id' => $this->activeSnapshotId($loan),
            'penalty_minor' => 6100,
        ]);

        $nextMonth = app(AssessLoanArrearsAndPenalties::class)->handle($loan->refresh(), '2026-06-07');
        self::assertSame(6100, $nextMonth['assessed_penalty_minor']);
        $this->assertDatabaseHas('loan_schedule_lines', [
            'loan_schedule_snapshot_id' => $this->activeSnapshotId($loan),
            'penalty_minor' => 12200,
            'total_installment_minor' => 67200,
        ]);
    }

    public function test_does_not_assess_penalty_below_threshold_or_when_only_prior_penalties_are_unpaid(): void
    {
        $loan = $this->createLoanWithSchedule([
            [
                'due_date' => '2026-05-01',
                'principal_minor' => 900,
                'interest_minor' => 0,
                'fees_minor' => 0,
                'insurance_minor' => 0,
                'tax_minor' => 0,
                'penalty_minor' => 0,
            ],
            [
                'due_date' => '2026-05-01',
                'principal_minor' => 0,
                'interest_minor' => 0,
                'fees_minor' => 0,
                'insurance_minor' => 0,
                'tax_minor' => 0,
                'penalty_minor' => 6000,
            ],
        ]);

        $result = app(AssessLoanArrearsAndPenalties::class)->handle($loan, '2026-05-07');

        self::assertSame(0, $result['assessed_penalty_minor']);
        $this->assertDatabaseHas('loan_arrears', [
            'loan_id' => $loan->id,
            'original_due_minor' => 900,
            'unpaid_minor' => 900,
            'penalty_base_minor' => null,
            'status' => 'open',
        ]);
        self::assertSame(1, DB::table('loan_arrears')->where('loan_id', $loan->id)->count());
        $this->assertDatabaseHas('loan_schedule_lines', [
            'loan_schedule_snapshot_id' => $this->activeSnapshotId($loan),
            'installment_number' => 2,
            'penalty_minor' => 6000,
            'total_installment_minor' => 6000,
        ]);
    }

    public function test_fixed_amount_product_penalty_from_snapshot_overrides_config(): void
    {
        $loan = $this->createPenaltyLoan(
            productPenalty: ['penalty_value_type' => 'amount', 'penalty_value' => 7000, 'penalty_formula_type' => 'fixed'],
            snapshotTerms: ['penalty_grace_days' => 5, 'penalty_value_type' => 'amount', 'penalty_value' => 7000, 'penalty_formula_type' => 'fixed'],
            line: ['principal_minor' => 50000, 'interest_minor' => 5000],
        );

        $result = app(AssessLoanArrearsAndPenalties::class)->handle($loan, '2026-05-07');

        // Flat amount only, no percentage, no config 5000+2% = 6100.
        self::assertSame(7000, $result['assessed_penalty_minor']);
    }

    public function test_percentage_penalty_uses_overdue_amount_base(): void
    {
        $loan = $this->createPenaltyLoan(
            productPenalty: ['penalty_value_type' => 'percentage', 'penalty_value' => 10, 'penalty_formula_type' => 'percentage', 'penalty_formula_base' => 'overdue_amount'],
            snapshotTerms: ['penalty_grace_days' => 5, 'penalty_value_type' => 'percentage', 'penalty_value' => 10, 'penalty_formula_type' => 'percentage', 'penalty_formula_base' => 'overdue_amount'],
            line: ['principal_minor' => 50000, 'interest_minor' => 5000],
            partialPrincipalPaid: 5000,
        );

        $result = app(AssessLoanArrearsAndPenalties::class)->handle($loan, '2026-05-07');

        // overdue_amount = gross scheduled due (55000), before crediting the 5000 payment → 10% = 5500.
        self::assertSame(5500, $result['assessed_penalty_minor']);
    }

    public function test_percentage_penalty_uses_unpaid_scheduled_due_base(): void
    {
        $loan = $this->createPenaltyLoan(
            productPenalty: ['penalty_value_type' => 'percentage', 'penalty_value' => 10, 'penalty_formula_type' => 'percentage', 'penalty_formula_base' => 'unpaid_scheduled_due'],
            snapshotTerms: ['penalty_grace_days' => 5, 'penalty_value_type' => 'percentage', 'penalty_value' => 10, 'penalty_formula_type' => 'percentage', 'penalty_formula_base' => 'unpaid_scheduled_due'],
            line: ['principal_minor' => 50000, 'interest_minor' => 5000],
            partialPrincipalPaid: 5000,
        );

        $result = app(AssessLoanArrearsAndPenalties::class)->handle($loan, '2026-05-07');

        // unpaid_scheduled_due = 55000 - 5000 paid = 50000 → 10% = 5000 (distinct from overdue_amount).
        self::assertSame(5000, $result['assessed_penalty_minor']);
    }

    public function test_percentage_penalty_uses_principal_base(): void
    {
        $loan = $this->createPenaltyLoan(
            productPenalty: ['penalty_value_type' => 'percentage', 'penalty_value' => 10, 'penalty_formula_type' => 'percentage', 'penalty_formula_base' => 'principal'],
            snapshotTerms: ['penalty_grace_days' => 5, 'penalty_value_type' => 'percentage', 'penalty_value' => 10, 'penalty_formula_type' => 'percentage', 'penalty_formula_base' => 'principal'],
            line: ['principal_minor' => 50000, 'interest_minor' => 5000],
        );

        $result = app(AssessLoanArrearsAndPenalties::class)->handle($loan, '2026-05-07');

        // principal base = 50000 (not the 55000 scheduled due) → 10% = 5000.
        self::assertSame(5000, $result['assessed_penalty_minor']);
    }

    public function test_two_products_with_different_penalty_terms_produce_different_penalties(): void
    {
        $fixedLoan = $this->createPenaltyLoan(
            productPenalty: ['penalty_value_type' => 'amount', 'penalty_value' => 7000, 'penalty_formula_type' => 'fixed'],
            snapshotTerms: ['penalty_grace_days' => 5, 'penalty_value_type' => 'amount', 'penalty_value' => 7000, 'penalty_formula_type' => 'fixed'],
            line: ['principal_minor' => 50000, 'interest_minor' => 5000],
        );
        $percentLoan = $this->createPenaltyLoan(
            productPenalty: ['penalty_value_type' => 'percentage', 'penalty_value' => 10, 'penalty_formula_type' => 'percentage', 'penalty_formula_base' => 'overdue_amount'],
            snapshotTerms: ['penalty_grace_days' => 5, 'penalty_value_type' => 'percentage', 'penalty_value' => 10, 'penalty_formula_type' => 'percentage', 'penalty_formula_base' => 'overdue_amount'],
            line: ['principal_minor' => 50000, 'interest_minor' => 5000],
        );

        $fixed = app(AssessLoanArrearsAndPenalties::class)->handle($fixedLoan, '2026-05-07');
        $percent = app(AssessLoanArrearsAndPenalties::class)->handle($percentLoan, '2026-05-07');

        self::assertSame(7000, $fixed['assessed_penalty_minor']);
        self::assertSame(5500, $percent['assessed_penalty_minor']);
        self::assertNotSame($fixed['assessed_penalty_minor'], $percent['assessed_penalty_minor']);
    }

    public function test_snapshot_penalty_terms_take_precedence_over_later_product_edits(): void
    {
        $loan = $this->createPenaltyLoan(
            // Current product now says 9000, but the snapshot froze 7000 at creation.
            productPenalty: ['penalty_value_type' => 'amount', 'penalty_value' => 9000, 'penalty_formula_type' => 'fixed'],
            snapshotTerms: ['penalty_grace_days' => 5, 'penalty_value_type' => 'amount', 'penalty_value' => 7000, 'penalty_formula_type' => 'fixed'],
            line: ['principal_minor' => 50000, 'interest_minor' => 5000],
        );

        $result = app(AssessLoanArrearsAndPenalties::class)->handle($loan, '2026-05-07');

        self::assertSame(7000, $result['assessed_penalty_minor']);
    }

    public function test_current_product_penalty_used_when_loan_has_no_snapshot(): void
    {
        $loan = $this->createPenaltyLoan(
            productPenalty: ['penalty_value_type' => 'amount', 'penalty_value' => 7000, 'penalty_formula_type' => 'fixed'],
            snapshotTerms: null,
            line: ['principal_minor' => 50000, 'interest_minor' => 5000],
        );

        $result = app(AssessLoanArrearsAndPenalties::class)->handle($loan, '2026-05-07');

        self::assertSame(7000, $result['assessed_penalty_minor']);
    }

    /**
     * @param  array<string, mixed>  $productPenalty
     * @param  array<string, mixed>|null  $snapshotTerms
     * @param  array{principal_minor:int, interest_minor:int}  $line
     */
    private function createPenaltyLoan(array $productPenalty, ?array $snapshotTerms, array $line, int $partialPrincipalPaid = 0): Loan
    {
        $agencyId = DB::table('agencies')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'code' => 'PEN-'.Str::upper(Str::random(6)),
            'name' => 'Penalty Agency',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $clientId = DB::table('clients')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agencyId,
            'client_reference' => 'CLI-'.Str::ulid(),
            'first_name' => 'Penalty',
            'last_name' => 'Client',
            'status' => 'active',
            'kyc_status' => 'verified',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $ledgerAccountId = DB::table('ledger_accounts')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agencyId,
            'code' => 'L-'.Str::ulid(),
            'name' => 'Loan Ledger',
            'account_class' => 'asset',
            'normal_balance_side' => 'debit',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $loanProduct = LoanProduct::query()->create(array_merge([
            'public_id' => (string) Str::ulid(),
            'ledger_account_id' => $ledgerAccountId,
            'code' => 'LP-'.Str::ulid(),
            'name' => 'Penalty Product',
            'status' => LoanProduct::STATUS_ACTIVE,
            'penalty_grace_days' => 5,
        ], $productPenalty));
        $loan = Loan::query()->create([
            'public_id' => (string) Str::ulid(),
            'client_id' => $clientId,
            'agency_id' => $agencyId,
            'loan_product_id' => $loanProduct->id,
            'loan_number' => 'LN-'.Str::ulid(),
            'requested_amount_minor' => 100000,
            'approved_principal_minor' => 100000,
            'currency' => 'XAF',
            'applied_on' => '2026-04-01',
            'approved_on' => '2026-04-02',
            'disbursed_on' => '2026-04-03',
            'status' => Loan::STATUS_DISBURSED,
            'formula_policy_snapshot' => $snapshotTerms === null ? null : ['product_terms' => $snapshotTerms],
        ]);
        $snapshot = LoanScheduleSnapshot::query()->create([
            'public_id' => (string) Str::ulid(),
            'loan_id' => $loan->id,
            'formula_engine_key' => 'installment',
            'formula_engine_version' => 'test',
            'policy_snapshot_hash' => hash('sha256', 'test'),
            'generated_at' => now(),
            'status' => LoanScheduleSnapshot::STATUS_ACTIVE,
        ]);

        $due = $line['principal_minor'] + $line['interest_minor'];
        $lineId = DB::table('loan_schedule_lines')->insertGetId([
            'loan_schedule_snapshot_id' => $snapshot->id,
            'installment_number' => 1,
            'due_date' => '2026-05-01',
            'principal_minor' => $line['principal_minor'],
            'interest_minor' => $line['interest_minor'],
            'fees_minor' => 0,
            'insurance_minor' => 0,
            'tax_minor' => 0,
            'penalty_minor' => 0,
            'capitalized_interest_minor' => 0,
            'remaining_principal_minor' => 0,
            'total_installment_minor' => $due,
            'currency' => 'XAF',
            'status' => 'scheduled',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($partialPrincipalPaid > 0) {
            $this->applyPrincipalPayment($loan, $agencyId, $clientId, $ledgerAccountId, $lineId, $partialPrincipalPaid);
        }

        return $loan;
    }

    private function applyPrincipalPayment(Loan $loan, int $agencyId, int $clientId, int $ledgerAccountId, int $lineId, int $amountMinor): void
    {
        $customerAccountId = DB::table('customer_accounts')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'client_id' => $clientId,
            'agency_id' => $agencyId,
            'ledger_account_id' => $ledgerAccountId,
            'account_number' => 'CA-'.Str::upper(Str::random(8)),
            'account_type' => 'savings',
            'opened_on' => '2026-04-01',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $journalEntryId = DB::table('journal_entries')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'reference' => 'JE-'.Str::upper(Str::random(8)),
            'business_date' => '2026-05-02',
            'agency_id' => $agencyId,
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $repaymentId = DB::table('loan_repayments')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agencyId,
            'loan_id' => $loan->id,
            'journal_entry_id' => $journalEntryId,
            'customer_account_id' => $customerAccountId,
            'received_amount_minor' => $amountMinor,
            'allocated_amount_minor' => $amountMinor,
            'currency' => 'XAF',
            'paid_on' => '2026-05-02',
            'status' => 'posted',
            'posted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('loan_repayment_allocations')->insert([
            'loan_repayment_id' => $repaymentId,
            'loan_schedule_line_id' => $lineId,
            'component' => 'principal',
            'amount_minor' => $amountMinor,
            'currency' => 'XAF',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<int, array{due_date:string, principal_minor:int, interest_minor:int, fees_minor:int, insurance_minor:int, tax_minor:int, penalty_minor:int}>  $lines
     */
    private function createLoanWithSchedule(array $lines): Loan
    {
        $agencyId = DB::table('agencies')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'code' => 'ARR-'.Str::upper(Str::random(6)),
            'name' => 'Arrears Agency',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $clientId = DB::table('clients')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agencyId,
            'client_reference' => 'CLI-'.Str::ulid(),
            'first_name' => 'Arrears',
            'last_name' => 'Client',
            'status' => 'active',
            'kyc_status' => 'verified',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $ledgerAccountId = DB::table('ledger_accounts')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agencyId,
            'code' => 'L-'.Str::ulid(),
            'name' => 'Loan Ledger',
            'account_class' => 'asset',
            'normal_balance_side' => 'debit',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $loanProduct = LoanProduct::query()->create([
            'public_id' => (string) Str::ulid(),
            'ledger_account_id' => $ledgerAccountId,
            'code' => 'LP-'.Str::ulid(),
            'name' => 'Penalty Product',
            'status' => LoanProduct::STATUS_ACTIVE,
            'penalty_grace_days' => 5,
        ]);
        $loan = Loan::query()->create([
            'public_id' => (string) Str::ulid(),
            'client_id' => $clientId,
            'agency_id' => $agencyId,
            'loan_product_id' => $loanProduct->id,
            'loan_number' => 'LN-'.Str::ulid(),
            'requested_amount_minor' => 100000,
            'approved_principal_minor' => 100000,
            'currency' => 'XAF',
            'applied_on' => '2026-04-01',
            'approved_on' => '2026-04-02',
            'disbursed_on' => '2026-04-03',
            'status' => Loan::STATUS_DISBURSED,
        ]);
        $snapshot = LoanScheduleSnapshot::query()->create([
            'public_id' => (string) Str::ulid(),
            'loan_id' => $loan->id,
            'formula_engine_key' => 'installment',
            'formula_engine_version' => 'test',
            'policy_snapshot_hash' => hash('sha256', 'test'),
            'generated_at' => now(),
            'status' => LoanScheduleSnapshot::STATUS_ACTIVE,
        ]);

        foreach ($lines as $index => $line) {
            $penalty = $line['penalty_minor'];
            DB::table('loan_schedule_lines')->insert([
                'loan_schedule_snapshot_id' => $snapshot->id,
                'installment_number' => $index + 1,
                'due_date' => $line['due_date'],
                'principal_minor' => $line['principal_minor'],
                'interest_minor' => $line['interest_minor'],
                'fees_minor' => $line['fees_minor'],
                'insurance_minor' => $line['insurance_minor'],
                'tax_minor' => $line['tax_minor'],
                'penalty_minor' => $penalty,
                'capitalized_interest_minor' => 0,
                'remaining_principal_minor' => 0,
                'total_installment_minor' => $line['principal_minor'] + $line['interest_minor'] + $line['fees_minor'] + $line['insurance_minor'] + $line['tax_minor'] + $penalty,
                'currency' => 'XAF',
                'status' => 'scheduled',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $loan;
    }

    private function activeSnapshotId(Loan $loan): int
    {
        $id = DB::table('loan_schedule_snapshots')
            ->where('loan_id', $loan->id)
            ->where('status', LoanScheduleSnapshot::STATUS_ACTIVE)
            ->value('id');
        self::assertIsInt($id);

        return $id;
    }
}
