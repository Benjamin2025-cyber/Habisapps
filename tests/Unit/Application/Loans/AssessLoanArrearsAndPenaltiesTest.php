<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Loans;

use App\Application\Loans\AssessLoanArrearsAndPenalties;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\LoanScheduleSnapshot;
use App\Support\Finance\MoneyAmount;
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
        // 50 000 XAF principal + 5 000 XAF interest, in minor units at the
        // account scale (a franc is 100 minor units).
        $loan = $this->createLoanWithSchedule([
            [
                'due_date' => '2026-05-01',
                'principal_minor' => 5000000,
                'interest_minor' => 500000,
                'fees_minor' => 0,
                'insurance_minor' => 0,
                'tax_minor' => 0,
                'penalty_minor' => 0,
            ],
        ]);

        $first = app(AssessLoanArrearsAndPenalties::class)->handle($loan, '2026-05-07');

        // Hybrid formula: 5 000 XAF fixed + 2 % of the 55 000 XAF unpaid
        // (= 1 100 XAF), i.e. 610 000 minor units.
        self::assertSame(610000, $first['assessed_penalty_minor']);
        $this->assertDatabaseHas('loan_arrears', [
            'loan_id' => $loan->id,
            'original_due_minor' => 5500000,
            'paid_minor' => 0,
            'unpaid_minor' => 5500000,
            'penalty_base_minor' => 5500000,
            'status' => 'open',
        ]);
        $this->assertDatabaseHas('loan_schedule_lines', [
            'loan_schedule_snapshot_id' => $this->activeSnapshotId($loan),
            'penalty_minor' => 610000,
            'total_installment_minor' => 6110000,
        ]);

        $sameMonth = app(AssessLoanArrearsAndPenalties::class)->handle($loan->refresh(), '2026-05-20');
        self::assertSame(0, $sameMonth['assessed_penalty_minor']);
        $this->assertDatabaseHas('loan_schedule_lines', [
            'loan_schedule_snapshot_id' => $this->activeSnapshotId($loan),
            'penalty_minor' => 610000,
        ]);

        $nextMonth = app(AssessLoanArrearsAndPenalties::class)->handle($loan->refresh(), '2026-06-07');
        self::assertSame(610000, $nextMonth['assessed_penalty_minor']);
        $this->assertDatabaseHas('loan_schedule_lines', [
            'loan_schedule_snapshot_id' => $this->activeSnapshotId($loan),
            'penalty_minor' => 1220000,
            'total_installment_minor' => 6720000,
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

    /**
     * « Do not penalize unpaid amounts below 1,000 XAF ». Without a floor at the
     * right magnitude, the hybrid formula drops a 5 000 XAF penalty onto a
     * residue of a few francs.
     */
    public function test_arrears_floor_is_one_thousand_francs_not_ten(): void
    {
        // 999 XAF unpaid — under the floor, no penalty.
        $under = $this->createPenaltyLoan(
            snapshotTerms: ['penalty_grace_days' => 5],
            line: ['principal_minor' => 99900, 'interest_minor' => 0],
        );
        $underResult = app(AssessLoanArrearsAndPenalties::class)->handle($under, '2026-05-07');
        self::assertSame(0, $underResult['assessed_penalty_minor']);

        // 1 000 XAF unpaid — on the floor, penalty applies: 5 000 XAF fixed
        // + 2 % of 1 000 XAF (= 20 XAF).
        $atFloor = $this->createPenaltyLoan(
            snapshotTerms: ['penalty_grace_days' => 5],
            line: ['principal_minor' => 100000, 'interest_minor' => 0],
        );
        $atFloorResult = app(AssessLoanArrearsAndPenalties::class)->handle($atFloor, '2026-05-07');
        self::assertSame(502000, $atFloorResult['assessed_penalty_minor']);
    }

    /**
     * The percentage rate is the scale-0 string '2', and BigDecimal::dividedBy
     * without an explicit scale rounds with UNNECESSARY. Any unpaid base that is
     * not a multiple of 50 used to raise RoundingNecessaryException — a
     * RuntimeException, so it aborted the whole monthly arrears batch instead of
     * failing one loan.
     */
    public function test_variable_part_rounds_instead_of_throwing_on_an_indivisible_base(): void
    {
        $loan = $this->createPenaltyLoan(
            snapshotTerms: ['penalty_grace_days' => 5],
            line: ['principal_minor' => 123457, 'interest_minor' => 0],
        );

        $result = app(AssessLoanArrearsAndPenalties::class)->handle($loan, '2026-05-07');

        // 5 000 XAF fixed + 2 % of 123 457 = 2 469.14 → 2 469 half-up,
        // i.e. 500 000 + 2 469 minor units.
        self::assertSame(502469, $result['assessed_penalty_minor']);
    }

    /**
     * The accounting team's rule is one hybrid amount per overdue installment:
     * « une partie fixe 5.000 FCFA et une partie variable 2% du montant
     * impayé ». Asserted in francs, not minor units: a minor-unit literal that
     * merely echoes the constant cannot catch a scale error in the constant
     * itself.
     */
    public function test_penalty_is_the_fixed_part_plus_the_variable_part_on_the_unpaid_amount(): void
    {
        $loan = $this->createPenaltyLoan(
            snapshotTerms: ['penalty_grace_days' => 5],
            line: ['principal_minor' => 5000000, 'interest_minor' => 500000],
        );

        $result = app(AssessLoanArrearsAndPenalties::class)->handle($loan, '2026-05-07');

        // 5 000 XAF + 2 % of 55 000 XAF (= 1 100 XAF) = 6 100 XAF.
        self::assertSame(610000, $result['assessed_penalty_minor']);
        self::assertSame('6100.00', MoneyAmount::ofMinor($result['assessed_penalty_minor'])->amount());
    }

    public function test_partial_payments_shrink_the_variable_part_but_never_the_fixed_part(): void
    {
        $loan = $this->createPenaltyLoan(
            snapshotTerms: ['penalty_grace_days' => 5],
            line: ['principal_minor' => 5000000, 'interest_minor' => 500000],
            partialPrincipalPaid: 500000,
        );

        $result = app(AssessLoanArrearsAndPenalties::class)->handle($loan, '2026-05-07');

        // 5 500 000 due − 500 000 paid = 5 000 000 unpaid; 2 % = 100 000
        // (1 000 XAF) on top of the untouched 5 000 XAF fixed part.
        self::assertSame(600000, $result['assessed_penalty_minor']);
        self::assertSame('6000.00', MoneyAmount::ofMinor($result['assessed_penalty_minor'])->amount());
    }

    /**
     * The penalty is not hardcoded in the engine: it resolves from the approved
     * `penalties_and_arrears` formula-policy config, so a stakeholder-approved
     * change to either component flows through without a code change.
     */
    public function test_penalty_terms_come_from_the_approved_formula_policy_config(): void
    {
        config([
            'formulas.policies.penalties_and_arrears.rules.monthly_arrears_penalty.fixed_amount_minor' => 250000,
            'formulas.policies.penalties_and_arrears.rules.monthly_arrears_penalty.variable_rate_percent' => '1',
        ]);

        $loan = $this->createPenaltyLoan(
            snapshotTerms: ['penalty_grace_days' => 5],
            line: ['principal_minor' => 5000000, 'interest_minor' => 500000],
        );

        $result = app(AssessLoanArrearsAndPenalties::class)->handle($loan, '2026-05-07');

        // 2 500 XAF fixed + 1 % of 55 000 XAF (= 550 XAF) = 3 050 XAF.
        self::assertSame(305000, $result['assessed_penalty_minor']);
    }

    /**
     * @param  array<string, mixed>|null  $snapshotTerms
     * @param  array{principal_minor:int, interest_minor:int}  $line
     */
    private function createPenaltyLoan(?array $snapshotTerms, array $line, int $partialPrincipalPaid = 0): Loan
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
            'account_class' => 'tresorerie_interbancaire',
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
            'account_class' => 'tresorerie_interbancaire',
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
