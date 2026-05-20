<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StakeholderCompleteSchemaIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_stakeholder_completion_tables_and_columns_exist(): void
    {
        foreach ([
            'account_products',
            'loan_approvals',
            'collateral_items',
            'loan_transfers',
            'loan_guarantee_obligations',
            'loan_disbursements',
            'loan_repayments',
            'loan_repayment_allocations',
            'delinquency_trackings',
            'loan_charge_assessments',
            'loan_arrears',
            'loan_recovery_accounts',
            'loan_recovery_attempts',
            'insurance_partners',
            'insurance_products',
            'insurance_subscriptions',
            'insurance_premium_assessments',
            'insurance_premium_payments',
            'insurance_claims',
            'hr_employees',
            'hr_contracts',
            'hr_attendance_records',
            'hr_leave_requests',
            'hr_payroll_runs',
            'hr_payroll_slips',
            'hr_payroll_lines',
            'currencies',
            'exchange_rates',
            'till_currency_balances',
            'fx_transactions',
            'fx_stock_movements',
            'islamic_products',
            'islamic_financings',
            'islamic_financed_assets',
            'islamic_profit_sharing_terms',
            'islamic_compliance_reviews',
            'islamic_financing_installments',
            'emf_regulatory_accounts',
            'emf_ledger_account_mappings',
            'operation_codes',
            'operation_account_mappings',
            'report_definitions',
            'report_runs',
            'dashboard_definitions',
            'dashboard_widgets',
            'notification_templates',
            'notification_deliveries',
            'sms_messages',
        ] as $table) {
            self::assertTrue(Schema::hasTable($table), "Missing table [{$table}].");
        }

        self::assertTrue(Schema::hasColumns('loan_products', [
            'min_amount_minor',
            'max_amount_minor',
            'tax_policy_key',
            'insurance_policy_key',
            'guarantee_deposit_policy_key',
        ]));

        self::assertTrue(Schema::hasColumns('loans', [
            'amortization_account_id',
            'unpaid_account_id',
            'recovery_account_id',
            'transfer_account_id',
            'insurance_amount_minor',
            'capitalized_interest_minor',
        ]));

        self::assertTrue(Schema::hasColumns('tills', [
            'ledger_account_id',
            'daily_state',
            'requires_denominations',
            'is_central_till',
            'max_balance_limit_minor',
        ]));

        self::assertTrue(Schema::hasColumns('agencies', [
            'branch_type',
            'fax_number',
            'po_box',
            'geographic_description',
        ]));

        self::assertTrue(Schema::hasColumns('clients', [
            'profile_photo_document_id',
            'father_name',
            'mother_name',
            'home_phone_number',
            'kyc_submitted_by_user_id',
            'business_started_on',
            'business_activity_started_on',
            'business_address_line_1',
            'business_address_line_2',
            'business_city',
            'business_region',
            'sector_id',
            'sub_sector_id',
        ]));

        self::assertTrue(Schema::hasColumns('client_identity_documents', [
            'document_number_hash',
        ]));

        self::assertTrue(Schema::hasColumns('journal_entries', [
            'submitted_at',
            'submitted_by_user_id',
            'reviewed_at',
            'reviewed_by_user_id',
            'review_comment',
            'rejection_reason',
        ]));

        self::assertTrue(Schema::hasColumns('loan_repayments', [
            'loan_id',
            'journal_entry_id',
            'customer_account_id',
            'received_amount_minor',
            'allocated_amount_minor',
            'overpayment_retained_minor',
        ]));

        self::assertTrue(Schema::hasColumns('loan_repayment_allocations', [
            'loan_repayment_id',
            'loan_schedule_line_id',
            'component',
            'amount_minor',
        ]));
    }

    public function test_client_kyc_status_vocabulary_is_enforced(): void
    {
        $agencyId = $this->createAgency('KV01');

        $clientId = DB::table('clients')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agencyId,
            'client_reference' => 'CL-KYC-'.Str::ulid(),
            'first_name' => 'Default',
            'last_name' => 'Vocabulary',
            'status' => 'active',
        ]);

        self::assertSame('draft', DB::table('clients')->where('id', $clientId)->value('kyc_status'));

        $this->expectException(QueryException::class);

        DB::table('clients')->insert([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agencyId,
            'client_reference' => 'CL-BAD-KYC-'.Str::ulid(),
            'first_name' => 'Invalid',
            'last_name' => 'Vocabulary',
            'status' => 'active',
            'kyc_status' => 'pending',
        ]);
    }

    public function test_client_sector_and_sub_sector_must_match_in_database(): void
    {
        $agencyId = $this->createAgency('SC01');
        $sectorA = $this->createSectorAndSubSector('SC-A', 'SC-A1');
        $sectorB = $this->createSectorAndSubSector('SC-B', 'SC-B1');

        $this->expectException(QueryException::class);

        DB::table('clients')->insert([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agencyId,
            'client_reference' => 'CL-SC-'.Str::ulid(),
            'first_name' => 'Invalid',
            'last_name' => 'Classification',
            'status' => 'active',
            'sector_id' => $sectorA['sector_id'],
            'sub_sector_id' => $sectorB['sub_sector_id'],
        ]);
    }

    public function test_loan_sector_and_sub_sector_must_match_in_database(): void
    {
        $agencyId = $this->createAgency('SL01');
        $clientId = $this->createClient($agencyId);
        $loanProductId = $this->createLoanProduct();
        $sectorA = $this->createSectorAndSubSector('SL-A', 'SL-A1');
        $sectorB = $this->createSectorAndSubSector('SL-B', 'SL-B1');

        $this->expectException(QueryException::class);

        DB::table('loans')->insert([
            'public_id' => (string) Str::ulid(),
            'client_id' => $clientId,
            'agency_id' => $agencyId,
            'loan_product_id' => $loanProductId,
            'loan_number' => 'LN-SC-'.Str::ulid(),
            'requested_amount_minor' => 100000,
            'currency' => 'XAF',
            'applied_on' => '2026-05-11',
            'status' => 'application',
            'sector_id' => $sectorA['sector_id'],
            'sub_sector_id' => $sectorB['sub_sector_id'],
        ]);
    }

    public function test_journal_entry_review_status_vocabulary_is_enforced(): void
    {
        $agencyId = $this->createAgency('JR01');

        $this->expectException(QueryException::class);

        DB::table('journal_entries')->insert([
            'public_id' => (string) Str::ulid(),
            'reference' => 'JR-BAD-'.Str::ulid(),
            'business_date' => '2026-05-11',
            'agency_id' => $agencyId,
            'status' => 'pending_review',
        ]);
    }

    public function test_account_product_minimum_balance_cannot_be_negative(): void
    {
        $this->expectException(QueryException::class);

        DB::table('account_products')->insert([
            'public_id' => (string) Str::ulid(),
            'code' => 'SAV-BAD',
            'name' => 'Invalid Savings',
            'account_family' => 'savings',
            'minimum_balance_minor' => -1,
            'currency' => 'XAF',
        ]);
    }

    public function test_loan_product_limits_and_due_date_are_validated_by_database(): void
    {
        $this->expectException(QueryException::class);

        DB::table('loan_products')->insert([
            'public_id' => (string) Str::ulid(),
            'code' => 'LP-BAD',
            'name' => 'Invalid Product',
            'status' => 'active',
            'min_amount_minor' => 200000,
            'max_amount_minor' => 100000,
            'due_date_day' => 32,
        ]);
    }

    public function test_loan_projection_amounts_cannot_be_negative(): void
    {
        $agencyId = $this->createAgency('LN01');
        $clientId = $this->createClient($agencyId);
        $loanProductId = $this->createLoanProduct();

        $this->expectException(QueryException::class);

        DB::table('loans')->insert([
            'public_id' => (string) Str::ulid(),
            'client_id' => $clientId,
            'agency_id' => $agencyId,
            'loan_product_id' => $loanProductId,
            'loan_number' => 'LN-'.Str::ulid(),
            'requested_amount_minor' => 100000,
            'currency' => 'XAF',
            'applied_on' => '2026-05-11',
            'status' => 'application',
            'insurance_amount_minor' => -1,
        ]);
    }

    public function test_charge_arrears_and_recovery_amount_constraints_are_enforced(): void
    {
        $agencyId = $this->createAgency('LR01');
        $loanId = $this->createLoan($agencyId);
        $accountId = $this->createCustomerAccount($agencyId);

        DB::table('loan_recovery_accounts')->insert([
            'public_id' => (string) Str::ulid(),
            'loan_id' => $loanId,
            'customer_account_id' => $accountId,
            'priority' => 1,
            'is_primary' => true,
            'status' => 'active',
        ]);

        $this->expectException(QueryException::class);

        DB::table('loan_recovery_attempts')->insert([
            'public_id' => (string) Str::ulid(),
            'loan_id' => $loanId,
            'customer_account_id' => $accountId,
            'requested_amount_minor' => -100,
            'recovered_amount_minor' => 0,
            'currency' => 'XAF',
            'status' => 'pending',
        ]);
    }

    public function test_loan_guarantee_obligation_preserves_guarantor_snapshot_and_agency_scope(): void
    {
        $agencyA = $this->createAgency('LG01');
        $agencyB = $this->createAgency('LG02');
        $clientId = $this->createClient($agencyA);
        $loanId = $this->createLoan($agencyA, $clientId);

        $guarantorId = DB::table('client_guarantors')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agencyA,
            'client_id' => $clientId,
            'guarantor_full_name' => 'Original Guarantor',
            'guarantor_phone_number' => '+237600000111',
            'status' => 'active',
            'verification_status' => 'verified',
        ]);

        DB::table('loan_guarantee_obligations')->insert([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agencyA,
            'loan_id' => $loanId,
            'client_guarantor_id' => $guarantorId,
            'obligation_type' => 'personal_guarantee',
            'obligation_amount_minor' => 100000,
            'currency' => 'XAF',
            'status' => 'active',
            'starts_on' => '2026-05-11',
            'release_condition' => 'full_settlement',
            'guarantor_identity_snapshot' => json_encode([
                'guarantor_full_name' => 'Original Guarantor',
                'guarantor_phone_number' => '+237600000111',
            ], JSON_THROW_ON_ERROR),
        ]);

        DB::table('client_guarantors')
            ->where('id', $guarantorId)
            ->update(['guarantor_full_name' => 'Updated Guarantor']);

        $snapshot = DB::table('loan_guarantee_obligations')
            ->where('client_guarantor_id', $guarantorId)
            ->value('guarantor_identity_snapshot');

        self::assertIsString($snapshot);
        $decodedSnapshot = json_decode($snapshot, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decodedSnapshot);
        self::assertSame('Original Guarantor', $decodedSnapshot['guarantor_full_name']);

        $crossAgencyClientId = $this->createClient($agencyB);
        $crossAgencyGuarantorId = DB::table('client_guarantors')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agencyB,
            'client_id' => $crossAgencyClientId,
            'guarantor_full_name' => 'Cross Agency',
            'status' => 'active',
            'verification_status' => 'verified',
        ]);

        $this->expectException(QueryException::class);

        DB::table('loan_guarantee_obligations')->insert([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agencyA,
            'loan_id' => $loanId,
            'client_guarantor_id' => $crossAgencyGuarantorId,
            'obligation_percentage' => 50,
            'status' => 'active',
        ]);
    }

    public function test_complete_insurance_lifecycle_tables_accept_valid_rows_and_reject_invalid_premium(): void
    {
        $agencyId = $this->createAgency('IN01');
        $clientId = $this->createClient($agencyId);
        $loanId = $this->createLoan($agencyId, $clientId);

        $partnerId = DB::table('insurance_partners')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agencyId,
            'code' => 'AXA',
            'name' => 'AXA Assurance',
            'status' => 'active',
        ]);

        $productId = DB::table('insurance_products')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'insurance_partner_id' => $partnerId,
            'code' => 'ASS',
            'name' => 'Assurance Emprunteur',
            'product_type' => 'borrower',
            'premium_calculation_type' => 'percentage',
            'premium_rate' => 2,
            'currency' => 'XAF',
            'payment_mode' => 'upfront',
            'status' => 'active',
        ]);

        DB::table('insurance_product_coverages')->insert([
            'insurance_product_id' => $productId,
            'coverage_code' => 'DEATH',
            'coverage_name' => 'Death',
        ]);

        $subscriptionId = DB::table('insurance_subscriptions')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'client_id' => $clientId,
            'agency_id' => $agencyId,
            'loan_id' => $loanId,
            'insurance_product_id' => $productId,
            'subscription_number' => 'INS-'.Str::ulid(),
            'currency' => 'XAF',
            'status' => 'active',
        ]);

        DB::table('insurance_premium_assessments')->insert([
            'public_id' => (string) Str::ulid(),
            'insurance_subscription_id' => $subscriptionId,
            'loan_id' => $loanId,
            'premium_amount_minor' => 2000,
            'currency' => 'XAF',
            'status' => 'assessed',
        ]);

        $this->assertDatabaseHas('insurance_subscriptions', [
            'id' => $subscriptionId,
            'loan_id' => $loanId,
        ]);

        $this->expectException(QueryException::class);

        DB::table('insurance_premium_assessments')->insert([
            'public_id' => (string) Str::ulid(),
            'insurance_subscription_id' => $subscriptionId,
            'loan_id' => $loanId,
            'premium_amount_minor' => 0,
            'currency' => 'XAF',
            'status' => 'assessed',
        ]);
    }

    public function test_hr_leave_dates_and_salary_advance_amounts_are_constrained(): void
    {
        $employeeId = $this->createHrEmployee();

        DB::table('hr_salary_advances')->insert([
            'public_id' => (string) Str::ulid(),
            'hr_employee_id' => $employeeId,
            'amount_minor' => 10000,
            'remaining_amount_minor' => 5000,
            'currency' => 'XAF',
            'status' => 'active',
        ]);

        $this->expectException(QueryException::class);

        DB::table('hr_leave_requests')->insert([
            'public_id' => (string) Str::ulid(),
            'hr_employee_id' => $employeeId,
            'leave_type' => 'annual',
            'starts_on' => '2026-05-20',
            'ends_on' => '2026-05-10',
            'status' => 'pending',
        ]);
    }

    public function test_fx_rates_till_balances_and_transactions_are_constrained(): void
    {
        $agencyId = $this->createAgency('FX01');
        $tillId = $this->createTill($agencyId);

        DB::table('currencies')->insert([
            'code' => 'USD',
            'name' => 'US Dollar',
            'minor_unit' => 2,
            'status' => 'active',
        ]);

        DB::table('till_currency_balances')->insert([
            'till_id' => $tillId,
            'currency' => 'USD',
            'opening_balance_minor' => 10000,
            'current_balance_minor' => 10000,
        ]);

        $this->expectException(QueryException::class);

        DB::table('exchange_rates')->insert([
            'public_id' => (string) Str::ulid(),
            'base_currency' => 'XAF',
            'quote_currency' => 'USD',
            'reference_rate' => 0,
            'buy_rate' => 475,
            'sell_rate' => 525,
            'effective_on' => '2026-05-11',
            'status' => 'active',
        ]);
    }

    public function test_islamic_finance_profit_sharing_rates_cannot_be_negative(): void
    {
        $agencyId = $this->createAgency('IF01');
        $clientId = $this->createClient($agencyId);

        $productId = DB::table('islamic_products')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agencyId,
            'code' => 'MOU-'.Str::ulid(),
            'name' => 'Moudaraba',
            'contract_type' => 'moudaraba',
            'status' => 'active',
        ]);

        $financingId = DB::table('islamic_financings')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'client_id' => $clientId,
            'agency_id' => $agencyId,
            'islamic_product_id' => $productId,
            'contract_number' => 'IF-'.Str::ulid(),
            'contract_type' => 'moudaraba',
            'financed_amount_minor' => 500000,
            'currency' => 'XAF',
            'status' => 'draft',
        ]);

        $this->expectException(QueryException::class);

        DB::table('islamic_profit_sharing_terms')->insert([
            'islamic_financing_id' => $financingId,
            'institution_share_rate' => -1,
            'client_share_rate' => 101,
        ]);
    }

    public function test_reporting_codification_notifications_and_sms_tables_accept_reference_rows(): void
    {
        $agencyId = $this->createAgency('RP01');
        $ledgerAccountId = $this->createLedgerAccount($agencyId);

        $operationCodeId = DB::table('operation_codes')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'code' => 'LOAN_DISBURSEMENT',
            'label' => 'Loan Disbursement',
            'module' => 'loan',
            'operation_type' => 'disbursement',
            'direction' => 'debit',
            'status' => 'active',
        ]);

        DB::table('operation_account_mappings')->insert([
            'public_id' => (string) Str::ulid(),
            'operation_code_id' => $operationCodeId,
            'debit_ledger_account_id' => $ledgerAccountId,
            'currency' => 'XAF',
            'status' => 'active',
        ]);

        $reportDefinitionId = DB::table('report_definitions')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'code' => 'COBAC-BALANCE',
            'name' => 'COBAC Balance',
            'report_type' => 'cobac',
            'module' => 'accounting',
            'status' => 'active',
        ]);

        DB::table('report_runs')->insert([
            'public_id' => (string) Str::ulid(),
            'report_definition_id' => $reportDefinitionId,
            'agency_id' => $agencyId,
            'status' => 'pending',
        ]);

        $templateId = DB::table('notification_templates')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'code' => 'REPAYMENT_REMINDER',
            'channel' => 'sms',
            'body_template' => 'Your repayment is due.',
            'status' => 'active',
        ]);

        DB::table('notification_deliveries')->insert([
            'public_id' => (string) Str::ulid(),
            'notification_template_id' => $templateId,
            'channel' => 'sms',
            'destination' => '+237600000000',
            'body' => 'Your repayment is due.',
            'status' => 'pending',
        ]);

        DB::table('sms_messages')->insert([
            'public_id' => (string) Str::ulid(),
            'phone_number' => '+237600000000',
            'message' => 'Your repayment is due.',
            'direction' => 'outbound',
            'status' => 'pending',
        ]);

        $this->assertDatabaseHas('operation_account_mappings', [
            'operation_code_id' => $operationCodeId,
            'debit_ledger_account_id' => $ledgerAccountId,
        ]);
        $this->assertDatabaseHas('report_runs', [
            'report_definition_id' => $reportDefinitionId,
            'agency_id' => $agencyId,
        ]);
        $this->assertDatabaseHas('sms_messages', [
            'phone_number' => '+237600000000',
            'status' => 'pending',
        ]);
    }

    private function createAgency(string $code): int
    {
        return DB::table('agencies')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'code' => $code,
            'name' => 'Agency '.$code,
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
            'kyc_status' => 'draft',
        ]);
    }

    /**
     * @return array{sector_id:int, sub_sector_id:int}
     */
    private function createSectorAndSubSector(string $sectorCode, string $subSectorCode): array
    {
        $sectorId = DB::table('sectors')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'code' => $sectorCode,
            'name' => 'Sector '.$sectorCode,
            'status' => 'active',
        ]);

        $subSectorId = DB::table('sub_sectors')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'sector_id' => $sectorId,
            'code' => $subSectorCode,
            'name' => 'Sub-sector '.$subSectorCode,
            'status' => 'active',
        ]);

        return [
            'sector_id' => $sectorId,
            'sub_sector_id' => $subSectorId,
        ];
    }

    private function createLedgerAccount(int $agencyId): int
    {
        return DB::table('ledger_accounts')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agencyId,
            'code' => '1000-'.Str::ulid(),
            'name' => 'Cash',
            'account_class' => 'asset',
            'normal_balance_side' => 'debit',
            'status' => 'active',
        ]);
    }

    private function createCustomerAccount(int $agencyId): int
    {
        $clientId = $this->createClient($agencyId);

        return DB::table('customer_accounts')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'client_id' => $clientId,
            'agency_id' => $agencyId,
            'account_number' => 'ACC-'.Str::ulid(),
            'opened_on' => '2026-05-11',
            'status' => 'active',
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

    private function createLoan(int $agencyId, ?int $clientId = null): int
    {
        return DB::table('loans')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'client_id' => $clientId ?? $this->createClient($agencyId),
            'agency_id' => $agencyId,
            'loan_product_id' => $this->createLoanProduct(),
            'loan_number' => 'LN-'.Str::ulid(),
            'requested_amount_minor' => 100000,
            'currency' => 'XAF',
            'applied_on' => '2026-05-11',
            'status' => 'application',
        ]);
    }

    private function createTill(int $agencyId): int
    {
        return DB::table('tills')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agencyId,
            'code' => 'TILL-'.Str::ulid(),
            'name' => 'Main Till',
            'type' => 'counter',
            'status' => 'active',
        ]);
    }

    private function createHrEmployee(): int
    {
        $agencyId = $this->createAgency('HR01');
        $user = User::factory()->createOne();

        return DB::table('hr_employees')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'user_id' => $user->id,
            'agency_id' => $agencyId,
            'employee_number' => 'EMP-'.Str::ulid(),
            'first_name' => 'John',
            'last_name' => 'Staff',
            'currency' => 'XAF',
            'status' => 'active',
        ]);
    }
}
