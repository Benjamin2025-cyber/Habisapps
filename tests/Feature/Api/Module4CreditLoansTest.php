<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\BatchProcedure;
use App\Models\BatchRun;
use App\Models\ClientGuarantor;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\Loan;
use App\Models\LoanApproval;
use App\Models\LoanProduct;
use App\Models\ReportDefinition;
use App\Models\StaffAgencyAssignment;
use App\Models\User;
use App\Support\Finance\FormulaPolicyKey;
use App\Support\Finance\LoanProductFormulaPolicySnapshotter;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class Module4CreditLoansTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_platform_admin_can_manage_loan_products(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agencyId = $this->createAgency('CR01');
        $ledger = $this->createLedgerAccount($agencyId);

        $create = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/loan-products', [
                'ledger_account_public_id' => $ledger['public_id'],
                'code' => 'LP-SME',
                'name' => 'SME Loan',
                'min_amount_minor' => 100000,
                'max_amount_minor' => 1000000,
                'min_term_count' => 3,
                'max_term_count' => 12,
                'term_unit' => LoanProduct::TERM_UNIT_MONTH,
                'due_date_day' => 15,
                'interest_rate' => '2.500000',
                'tax_rate' => '19.250000',
                'insurance_rate' => '2.000000',
                'fee_amount_minor' => 3000,
                'guarantee_deposit_type' => 'percentage',
                'guarantee_deposit_value' => '10.000000',
                'allowed_repayment_frequencies' => ['monthly'],
                'requires_guarantor' => true,
                'rules' => ['source' => 'stakeholder_formula_response'],
            ]);

        $this->assertJsonSuccess($create, 201);
        $productPublicId = $this->requireStringJsonPath($create, 'data.public_id');
        $create->assertJsonPath('data.code', 'LP-SME');
        $create->assertJsonPath('data.ledger_account_public_id', $ledger['public_id']);
        $create->assertJsonPath('data.max_amount_minor', 1000000);
        $create->assertJsonPath('data.due_date_day', 15);
        $create->assertJsonMissing(['id' => 1]);

        $update = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->patchJson('/api/v1/loan-products/'.$productPublicId, [
                'name' => 'SME Loan Updated',
                'max_amount_minor' => 1200000,
                'status' => LoanProduct::STATUS_INACTIVE,
            ]);

        $this->assertJsonSuccess($update);
        $update->assertJsonPath('data.name', 'SME Loan Updated');
        $update->assertJsonPath('data.status', LoanProduct::STATUS_INACTIVE);

        $list = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->getJson('/api/v1/loan-products?status='.LoanProduct::STATUS_INACTIVE);
        $this->assertJsonSuccess($list);
        $list->assertJsonPath('data.loan_products.0.public_id', $productPublicId);

        $search = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->getJson('/api/v1/loan-products?search=SME');
        $this->assertJsonSuccess($search);
        $search->assertJsonPath('data.loan_products.0.public_id', $productPublicId);

        $archive = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->deleteJson('/api/v1/loan-products/'.$productPublicId);
        $this->assertJsonSuccess($archive);

        $this->assertDatabaseHas('loan_products', [
            'public_id' => $productPublicId,
            'status' => LoanProduct::STATUS_ARCHIVED,
        ]);
    }

    public function test_loan_product_validation_and_authorization_fail_closed(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $reader = $this->createUserWithRole('user-admin');
        $agencyId = $this->createAgency('CR02');
        $inactiveLedger = $this->createLedgerAccount($agencyId, LedgerAccount::STATUS_INACTIVE);

        $invalidRange = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/loan-products', [
                'code' => 'LP-BAD-RANGE',
                'name' => 'Bad Range',
                'min_amount_minor' => 500000,
                'max_amount_minor' => 100000,
                'due_date_day' => 32,
            ]);
        $invalidRange->assertStatus(422);
        $invalidRange->assertJsonValidationErrors(['max_amount_minor', 'due_date_day']);

        $inactiveLedgerResponse = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/loan-products', [
                'ledger_account_public_id' => $inactiveLedger['public_id'],
                'code' => 'LP-INACTIVE-LEDGER',
                'name' => 'Inactive Ledger',
            ]);
        $inactiveLedgerResponse->assertStatus(422);
        $inactiveLedgerResponse->assertJsonValidationErrors(['ledger_account_public_id']);

        $forbidden = $this->withApiHeaders()
            ->actingAsSanctum($reader)
            ->postJson('/api/v1/loan-products', [
                'code' => 'LP-FORBIDDEN',
                'name' => 'Forbidden',
            ]);
        $forbidden->assertForbidden();
    }

    public function test_loan_product_penalty_descriptor_fields_reject_typos(): void
    {
        $actor = $this->createUserWithRole('platform-admin');

        $typo = $this->withApiHeaders()->actingAsSanctum($actor)
            ->postJson('/api/v1/loan-products', [
                'code' => 'LP-PEN-TYPO',
                'name' => 'Penalty Typo Product',
                'penalty_formula_type' => 'flate_rate',
                'penalty_formula_base' => 'principel',
                'penalty_value_type' => 'percent',
            ]);
        $typo->assertStatus(422);
        $typo->assertJsonValidationErrors(['penalty_formula_type', 'penalty_formula_base', 'penalty_value_type']);

        $valid = $this->withApiHeaders()->actingAsSanctum($actor)
            ->postJson('/api/v1/loan-products', [
                'code' => 'LP-PEN-OK',
                'name' => 'Penalty Valid Product',
                'penalty_formula_type' => 'flat_rate',
                'penalty_formula_base' => 'principal',
                'penalty_value_type' => 'percentage',
                'penalty_value' => '2.5',
            ]);
        $this->assertJsonSuccess($valid, 201);
    }

    public function test_loan_product_incomplete_penalty_combinations_fail_validation(): void
    {
        $actor = $this->createUserWithRole('platform-admin');

        // Percentage value type with no value and no base.
        $missingParts = $this->withApiHeaders()->actingAsSanctum($actor)
            ->postJson('/api/v1/loan-products', [
                'code' => 'LP-PEN-INC1',
                'name' => 'Incomplete Penalty 1',
                'penalty_value_type' => 'percentage',
            ]);
        $missingParts->assertStatus(422);
        $missingParts->assertJsonValidationErrors(['penalty_value', 'penalty_formula_base']);

        // A value supplied with no value type cannot be interpreted.
        $missingType = $this->withApiHeaders()->actingAsSanctum($actor)
            ->postJson('/api/v1/loan-products', [
                'code' => 'LP-PEN-INC2',
                'name' => 'Incomplete Penalty 2',
                'penalty_value' => '500',
            ]);
        $missingType->assertStatus(422);
        $missingType->assertJsonValidationErrors(['penalty_value_type']);

        // Amount value type cannot pair with a rate-based formula type.
        $mismatch = $this->withApiHeaders()->actingAsSanctum($actor)
            ->postJson('/api/v1/loan-products', [
                'code' => 'LP-PEN-INC3',
                'name' => 'Incomplete Penalty 3',
                'penalty_value_type' => 'amount',
                'penalty_value' => '5000',
                'penalty_formula_type' => 'flat_rate',
            ]);
        $mismatch->assertStatus(422);
        $mismatch->assertJsonValidationErrors(['penalty_formula_type']);

        // A complete fixed-amount combination is accepted.
        $valid = $this->withApiHeaders()->actingAsSanctum($actor)
            ->postJson('/api/v1/loan-products', [
                'code' => 'LP-PEN-INC-OK',
                'name' => 'Complete Fixed Penalty',
                'penalty_value_type' => 'amount',
                'penalty_value' => '5000',
                'penalty_formula_type' => 'fixed',
            ]);
        $this->assertJsonSuccess($valid, 201);
    }

    public function test_unrelated_update_of_legacy_partial_penalty_product_is_not_blocked(): void
    {
        $actor = $this->createUserWithRole('platform-admin');

        // Simulate a product persisted before the penalty-coherence rules, with
        // a stray penalty field but no value type/value.
        $product = LoanProduct::query()->create([
            'public_id' => (string) Str::ulid(),
            'code' => 'LP-LEGACY-PEN',
            'name' => 'Legacy Penalty Product',
            'status' => LoanProduct::STATUS_ACTIVE,
            'penalty_formula_type' => 'fixed',
        ]);

        // A PATCH that does not touch any penalty field must succeed.
        $update = $this->withApiHeaders()->actingAsSanctum($actor)
            ->patchJson('/api/v1/loan-products/'.$product->public_id, [
                'name' => 'Legacy Penalty Product Renamed',
            ]);
        $this->assertJsonSuccess($update);
        $update->assertJsonPath('data.name', 'Legacy Penalty Product Renamed');

        // But submitting a penalty field re-engages coherence validation.
        $incomplete = $this->withApiHeaders()->actingAsSanctum($actor)
            ->patchJson('/api/v1/loan-products/'.$product->public_id, [
                'penalty_value_type' => 'percentage',
            ]);
        $incomplete->assertStatus(422);
        $incomplete->assertJsonValidationErrors(['penalty_value', 'penalty_formula_base']);
    }

    public function test_formula_policy_catalog_exposes_keys_approval_and_product_fields(): void
    {
        $actor = $this->createUserWithRole('platform-admin');

        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->getJson('/api/v1/formula-policies');
        $this->assertJsonSuccess($response);
        $response->assertJsonPath('meta.pagination.current_page', 1);

        // Every enum case is present.
        $response->assertJsonCount(count(FormulaPolicyKey::cases()), 'data.formula_policies');
        foreach (FormulaPolicyKey::cases() as $case) {
            $response->assertJsonFragment(['key' => $case->value]);
        }

        // Approval status mirrors config: penalties_and_arrears is unapproved.
        $response->assertJsonFragment(['key' => FormulaPolicyKey::PenaltiesAndArrears->value, 'approved' => false]);
        $response->assertJsonFragment(['key' => FormulaPolicyKey::XafRounding->value, 'approved' => true]);

        // The catalog's product-field mapping is the same source used by the
        // snapshotter/validation: the penalty policy maps to penalty_policy_key.
        $response->assertJsonFragment(['key' => FormulaPolicyKey::PenaltiesAndArrears->value, 'product_fields' => ['penalty_policy_key']]);
        $response->assertJsonFragment(['key' => FormulaPolicyKey::LoanInterestMethod->value, 'product_fields' => ['interest_policy_key']]);

        $search = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->getJson('/api/v1/formula-policies?search=rounding');
        $this->assertJsonSuccess($search);
        $search->assertJsonPath('meta.pagination.total', 1);
        $search->assertJsonPath('data.formula_policies.0.key', FormulaPolicyKey::XafRounding->value);
    }

    public function test_loan_product_formula_policies_fail_closed_until_approved_and_snapshot_to_loan(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agencyId = $this->createAgency('CR03');

        $blocked = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/loan-products', [
                'code' => 'LP-POLICY-BLOCKED',
                'name' => 'Blocked Policy Product',
                'penalty_policy_key' => FormulaPolicyKey::PenaltiesAndArrears->value,
            ]);
        $blocked->assertStatus(422);
        $blocked->assertJsonValidationErrors(['penalty_policy_key']);

        config([
            'formulas.policies.xaf_rounding.approved' => true,
            'formulas.policies.xaf_rounding.owner' => 'Finance',
            'formulas.policies.xaf_rounding.approved_at' => '2026-05-12',
            'formulas.policies.loan_interest_method.approved' => true,
            'formulas.policies.loan_interest_method.owner' => 'Credit',
            'formulas.policies.loan_interest_method.approved_at' => '2026-05-12',
            'formulas.policies.loan_installment_amount.approved' => true,
            'formulas.policies.loan_installment_amount.owner' => 'Credit',
            'formulas.policies.loan_installment_amount.approved_at' => '2026-05-12',
            'formulas.policies.repayment_allocation_order.approved' => true,
            'formulas.policies.repayment_allocation_order.owner' => 'Credit',
            'formulas.policies.repayment_allocation_order.approved_at' => '2026-05-12',
            'formulas.policies.fees_taxes_insurance.approved' => true,
            'formulas.policies.fees_taxes_insurance.owner' => 'Finance',
            'formulas.policies.fees_taxes_insurance.approved_at' => '2026-05-12',
            'formulas.policies.penalties_and_arrears.approved' => true,
            'formulas.policies.penalties_and_arrears.owner' => 'Credit',
            'formulas.policies.penalties_and_arrears.approved_at' => '2026-05-12',
            'formulas.policies.portfolio_reporting_metrics.approved' => true,
            'formulas.policies.portfolio_reporting_metrics.owner' => 'Risk',
            'formulas.policies.portfolio_reporting_metrics.approved_at' => '2026-05-12',
        ]);

        $create = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/loan-products', [
                'code' => 'LP-POLICY-APPROVED',
                'name' => 'Approved Policy Product',
                'interest_policy_key' => FormulaPolicyKey::LoanInterestMethod->value,
                'fee_policy_key' => FormulaPolicyKey::FeesTaxesInsurance->value,
                'tax_policy_key' => FormulaPolicyKey::FeesTaxesInsurance->value,
                'insurance_policy_key' => FormulaPolicyKey::FeesTaxesInsurance->value,
                'guarantee_deposit_policy_key' => FormulaPolicyKey::FeesTaxesInsurance->value,
                'penalty_policy_key' => FormulaPolicyKey::PenaltiesAndArrears->value,
                'repayment_allocation_policy_key' => FormulaPolicyKey::RepaymentAllocationOrder->value,
                'rules' => [
                    'formula_policies' => [
                        'rounding_policy_key' => FormulaPolicyKey::XafRounding->value,
                        'schedule_policy_key' => FormulaPolicyKey::LoanInstallmentAmount->value,
                        'reporting_policy_key' => FormulaPolicyKey::PortfolioReportingMetrics->value,
                    ],
                ],
            ]);
        $this->assertJsonSuccess($create, 201);

        $product = LoanProduct::query()
            ->where('public_id', $this->requireStringJsonPath($create, 'data.public_id'))
            ->firstOrFail();
        $loan = Loan::query()->create([
            'public_id' => (string) Str::ulid(),
            'client_id' => $this->createClient($agencyId),
            'agency_id' => $agencyId,
            'loan_product_id' => $product->id,
            'loan_number' => 'LN-'.Str::ulid(),
            'requested_amount_minor' => 100000,
            'currency' => 'XAF',
            'applied_on' => now()->toDateString(),
            'status' => Loan::STATUS_APPLICATION,
        ]);

        app(LoanProductFormulaPolicySnapshotter::class)->applyToLoan($loan, $product)->save();

        $rawSnapshot = DB::table('loans')->where('id', $loan->id)->value('formula_policy_snapshot');
        self::assertIsString($rawSnapshot);
        $snapshot = json_decode($rawSnapshot, true);
        self::assertIsArray($snapshot);
        self::assertSame('LP-POLICY-APPROVED', $snapshot['loan_product_code']);
        $formulaPolicies = $snapshot['formula_policies'] ?? null;
        self::assertIsArray($formulaPolicies);
        $interestPolicy = $formulaPolicies['interest_policy_key'] ?? null;
        $schedulePolicy = $formulaPolicies['rules.formula_policies.schedule_policy_key'] ?? null;
        $reportingPolicy = $formulaPolicies['rules.formula_policies.reporting_policy_key'] ?? null;
        self::assertIsArray($interestPolicy);
        self::assertIsArray($schedulePolicy);
        self::assertIsArray($reportingPolicy);
        self::assertSame(FormulaPolicyKey::LoanInterestMethod->value, $interestPolicy['policy_key']);
        self::assertSame(FormulaPolicyKey::LoanInstallmentAmount->value, $schedulePolicy['policy_key']);
        self::assertSame('Risk', $reportingPolicy['owner']);
    }

    public function test_loan_creation_from_unapproved_formula_policy_product_returns_422_naming_policy(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agencyId = $this->createAgency('CR-POLICY-1');
        $client = $this->createClientRecord($agencyId, 'verified');

        // Simulate a product that references an unapproved policy
        // (penalties_and_arrears is approved=false in config by default), as
        // could exist from before product-level gating was added. The product
        // also references an approved interest policy so the failing-policy
        // reporting is exercised against a multi-policy product.
        $product = $this->createLoanProduct($agencyId, [
            'interest_policy_key' => FormulaPolicyKey::LoanInterestMethod->value,
            'penalty_policy_key' => FormulaPolicyKey::PenaltiesAndArrears->value,
        ]);

        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/loans', [
                'client_public_id' => $client['public_id'],
                'loan_product_public_id' => $product->public_id,
                'requested_amount_minor' => 250000,
            ]);

        // Controlled 422, not a 500, and the error identifies the exact field
        // and the failing policy key (penalties_and_arrears), not the first
        // configured policy.
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['penalty_policy_key']);
        $message = $this->requireStringJsonPath($response, 'errors.penalty_policy_key.0');
        self::assertStringContainsString(FormulaPolicyKey::PenaltiesAndArrears->value, $message);
        self::assertStringNotContainsString(FormulaPolicyKey::LoanInterestMethod->value, $message);
    }

    public function test_loan_creation_from_incomplete_legacy_penalty_product_returns_422(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agencyId = $this->createAgency('CR-PEN-LEGACY');
        $client = $this->createClientRecord($agencyId, 'verified');

        // Simulate a product persisted before the penalty coherence rules, or
        // mutated directly outside the loan-product request layer. Product
        // request validation alone cannot protect loan creation from this state.
        $product = $this->createLoanProduct($agencyId, [
            'penalty_formula_type' => 'fixed',
        ]);

        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/loans', [
                'client_public_id' => $client['public_id'],
                'loan_product_public_id' => $product->public_id,
                'requested_amount_minor' => 250000,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['penalty_value_type', 'penalty_value']);
        self::assertSame(0, Loan::query()->where('loan_product_id', $product->id)->getQuery()->count());
    }

    public function test_platform_admin_can_manage_loan_applications_with_scope_and_kyc_rules(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agencyId = $this->createAgency('CR04');
        $client = $this->createClientRecord($agencyId, 'verified');
        $draftClient = $this->createClientRecord($agencyId, 'draft');
        $product = $this->createLoanProduct($agencyId);
        $account = $this->createCustomerAccount($agencyId, $client['id']);
        $agent = $this->createUserWithRole('loan-officer');
        $agent->givePermissionTo('loans.view', 'loans.create', 'loans.update');
        $this->assignStaffToAgency($agent, $agencyId, 'loan-officer');

        $create = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/loans', [
                'client_public_id' => $client['public_id'],
                'loan_product_public_id' => $product->public_id,
                'credit_agent_public_id' => $agent->public_id,
                'transfer_account_public_id' => $account['public_id'],
                'requested_amount_minor' => 250000,
                'currency' => 'XAF',
                'purpose' => 'Working capital',
                'number_of_installments' => 6,
            ]);
        $this->assertJsonSuccess($create, 201);
        $loanPublicId = $this->requireStringJsonPath($create, 'data.public_id');
        $create->assertJsonPath('data.status', Loan::STATUS_APPLICATION);
        $create->assertJsonPath('data.client_public_id', $client['public_id']);
        $create->assertJsonPath('data.loan_product_public_id', $product->public_id);
        $create->assertJsonPath('data.transfer_account_public_id', $account['public_id']);
        $create->assertJsonPath('data.formula_policy_snapshot.loan_product_code', $product->code);
        $create->assertJsonMissing(['id' => 1]);

        $update = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->patchJson('/api/v1/loans/'.$loanPublicId, [
                'requested_amount_minor' => 300000,
                'purpose' => 'Working capital updated',
            ]);
        $this->assertJsonSuccess($update);
        $update->assertJsonPath('data.requested_amount_minor', 300000);

        $list = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->getJson('/api/v1/loans?status='.Loan::STATUS_APPLICATION);
        $this->assertJsonSuccess($list);
        $list->assertJsonPath('data.loans.0.public_id', $loanPublicId);

        $blockedKyc = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/loans', [
                'client_public_id' => $draftClient['public_id'],
                'loan_product_public_id' => $product->public_id,
                'requested_amount_minor' => 250000,
            ]);
        $blockedKyc->assertStatus(422);
        $blockedKyc->assertJsonValidationErrors(['client_public_id']);

        $blockedAmount = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/loans', [
                'client_public_id' => $client['public_id'],
                'loan_product_public_id' => $product->public_id,
                'requested_amount_minor' => 50000,
            ]);
        $blockedAmount->assertStatus(422);
        $blockedAmount->assertJsonValidationErrors(['requested_amount_minor']);

        $reader = $this->createUserWithRole('user-admin');
        $forbidden = $this->withApiHeaders()
            ->actingAsSanctum($reader)
            ->postJson('/api/v1/loans', [
                'client_public_id' => $client['public_id'],
                'loan_product_public_id' => $product->public_id,
                'requested_amount_minor' => 250000,
            ]);
        $forbidden->assertForbidden();

        $loanOfficerCreate = $this->withApiHeaders()
            ->actingAsSanctum($agent)
            ->postJson('/api/v1/loans', [
                'client_public_id' => $client['public_id'],
                'loan_product_public_id' => $product->public_id,
                'requested_amount_minor' => 250000,
            ]);
        $this->assertJsonSuccess($loanOfficerCreate, 201);

        $otherAgencyId = $this->createAgency('CR05');
        $otherAgencyClient = $this->createClientRecord($otherAgencyId, 'verified');
        $crossAgency = $this->withApiHeaders()
            ->actingAsSanctum($agent)
            ->postJson('/api/v1/loans', [
                'client_public_id' => $otherAgencyClient['public_id'],
                'loan_product_public_id' => $product->public_id,
                'requested_amount_minor' => 250000,
            ]);
        $crossAgency->assertForbidden();
    }

    public function test_linked_account_update_rejects_empty_and_unknown_key_payloads(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agencyId = $this->createAgency('CR-ACCT-NOOP');
        $client = $this->createClientRecord($agencyId, 'verified');
        $product = $this->createLoanProduct($agencyId);
        $recoveryAccount = $this->createCustomerAccount($agencyId, $client['id']);
        $loan = $this->createLoanApplication($agencyId, $client['id'], $product->id, 300000);
        $loan->forceFill(['status' => Loan::STATUS_DISBURSED])->save();

        // Empty payload changes nothing -> 422, not a misleading success.
        $empty = $this->withApiHeaders()->actingAsSanctum($actor)
            ->patchJson('/api/v1/loans/'.$loan->public_id.'/linked-accounts', []);
        $empty->assertStatus(422);

        // A typo'd / unrecognized key is rejected instead of silently no-op.
        $unknownKey = $this->withApiHeaders()->actingAsSanctum($actor)
            ->patchJson('/api/v1/loans/'.$loan->public_id.'/linked-accounts', [
                'recovery_account_id' => $recoveryAccount['public_id'],
            ]);
        $unknownKey->assertStatus(422);

        // Unknown keys are rejected even when a valid key is also present,
        // so frontend typos cannot be masked by a partial valid update.
        $mixedUnknown = $this->withApiHeaders()->actingAsSanctum($actor)
            ->patchJson('/api/v1/loans/'.$loan->public_id.'/linked-accounts', [
                'recovery_account_public_id' => $recoveryAccount['public_id'],
                'recovery_account_id' => $recoveryAccount['public_id'],
            ]);
        $mixedUnknown->assertStatus(422);

        // A valid update lists the exact changed fields in the response.
        $valid = $this->withApiHeaders()->actingAsSanctum($actor)
            ->patchJson('/api/v1/loans/'.$loan->public_id.'/linked-accounts', [
                'recovery_account_public_id' => $recoveryAccount['public_id'],
            ]);
        $this->assertJsonSuccess($valid);
        $valid->assertJsonPath('meta.changed_fields', ['recovery_account_public_id']);
    }

    public function test_disbursed_loan_linked_accounts_can_be_updated_without_reopening_terms(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agencyId = $this->createAgency('CR-ACCT-1');
        $client = $this->createClientRecord($agencyId, 'verified');
        $product = $this->createLoanProduct($agencyId);
        $recoveryAccount = $this->createCustomerAccount($agencyId, $client['id']);

        $loan = $this->createLoanApplication($agencyId, $client['id'], $product->id, 300000);
        $loan->forceFill(['status' => Loan::STATUS_DISBURSED])->save();

        // Recovery account can be attached after disbursement via the dedicated
        // linked-accounts endpoint.
        $update = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->patchJson('/api/v1/loans/'.$loan->public_id.'/linked-accounts', [
                'recovery_account_public_id' => $recoveryAccount['public_id'],
            ]);
        $this->assertJsonSuccess($update);
        $update->assertJsonPath('data.recovery_account_public_id', $recoveryAccount['public_id']);

        // The recovery workflow reads loan.recovery_account_id, so the column
        // must reflect the new account.
        self::assertSame(
            $recoveryAccount['id'],
            Loan::query()->whereKey($loan->id)->value('recovery_account_id'),
        );

        // Loan terms remain frozen after the application stage: the general
        // update endpoint still rejects non-account edits on a disbursed loan.
        $blockedTerms = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->patchJson('/api/v1/loans/'.$loan->public_id, [
                'requested_amount_minor' => 999999,
            ]);
        $blockedTerms->assertStatus(422);

        // An account from another agency cannot be linked.
        $otherAgencyId = $this->createAgency('CR-ACCT-2');
        $otherClient = $this->createClientRecord($otherAgencyId, 'verified');
        $foreignAccount = $this->createCustomerAccount($otherAgencyId, $otherClient['id']);
        $crossAgency = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->patchJson('/api/v1/loans/'.$loan->public_id.'/linked-accounts', [
                'recovery_account_public_id' => $foreignAccount['public_id'],
            ]);
        $crossAgency->assertStatus(422);
        $crossAgency->assertJsonValidationErrors(['recovery_account_public_id']);

        // Closed loans reject linked-account changes.
        $loan->forceFill(['status' => Loan::STATUS_CLOSED])->save();
        $closed = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->patchJson('/api/v1/loans/'.$loan->public_id.'/linked-accounts', [
                'recovery_account_public_id' => $recoveryAccount['public_id'],
            ]);
        $closed->assertStatus(422);
    }

    public function test_loan_index_filters_by_client_public_id_across_pages(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agencyId = $this->createAgency('CR-FILTER-1');
        $targetClient = $this->createClientRecord($agencyId, 'verified');
        $otherClient = $this->createClientRecord($agencyId, 'verified');
        $product = $this->createLoanProduct($agencyId);

        // Older loan for the target client created first, plus several newer
        // loans for the other client so a naive first-page scan would miss it.
        $targetLoan = $this->createLoanApplication($agencyId, $targetClient['id'], $product->id, 100000);
        for ($i = 0; $i < 5; $i++) {
            $this->createLoanApplication($agencyId, $otherClient['id'], $product->id, 120000 + $i);
        }

        $filtered = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->getJson('/api/v1/loans?filter[client_public_id]='.$targetClient['public_id']);
        $this->assertJsonSuccess($filtered);
        $filtered->assertJsonPath('meta.pagination.total', 1);
        $filtered->assertJsonPath('data.loans.0.public_id', $targetLoan->public_id);
        $filtered->assertJsonPath('data.loans.0.client_public_id', $targetClient['public_id']);

        // Top-level form is also accepted.
        $topLevel = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->getJson('/api/v1/loans?client_public_id='.$targetClient['public_id']);
        $this->assertJsonSuccess($topLevel);
        $topLevel->assertJsonPath('meta.pagination.total', 1);
    }

    public function test_loan_index_client_filter_is_agency_scope_safe(): void
    {
        $agencyId = $this->createAgency('CR-FILTER-2');
        $otherAgencyId = $this->createAgency('CR-FILTER-3');
        $localClient = $this->createClientRecord($agencyId, 'verified');
        $foreignClient = $this->createClientRecord($otherAgencyId, 'verified');
        $product = $this->createLoanProduct($otherAgencyId);
        $foreignLoan = $this->createLoanApplication($otherAgencyId, $foreignClient['id'], $product->id, 100000);

        $agent = $this->createUserWithRole('loan-officer');
        $agent->givePermissionTo('loans.view');
        $this->assignStaffToAgency($agent, $agencyId, 'loan-officer');

        // An agency-scoped actor filtering by a client in another agency must
        // get an empty result, never the foreign client's loans.
        $response = $this->withApiHeaders()
            ->actingAsSanctum($agent)
            ->getJson('/api/v1/loans?filter[client_public_id]='.$foreignClient['public_id']);
        $this->assertJsonSuccess($response);
        $response->assertJsonPath('meta.pagination.total', 0);
        $response->assertJsonMissing(['public_id' => $foreignLoan->public_id]);
    }

    public function test_loan_resource_exposes_outstanding_projection_amounts(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agencyId = $this->createAgency('CR-OUT-1');
        $client = $this->createClientRecord($agencyId, 'verified');
        $product = $this->createLoanProduct($agencyId);

        $withProjections = $this->createLoanApplication($agencyId, $client['id'], $product->id, 500000);
        $withProjections->forceFill([
            'outstanding_principal_minor' => 350000,
            'installment_amount_minor' => 60000,
            'total_unpaid_amount_minor' => 75000,
            'due_amount_minor' => 60000,
            'global_outstanding_amount_minor' => 410000,
            'total_principal_repaid_minor' => 150000,
        ])->save();

        $show = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->getJson('/api/v1/loans/'.$withProjections->public_id);
        $this->assertJsonSuccess($show);
        $show->assertJsonPath('data.outstanding_principal_minor', 350000);
        $show->assertJsonPath('data.installment_amount_minor', 60000);
        $show->assertJsonPath('data.total_unpaid_amount_minor', 75000);
        $show->assertJsonPath('data.due_amount_minor', 60000);
        $show->assertJsonPath('data.global_outstanding_amount_minor', 410000);
        $show->assertJsonPath('data.total_principal_repaid_minor', 150000);

        // A fresh loan with no projections serializes them as null, not as a
        // misleading requested/approved fallback.
        $fresh = $this->createLoanApplication($agencyId, $client['id'], $product->id, 250000);
        $freshShow = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->getJson('/api/v1/loans/'.$fresh->public_id);
        $this->assertJsonSuccess($freshShow);
        $freshShow->assertJsonPath('data.outstanding_principal_minor', null);
        $freshShow->assertJsonPath('data.global_outstanding_amount_minor', null);
    }

    public function test_loan_approvals_and_active_schedule_are_retrievable_by_get(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agencyId = $this->createAgency('CR-GET-1');
        $client = $this->createClientRecord($agencyId, 'verified');
        $product = $this->createLoanProduct($agencyId);
        $loan = $this->createLoanApplication($agencyId, $client['id'], $product->id, 300000);

        $montage = LoanApproval::query()->create([
            'public_id' => (string) Str::ulid(),
            'loan_id' => $loan->id,
            'agency_id' => $agencyId,
            'step' => LoanApproval::STEP_MONTAGE,
            'decision' => LoanApproval::DECISION_APPROVED,
            'acted_by_user_id' => $actor->id,
            'acted_at' => now()->subHour(),
            'comments' => 'Montage approved',
        ]);
        $comptabilite = LoanApproval::query()->create([
            'public_id' => (string) Str::ulid(),
            'loan_id' => $loan->id,
            'agency_id' => $agencyId,
            'step' => LoanApproval::STEP_COMPTABILITE,
            'decision' => LoanApproval::DECISION_PENDING,
            'acted_by_user_id' => null,
            'acted_at' => null,
            'comments' => null,
        ]);

        $approvals = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->getJson('/api/v1/loans/'.$loan->public_id.'/approvals');
        $this->assertJsonSuccess($approvals);
        $approvals->assertJsonPath('data.approvals.0.public_id', $montage->public_id);
        $approvals->assertJsonPath('data.approvals.0.step', 'montage');
        $approvals->assertJsonPath('data.approvals.0.decision', 'approved');
        $approvals->assertJsonPath('data.approvals.0.acted_by_user_public_id', $actor->public_id);
        $approvals->assertJsonPath('data.approvals.1.public_id', $comptabilite->public_id);
        $approvals->assertJsonPath('data.approvals.1.decision', 'pending');

        // No active schedule yet -> 404 explicit empty state.
        $missingSchedule = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->getJson('/api/v1/loans/'.$loan->public_id.'/schedule');
        $missingSchedule->assertStatus(404);

        $this->createActiveSchedule($loan->id, [
            ['due_date' => now()->addMonth()->toDateString(), 'principal_minor' => 50000, 'interest_minor' => 5000, 'fees_minor' => 0, 'insurance_minor' => 0, 'tax_minor' => 0],
            ['due_date' => now()->addMonths(2)->toDateString(), 'principal_minor' => 50000, 'interest_minor' => 4000, 'fees_minor' => 0, 'insurance_minor' => 0, 'tax_minor' => 0],
        ]);

        $schedule = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->getJson('/api/v1/loans/'.$loan->public_id.'/schedule');
        $this->assertJsonSuccess($schedule);
        $schedule->assertJsonPath('data.snapshot.status', 'active');
        $schedule->assertJsonCount(2, 'data.snapshot.lines');
        $schedule->assertJsonPath('data.snapshot.lines.0.installment_number', 1);
        $schedule->assertJsonPath('data.snapshot.lines.0.principal_minor', 50000);

        // Agency scoping mirrors GET /loans/{loan}: a cross-agency actor is denied.
        $otherAgencyId = $this->createAgency('CR-GET-2');
        $foreignAgent = $this->createUserWithRole('loan-officer');
        $foreignAgent->givePermissionTo('loans.view');
        $this->assignStaffToAgency($foreignAgent, $otherAgencyId, 'loan-officer');
        $forbiddenApprovals = $this->withApiHeaders()
            ->actingAsSanctum($foreignAgent)
            ->getJson('/api/v1/loans/'.$loan->public_id.'/approvals');
        $forbiddenApprovals->assertForbidden();
        $forbiddenSchedule = $this->withApiHeaders()
            ->actingAsSanctum($foreignAgent)
            ->getJson('/api/v1/loans/'.$loan->public_id.'/schedule');
        $forbiddenSchedule->assertForbidden();
    }

    public function test_setup_charge_assessment_keeps_loan_assurance_out_of_bancassurance_premium_workflow(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agencyId = $this->createAgency('CR06');
        $client = $this->createClientRecord($agencyId, 'verified');
        $product = $this->createLoanProduct($agencyId, [
            'interest_rate' => '10.000000',
            'tax_rate' => '19.250000',
            'insurance_rate' => '2.000000',
            'guarantee_deposit_type' => 'percentage',
            'guarantee_deposit_value' => '10.000000',
            'rules' => [
                'setup_charges' => [
                    'dossier_fee_rate' => '3.000000',
                    'tax_base' => 'principal_plus_interest',
                    'guarantee_deposit_collection_method' => 'cash',
                ],
            ],
        ]);

        $loan = Loan::query()->create([
            'public_id' => (string) Str::ulid(),
            'client_id' => $client['id'],
            'agency_id' => $agencyId,
            'loan_product_id' => $product->id,
            'loan_number' => 'LN-'.Str::ulid(),
            'requested_amount_minor' => 200000,
            'currency' => 'XAF',
            'applied_on' => now()->toDateString(),
            'status' => Loan::STATUS_APPLICATION,
        ]);

        $assess = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/loans/'.$loan->public_id.'/setup-charges/assess');
        $this->assertJsonSuccess($assess);
        $assess->assertJsonPath('data.loan.dossier_fees_minor', 6000);
        $assess->assertJsonPath('data.loan.dossier_fees_tax_minor', 42350);
        $assess->assertJsonPath('data.loan.guarantee_deposit_amount_minor', 20000);
        $assess->assertJsonPath('data.loan.insurance_amount_minor', 4000);
        $assess->assertJsonPath('data.charges.0.charge_type', 'dossier_fee');
        $assess->assertJsonPath('data.charges.0.assessed_amount_minor', 6000);
        $assess->assertJsonPath('data.charges.0.metadata.non_refundable_after', 'setup_approval');
        $assess->assertJsonPath('data.charges.1.charge_type', 'dossier_fee_tax');
        $assess->assertJsonPath('data.charges.1.base_amount_minor', 220000);
        $assess->assertJsonPath('data.charges.1.assessed_amount_minor', 42350);
        $assess->assertJsonPath('data.charges.1.metadata.tax_base', 'principal_plus_interest');
        $assess->assertJsonPath('data.charges.2.charge_type', 'guarantee_deposit');
        $assess->assertJsonPath('data.charges.2.assessed_amount_minor', 20000);
        $assess->assertJsonPath('data.charges.2.metadata.cannot_settle_unpaid_loans', true);
        $assess->assertJsonPath('data.loan_assurance.amount_minor', 4000);
        $assess->assertJsonPath('data.loan_assurance.managed_as_premium', false);
        self::assertNull($assess->json('data.insurance_premium_assessment'));

        $this->assertDatabaseHas('loan_charge_assessments', [
            'loan_id' => $loan->id,
            'charge_type' => 'dossier_fee',
            'assessed_amount_minor' => 6000,
        ]);
        $this->assertDatabaseMissing('insurance_subscriptions', ['loan_id' => $loan->id]);
        $this->assertDatabaseMissing('insurance_premium_assessments', ['loan_id' => $loan->id]);

        $repeat = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/loans/'.$loan->public_id.'/setup-charges/assess');
        $this->assertJsonSuccess($repeat);
        self::assertSame(3, DB::table('loan_charge_assessments')->where('loan_id', $loan->id)->count());
        self::assertSame(0, DB::table('insurance_premium_assessments')->where('loan_id', $loan->id)->count());

        $feeIncomeLedgerId = $this->createRevenueLedger($agencyId, 'SETUP-FEE');
        $taxPayableLedgerId = $this->createCustomerLiabilityLedger($agencyId, 'SETUP-TAX');
        $guaranteeLiabilityLedgerId = $this->createCustomerLiabilityLedger($agencyId, 'SETUP-GUAR');
        $this->createSetupChargeMappings([
            'dossier_fee' => $feeIncomeLedgerId,
            'dossier_fee_tax' => $taxPayableLedgerId,
            'guarantee_deposit' => $guaranteeLiabilityLedgerId,
        ], 'XAF');
        $collectionLedgerId = $this->createCustomerLiabilityLedger($agencyId, 'SETUP-COLLECT');
        $collectionAccount = $this->createCustomerAccount($agencyId, $client['id'], $collectionLedgerId);
        $this->fundCustomerAccount($agencyId, $collectionAccount['id'], $collectionLedgerId, 52350);
        $cashLedger = $this->createLedgerAccount($agencyId);
        $session = $this->createOpenTellerSession($agencyId, $cashLedger['id'], 500000);

        $transferLedger = $this->createLedgerAccount($agencyId);
        $transferAccount = $this->createCustomerAccount($agencyId, $client['id'], $transferLedger['id']);
        $loan->forceFill([
            'status' => Loan::STATUS_APPROVED,
            'approved_principal_minor' => 200000,
            'approved_on' => '2026-05-13',
            'transfer_account_id' => $transferAccount['id'],
        ])->save();

        $unpaidDisbursement = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/loans/'.$loan->public_id.'/disburse', [
                'disbursement_channel' => 'transfer_account',
                'business_date' => '2026-05-13',
            ]);
        $unpaidDisbursement->assertStatus(422);
        $unpaidDisbursement->assertJsonValidationErrors(['disbursement']);

        $chargePublicIds = DB::table('loan_charge_assessments')
            ->where('loan_id', $loan->id)
            ->pluck('public_id', 'charge_type');
        foreach (['dossier_fee', 'dossier_fee_tax'] as $chargeType) {
            $chargePublicId = $chargePublicIds->get($chargeType);
            self::assertIsString($chargePublicId);

            $collection = $this->withApiHeaders()
                ->actingAsSanctum($actor)
                ->postJson('/api/v1/loans/'.$loan->public_id.'/setup-charges/'.$chargePublicId.'/collect', [
                    'customer_account_public_id' => $collectionAccount['public_id'],
                    'paid_on' => '2026-05-13',
                    'idempotency_key' => 'collect-'.$chargeType,
                ]);
            $this->assertJsonSuccess($collection);
            $collection->assertJsonPath('data.charge.status', 'paid');
            $collection->assertJsonPath('data.journal_entry.status', 'posted');
        }

        $guaranteeChargePublicId = $chargePublicIds->get('guarantee_deposit');
        self::assertIsString($guaranteeChargePublicId);
        $cashCollection = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/loans/'.$loan->public_id.'/setup-charges/'.$guaranteeChargePublicId.'/collect', [
                'payment_source' => 'teller_cash',
                'teller_session_public_id' => $session['public_id'],
                'paid_on' => '2026-05-13',
                'idempotency_key' => 'collect-guarantee_deposit',
            ]);
        $this->assertJsonSuccess($cashCollection);
        $cashCollection->assertJsonPath('data.charge.status', 'paid');
        $cashCollection->assertJsonPath('data.journal_entry.status', 'posted');

        $this->assertDatabaseHas('journal_lines', [
            'ledger_account_id' => $feeIncomeLedgerId,
            'credit_minor' => 6000,
        ]);
        $this->assertDatabaseHas('journal_lines', [
            'ledger_account_id' => $taxPayableLedgerId,
            'credit_minor' => 42350,
        ]);
        $this->assertDatabaseHas('journal_lines', [
            'ledger_account_id' => $guaranteeLiabilityLedgerId,
            'credit_minor' => 20000,
        ]);
        $this->assertDatabaseHas('journal_lines', [
            'ledger_account_id' => $cashLedger['id'],
            'debit_minor' => 20000,
        ]);
        $this->assertDatabaseHas('teller_transactions', [
            'teller_session_id' => $session['id'],
            'till_id' => $session['till_id'],
            'loan_id' => $loan->id,
            'transaction_type' => 'cash_deposit',
            'amount_minor' => 20000,
            'status' => 'posted',
            'operation_code' => 'loan_setup_charge_collection',
        ]);
        $this->assertDatabaseHas('journal_lines', [
            'ledger_account_id' => $collectionLedgerId,
            'customer_account_id' => $collectionAccount['id'],
            'debit_minor' => 6000,
        ]);

        $feeChargePublicId = $chargePublicIds->get('dossier_fee');
        self::assertIsString($feeChargePublicId);

        $feeReplay = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/loans/'.$loan->public_id.'/setup-charges/'.$feeChargePublicId.'/collect', [
                'customer_account_public_id' => $collectionAccount['public_id'],
                'paid_on' => '2026-05-13',
                'idempotency_key' => 'collect-dossier_fee',
            ]);
        $this->assertJsonSuccess($feeReplay);
        self::assertSame(1, DB::table('journal_entries')->where('idempotency_key', 'collect-dossier_fee')->count());

        $paidDisbursement = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/loans/'.$loan->public_id.'/disburse', [
                'disbursement_channel' => 'transfer_account',
                'business_date' => '2026-05-13',
            ]);
        $this->assertJsonSuccess($paidDisbursement);
        $paidDisbursement->assertJsonPath('data.loan.status', Loan::STATUS_DISBURSED);
    }

    public function test_direction_can_record_manual_dossier_fee_exception_without_recalculating_formula(): void
    {
        $direction = $this->createUserWithRole('platform-admin');
        $nonDirection = $this->createUserWithRole('user-admin');
        $agencyId = $this->createAgency('CR06B');
        $client = $this->createClientRecord($agencyId, 'verified');
        $transferLedger = $this->createCustomerLiabilityLedger($agencyId, 'DOSS');
        $transferAccount = $this->createCustomerAccount($agencyId, $client['id'], $transferLedger);
        $product = $this->createLoanProduct($agencyId, [
            'rules' => [
                'setup_charges' => [
                    'dossier_fee_rate' => '3.000000',
                ],
            ],
        ]);

        $loan = Loan::query()->create([
            'public_id' => (string) Str::ulid(),
            'client_id' => $client['id'],
            'agency_id' => $agencyId,
            'loan_product_id' => $product->id,
            'loan_number' => 'LN-'.Str::ulid(),
            'requested_amount_minor' => 200000,
            'approved_principal_minor' => 200000,
            'currency' => 'XAF',
            'applied_on' => now()->toDateString(),
            'approved_on' => '2026-05-13',
            'status' => Loan::STATUS_APPROVED,
            'transfer_account_id' => $transferAccount['id'],
        ]);

        $assess = $this->withApiHeaders()
            ->actingAsSanctum($direction)
            ->postJson('/api/v1/loans/'.$loan->public_id.'/setup-charges/assess');
        $this->assertJsonSuccess($assess);
        $chargePublicId = $this->requireStringJsonPath($assess, 'data.charges.0.public_id');

        $forbidden = $this->withApiHeaders()
            ->actingAsSanctum($nonDirection)
            ->postJson('/api/v1/loans/'.$loan->public_id.'/setup-charges/'.$chargePublicId.'/direction-decision', [
                'decision' => 'waive',
                'comments' => 'Exceptional Direction waiver.',
            ]);
        $forbidden->assertForbidden();

        $decision = $this->withApiHeaders()
            ->actingAsSanctum($direction)
            ->postJson('/api/v1/loans/'.$loan->public_id.'/setup-charges/'.$chargePublicId.'/direction-decision', [
                'decision' => 'waive',
                'comments' => 'Exceptional Direction waiver.',
            ]);
        $this->assertJsonSuccess($decision);
        $decision->assertJsonPath('data.charge.status', 'waived_by_direction');
        $decision->assertJsonPath('data.charge.assessed_amount_minor', 6000);
        $decision->assertJsonPath('data.charge.metadata.direction_exception_decision.decision', 'waive');
        $decision->assertJsonPath('data.charge.metadata.direction_exception_decision.manual_decision_only', true);

        $this->assertDatabaseHas('loan_charge_assessments', [
            'loan_id' => $loan->id,
            'charge_type' => 'dossier_fee',
            'assessed_amount_minor' => 6000,
            'status' => 'waived_by_direction',
        ]);

        $disbursement = $this->withApiHeaders()
            ->actingAsSanctum($direction)
            ->postJson('/api/v1/loans/'.$loan->public_id.'/disburse', [
                'disbursement_channel' => 'transfer_account',
                'business_date' => '2026-05-13',
            ]);
        $this->assertJsonSuccess($disbursement);
        $disbursement->assertJsonPath('data.loan.status', Loan::STATUS_DISBURSED);
    }

    public function test_four_step_loan_approval_workflow_blocks_skips_rework_and_direct_status_forgery(): void
    {
        $montage = $this->createUserWithRole('platform-admin');
        $comptabilite = $this->createUserWithRole('platform-admin');
        $controle = $this->createUserWithRole('platform-admin');
        $direction = $this->createUserWithRole('platform-admin');
        $agencyId = $this->createAgency('CR07');
        $client = $this->createClientRecord($agencyId, 'verified');
        $product = $this->createLoanProduct($agencyId);
        $loan = $this->createLoanApplication($agencyId, $client['id'], $product->id, 250000);

        $approvers = [
            'montage' => $montage,
            'comptabilite' => $comptabilite,
            'controle' => $controle,
        ];

        $skipped = $this->withApiHeaders()
            ->actingAsSanctum($direction)
            ->postJson('/api/v1/loans/'.$loan->public_id.'/approvals/direction', [
                'decision' => 'approved',
                'comments' => 'Skipping should fail.',
            ]);
        $skipped->assertStatus(422);
        $skipped->assertJsonValidationErrors(['approval']);

        $forbiddenActor = $this->createUserWithRole('user-admin');
        $forbidden = $this->withApiHeaders()
            ->actingAsSanctum($forbiddenActor)
            ->postJson('/api/v1/loans/'.$loan->public_id.'/approvals/montage', [
                'decision' => 'approved',
            ]);
        $forbidden->assertForbidden();

        foreach ($approvers as $step => $approver) {
            $approval = $this->withApiHeaders()
                ->actingAsSanctum($approver)
                ->postJson('/api/v1/loans/'.$loan->public_id.'/approvals/'.$step, [
                    'decision' => 'approved',
                    'comments' => 'Approved '.$step,
                ]);
            $this->assertJsonSuccess($approval);
            $approval->assertJsonPath('data.approval.step', $step);
            $approval->assertJsonPath('data.approval.decision', 'approved');
            $approval->assertJsonPath('data.loan.status', Loan::STATUS_IN_REVIEW);
        }

        $directionResponse = $this->withApiHeaders()
            ->actingAsSanctum($direction)
            ->postJson('/api/v1/loans/'.$loan->public_id.'/approvals/direction', [
                'decision' => 'approved',
                'comments' => 'Direction approved.',
            ]);
        $this->assertJsonSuccess($directionResponse);
        $directionResponse->assertJsonPath('data.loan.status', Loan::STATUS_APPROVED);
        $directionResponse->assertJsonPath('data.approval.acted_by_user_public_id', $direction->public_id);

        $this->assertDatabaseHas('loan_approvals', [
            'loan_id' => $loan->id,
            'step' => 'direction',
            'decision' => 'approved',
            'acted_by_user_id' => $direction->id,
        ]);
        $this->assertDatabaseHas('loan_status_transitions', [
            'loan_id' => $loan->id,
            'from_status' => Loan::STATUS_IN_REVIEW,
            'to_status' => Loan::STATUS_APPROVED,
            'decision' => 'approved',
        ]);

        $statusForgery = $this->withApiHeaders()
            ->actingAsSanctum($direction)
            ->patchJson('/api/v1/loans/'.$loan->public_id, [
                'status' => Loan::STATUS_APPROVED,
            ]);
        $statusForgery->assertStatus(422);

        $reworkLoan = $this->createLoanApplication($agencyId, $client['id'], $product->id, 300000);
        $returned = $this->withApiHeaders()
            ->actingAsSanctum($montage)
            ->postJson('/api/v1/loans/'.$reworkLoan->public_id.'/approvals/montage', [
                'decision' => 'returned',
                'comments' => 'Missing setup document.',
            ]);
        $this->assertJsonSuccess($returned);
        $returned->assertJsonPath('data.loan.status', Loan::STATUS_APPLICATION);
        $returned->assertJsonPath('data.approval.decision', 'returned');

        $rejectedLoan = $this->createLoanApplication($agencyId, $client['id'], $product->id, 350000);
        $rejected = $this->withApiHeaders()
            ->actingAsSanctum($montage)
            ->postJson('/api/v1/loans/'.$rejectedLoan->public_id.'/approvals/montage', [
                'decision' => 'rejected',
                'comments' => 'Credit policy rejection.',
            ]);
        $this->assertJsonSuccess($rejected);
        $rejected->assertJsonPath('data.loan.status', Loan::STATUS_REJECTED);
        $rejected->assertJsonPath('data.approval.decision', 'rejected');
    }

    public function test_loan_approval_enforces_separation_of_duties_across_stages(): void
    {
        $singleActor = $this->createUserWithRole('platform-admin');
        $secondActor = $this->createUserWithRole('platform-admin');
        $agencyId = $this->createAgency('CR07B');
        $client = $this->createClientRecord($agencyId, 'verified');
        $product = $this->createLoanProduct($agencyId);
        $loan = $this->createLoanApplication($agencyId, $client['id'], $product->id, 250000);

        $montage = $this->withApiHeaders()
            ->actingAsSanctum($singleActor)
            ->postJson('/api/v1/loans/'.$loan->public_id.'/approvals/montage', [
                'decision' => 'approved',
                'comments' => 'Setup approved.',
            ]);
        $this->assertJsonSuccess($montage);

        $sameActorSecondStage = $this->withApiHeaders()
            ->actingAsSanctum($singleActor)
            ->postJson('/api/v1/loans/'.$loan->public_id.'/approvals/comptabilite', [
                'decision' => 'approved',
                'comments' => 'Same user trying to approve second stage.',
            ]);
        $sameActorSecondStage->assertStatus(422);
        $sameActorSecondStage->assertJsonValidationErrors(['approval']);

        $this->assertDatabaseMissing('loan_approvals', [
            'loan_id' => $loan->id,
            'step' => 'comptabilite',
            'decision' => 'approved',
        ]);

        $rejectionBySameActor = $this->withApiHeaders()
            ->actingAsSanctum($singleActor)
            ->postJson('/api/v1/loans/'.$loan->public_id.'/approvals/comptabilite', [
                'decision' => 'rejected',
                'comments' => 'Rejections are not approvals.',
            ]);
        $this->assertJsonSuccess($rejectionBySameActor);

        $reapplyLoan = $this->createLoanApplication($agencyId, $client['id'], $product->id, 260000);
        $reapplyMontage = $this->withApiHeaders()
            ->actingAsSanctum($singleActor)
            ->postJson('/api/v1/loans/'.$reapplyLoan->public_id.'/approvals/montage', [
                'decision' => 'approved',
                'comments' => 'Initial setup approval.',
            ]);
        $this->assertJsonSuccess($reapplyMontage);

        $differentActorSecondStage = $this->withApiHeaders()
            ->actingAsSanctum($secondActor)
            ->postJson('/api/v1/loans/'.$reapplyLoan->public_id.'/approvals/comptabilite', [
                'decision' => 'approved',
                'comments' => 'Different approver progresses workflow.',
            ]);
        $this->assertJsonSuccess($differentActorSecondStage);
    }

    public function test_loan_status_transition_policy_records_history_and_denies_invalid_transitions(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agencyId = $this->createAgency('CR08');
        $client = $this->createClientRecord($agencyId, 'verified');
        $product = $this->createLoanProduct($agencyId);
        $loan = $this->createLoanApplication($agencyId, $client['id'], $product->id, 400000);
        $loan->forceFill([
            'status' => Loan::STATUS_APPROVED,
            'approved_on' => now()->toDateString(),
        ])->save();

        $invalid = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/loans/'.$loan->public_id.'/status-transitions', [
                'to_status' => Loan::STATUS_CLOSED,
                'reason' => 'invalid_skip',
            ]);
        $invalid->assertStatus(422);
        $invalid->assertJsonValidationErrors(['status']);

        $disbursed = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/loans/'.$loan->public_id.'/status-transitions', [
                'to_status' => Loan::STATUS_DISBURSED,
                'reason' => 'disbursement_workflow_completed',
                'notes' => 'Lifecycle transition only.',
            ]);
        $this->assertJsonSuccess($disbursed);
        $disbursed->assertJsonPath('data.loan.status', Loan::STATUS_DISBURSED);
        $disbursed->assertJsonPath('data.transition.from_status', Loan::STATUS_APPROVED);
        $disbursed->assertJsonPath('data.transition.to_status', Loan::STATUS_DISBURSED);

        $active = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/loans/'.$loan->public_id.'/status-transitions', [
                'to_status' => Loan::STATUS_ACTIVE,
                'reason' => 'schedule_started',
            ]);
        $this->assertJsonSuccess($active);
        $active->assertJsonPath('data.loan.status', Loan::STATUS_ACTIVE);

        $rescheduled = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/loans/'.$loan->public_id.'/status-transitions', [
                'to_status' => Loan::STATUS_RESCHEDULED,
                'reason' => 'approved_reschedule',
            ]);
        $this->assertJsonSuccess($rescheduled);
        $rescheduled->assertJsonPath('data.loan.status', Loan::STATUS_RESCHEDULED);

        $closed = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/loans/'.$loan->public_id.'/status-transitions', [
                'to_status' => Loan::STATUS_CLOSED,
                'reason' => 'fully_settled',
            ]);
        $this->assertJsonSuccess($closed);
        $closed->assertJsonPath('data.loan.status', Loan::STATUS_CLOSED);

        self::assertSame(4, DB::table('loan_status_transitions')->where('loan_id', $loan->id)->count());
        $this->assertDatabaseHas('loan_status_transitions', [
            'loan_id' => $loan->id,
            'agency_id' => $agencyId,
            'from_status' => Loan::STATUS_RESCHEDULED,
            'to_status' => Loan::STATUS_CLOSED,
            'decision' => 'transitioned',
            'reason' => 'fully_settled',
        ]);
    }

    public function test_loan_disbursement_posts_accounting_entry_and_is_idempotent(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agencyId = $this->createAgency('CR15');
        $client = $this->createClientRecord($agencyId, 'verified');
        $product = $this->createLoanProduct($agencyId);
        $transferLedger = $this->createLedgerAccount($agencyId);
        $transferAccount = $this->createCustomerAccount($agencyId, $client['id'], $transferLedger['id']);
        $loan = $this->createLoanApplication($agencyId, $client['id'], $product->id, 300000);
        $loan->forceFill([
            'status' => Loan::STATUS_APPROVED,
            'approved_principal_minor' => 300000,
            'approved_on' => '2026-05-13',
            'transfer_account_id' => $transferAccount['id'],
        ])->save();

        $cashBlocked = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/loans/'.$loan->public_id.'/disburse', [
                'disbursement_channel' => 'cash',
            ]);
        $cashBlocked->assertStatus(422);
        $cashBlocked->assertJsonValidationErrors(['disbursement']);

        $posted = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/loans/'.$loan->public_id.'/disburse', [
                'disbursement_channel' => 'transfer_account',
                'business_date' => '2026-05-13',
                'notes' => 'Transfer to client account.',
            ]);
        $this->assertJsonSuccess($posted);
        $posted->assertJsonPath('data.loan.status', Loan::STATUS_DISBURSED);
        $posted->assertJsonPath('data.loan.disbursed_on', '2026-05-13');
        $posted->assertJsonPath('data.disbursement.principal_amount_minor', 300000);
        $posted->assertJsonPath('data.disbursement.transfer_account_public_id', $transferAccount['public_id']);
        $posted->assertJsonPath('data.journal_entry.status', 'posted');
        $posted->assertJsonPath('data.journal_entry.source_type', 'loan_disbursement');
        $posted->assertJsonPath('data.journal_entry.lines.0.debit_minor', 300000);
        $posted->assertJsonPath('data.journal_entry.lines.0.credit_minor', 0);
        $posted->assertJsonPath('data.journal_entry.lines.1.debit_minor', 0);
        $posted->assertJsonPath('data.journal_entry.lines.1.credit_minor', 300000);
        $journalEntryPublicId = $this->requireStringJsonPath($posted, 'data.journal_entry.public_id');
        $disbursementPublicId = $this->requireStringJsonPath($posted, 'data.disbursement.public_id');

        $repeat = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/loans/'.$loan->public_id.'/disburse', [
                'disbursement_channel' => 'transfer_account',
                'business_date' => '2026-05-13',
                'notes' => 'Transfer to client account.',
            ]);
        $this->assertJsonSuccess($repeat);
        $repeat->assertJsonPath('data.disbursement.public_id', $disbursementPublicId);
        $repeat->assertJsonPath('data.journal_entry.public_id', $journalEntryPublicId);

        self::assertSame(1, DB::table('loan_disbursements')->where('loan_id', $loan->id)->count());
        self::assertSame(1, DB::table('journal_entries')->where('source_type', 'loan_disbursement')->where('source_public_id', $loan->public_id)->count());
        self::assertSame(2, DB::table('journal_lines')->where('loan_id', $loan->id)->count());
        $this->assertDatabaseHas('loan_status_transitions', [
            'loan_id' => $loan->id,
            'from_status' => Loan::STATUS_APPROVED,
            'to_status' => Loan::STATUS_DISBURSED,
            'decision' => 'posted',
            'reason' => 'loan_disbursement_posted',
        ]);
    }

    public function test_loan_disbursement_replay_rejects_payload_mismatch(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agencyId = $this->createAgency('CR15X');
        $client = $this->createClientRecord($agencyId, 'verified');
        $product = $this->createLoanProduct($agencyId);
        $transferLedger = $this->createLedgerAccount($agencyId);
        $transferAccount = $this->createCustomerAccount($agencyId, $client['id'], $transferLedger['id']);
        $loan = $this->createLoanApplication($agencyId, $client['id'], $product->id, 300000);
        $loan->forceFill([
            'status' => Loan::STATUS_APPROVED,
            'approved_principal_minor' => 300000,
            'approved_on' => '2026-05-13',
            'transfer_account_id' => $transferAccount['id'],
        ])->save();

        $first = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/loans/'.$loan->public_id.'/disburse', [
                'disbursement_channel' => 'transfer_account',
                'business_date' => '2026-05-13',
            ]);
        $this->assertJsonSuccess($first);
        $disbursementCountBefore = DB::table('loan_disbursements')->where('loan_id', $loan->id)->count();

        $conflictingChannel = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/loans/'.$loan->public_id.'/disburse', [
                'disbursement_channel' => 'cash',
                'business_date' => '2026-05-13',
            ]);
        $conflictingChannel->assertStatus(422);
        $conflictingChannel->assertJsonValidationErrors(['disbursement']);

        $conflictingDate = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/loans/'.$loan->public_id.'/disburse', [
                'disbursement_channel' => 'transfer_account',
                'business_date' => '2026-05-14',
            ]);
        $conflictingDate->assertStatus(422);
        $conflictingDate->assertJsonValidationErrors(['disbursement']);

        // No new disbursement / journal rows should have been created by the rejected replays.
        $disbursementCountAfter = DB::table('loan_disbursements')->where('loan_id', $loan->id)->count();
        self::assertSame($disbursementCountBefore, $disbursementCountAfter);
        self::assertSame(
            1,
            DB::table('journal_entries')->where('source_type', 'loan_disbursement')->where('source_public_id', $loan->public_id)->count(),
        );
    }

    public function test_cash_loan_disbursement_requires_open_teller_session_and_posts_teller_cash_out(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agencyId = $this->createAgency('CR15C');
        $client = $this->createClientRecord($agencyId, 'verified');
        $product = $this->createLoanProduct($agencyId);
        $cashLedger = $this->createLedgerAccount($agencyId);
        $session = $this->createOpenTellerSession($agencyId, $cashLedger['id'], 500000);
        $fractionalLoan = $this->createLoanApplication($agencyId, $client['id'], $product->id, 300050);
        $fractionalLoan->forceFill([
            'status' => Loan::STATUS_APPROVED,
            'approved_principal_minor' => 300050,
            'approved_on' => '2026-05-13',
        ])->save();

        $fractionalDisbursement = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/loans/'.$fractionalLoan->public_id.'/disburse', [
                'disbursement_channel' => 'cash',
                'teller_session_public_id' => $session['public_id'],
                'business_date' => '2026-05-13',
            ]);
        $fractionalDisbursement->assertStatus(422);
        $fractionalDisbursement->assertJsonValidationErrors(['disbursement']);

        $loan = $this->createLoanApplication($agencyId, $client['id'], $product->id, 300000);
        $loan->forceFill([
            'status' => Loan::STATUS_APPROVED,
            'approved_principal_minor' => 300000,
            'approved_on' => '2026-05-13',
        ])->save();

        $posted = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/loans/'.$loan->public_id.'/disburse', [
                'disbursement_channel' => 'cash',
                'teller_session_public_id' => $session['public_id'],
                'business_date' => '2026-05-13',
                'notes' => 'Cash payout to borrower.',
            ]);

        $this->assertJsonSuccess($posted);
        $posted->assertJsonPath('data.loan.status', Loan::STATUS_DISBURSED);
        $posted->assertJsonPath('data.disbursement.disbursement_channel', 'cash');
        $posted->assertJsonPath('data.disbursement.transfer_account_public_id', null);
        $posted->assertJsonPath('data.journal_entry.lines.0.debit_minor', 300000);
        $posted->assertJsonPath('data.journal_entry.lines.1.credit_minor', 300000);

        $this->assertDatabaseHas('teller_transactions', [
            'teller_session_id' => $session['id'],
            'till_id' => $session['till_id'],
            'loan_id' => $loan->id,
            'transaction_type' => 'cash_withdrawal',
            'amount_minor' => 300000,
            'status' => 'posted',
            'operation_code' => 'loan_cash_disbursement',
        ]);
        $this->assertDatabaseHas('loan_disbursements', [
            'loan_id' => $loan->id,
            'transfer_account_id' => null,
            'disbursement_channel' => 'cash',
            'principal_amount_minor' => 300000,
        ]);
    }

    public function test_loan_repayment_allocates_oldest_installment_capital_first_and_retains_overpayment(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agencyId = $this->createAgency('CR16');
        $client = $this->createClientRecord($agencyId, 'verified');
        $product = $this->createLoanProduct($agencyId);
        self::assertIsInt($product->ledger_account_id);
        $interestLedgerId = $this->createRevenueLedger($agencyId, 'REPAY-INT');
        $feeLedgerId = $this->createRevenueLedger($agencyId, 'REPAY-FEE');
        $insuranceLedgerId = $this->createCustomerLiabilityLedger($agencyId, 'REPAY-INS');
        $taxLedgerId = $this->createCustomerLiabilityLedger($agencyId, 'REPAY-TAX');
        $penaltyLedgerId = $this->createRevenueLedger($agencyId, 'REPAY-PEN');
        $this->createRepaymentComponentMappings([
            'principal' => $product->ledger_account_id,
            'interest' => $interestLedgerId,
            'fees' => $feeLedgerId,
            'insurance' => $insuranceLedgerId,
            'tax' => $taxLedgerId,
            'penalty' => $penaltyLedgerId,
        ], 'XAF');
        $accountLedgerId = $this->createCustomerLiabilityLedger($agencyId, 'REPAY-A');
        $repaymentAccount = $this->createCustomerAccount($agencyId, $client['id'], $accountLedgerId);
        $loan = $this->createLoanApplication($agencyId, $client['id'], $product->id, 100000);
        $loan->forceFill([
            'status' => Loan::STATUS_ACTIVE,
            'approved_principal_minor' => 100000,
            'approved_on' => '2026-05-13',
            'disbursed_on' => '2026-05-13',
        ])->save();
        $this->createActiveSchedule($loan->id, [
            ['due_date' => '2026-05-13', 'principal_minor' => 50000, 'interest_minor' => 5000, 'fees_minor' => 1000, 'insurance_minor' => 500, 'tax_minor' => 250],
            ['due_date' => '2026-06-13', 'principal_minor' => 50000, 'interest_minor' => 5000, 'fees_minor' => 1000, 'insurance_minor' => 500, 'tax_minor' => 250],
        ]);

        $insufficientBalance = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/loans/'.$loan->public_id.'/repayments', [
                'customer_account_public_id' => $repaymentAccount['public_id'],
                'amount_minor' => 52000,
                'paid_on' => '2026-05-13',
                'notes' => 'Same-day partial repayment.',
            ]);
        $insufficientBalance->assertStatus(422);
        $insufficientBalance->assertJsonValidationErrors(['repayment']);

        $this->fundCustomerAccount($agencyId, $repaymentAccount['id'], $accountLedgerId, 200000);

        $partial = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/loans/'.$loan->public_id.'/repayments', [
                'customer_account_public_id' => $repaymentAccount['public_id'],
                'amount_minor' => 52000,
                'paid_on' => '2026-05-13',
                'notes' => 'Same-day partial repayment.',
            ]);
        $this->assertJsonSuccess($partial);
        $partial->assertJsonPath('data.repayment.received_amount_minor', 52000);
        $partial->assertJsonPath('data.repayment.allocated_amount_minor', 52000);
        $partial->assertJsonPath('data.repayment.overpayment_retained_minor', 0);
        $partial->assertJsonPath('data.repayment.allocations.0.installment_number', 1);
        $partial->assertJsonPath('data.repayment.allocations.0.component', 'principal');
        $partial->assertJsonPath('data.repayment.allocations.0.amount_minor', 50000);
        $partial->assertJsonPath('data.repayment.allocations.1.component', 'interest');
        $partial->assertJsonPath('data.repayment.allocations.1.amount_minor', 2000);
        $partialRepaymentPublicId = $this->requireStringJsonPath($partial, 'data.repayment.public_id');
        $partialJournalPublicId = $this->requireStringJsonPath($partial, 'data.journal_entry.public_id');
        $partialJournalId = DB::table('journal_entries')->where('public_id', $partialJournalPublicId)->value('id');
        self::assertIsInt($partialJournalId);
        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $partialJournalId,
            'ledger_account_id' => $accountLedgerId,
            'customer_account_id' => $repaymentAccount['id'],
            'debit_minor' => 52000,
            'credit_minor' => 0,
        ]);
        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $partialJournalId,
            'ledger_account_id' => $product->ledger_account_id,
            'debit_minor' => 0,
            'credit_minor' => 50000,
        ]);
        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $partialJournalId,
            'ledger_account_id' => $interestLedgerId,
            'debit_minor' => 0,
            'credit_minor' => 2000,
        ]);

        $partialReplay = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/loans/'.$loan->public_id.'/repayments', [
                'customer_account_public_id' => $repaymentAccount['public_id'],
                'amount_minor' => 52000,
                'paid_on' => '2026-05-13',
                'notes' => 'Same-day partial repayment.',
            ]);
        $this->assertJsonSuccess($partialReplay);
        $partialReplay->assertJsonPath('data.repayment.public_id', $partialRepaymentPublicId);
        self::assertSame(1, DB::table('loan_repayments')->where('loan_id', $loan->id)->where('received_amount_minor', 52000)->count());

        $sameDay = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/loans/'.$loan->public_id.'/repayments', [
                'customer_account_public_id' => $repaymentAccount['public_id'],
                'amount_minor' => 10000,
                'paid_on' => '2026-05-13',
            ]);
        $this->assertJsonSuccess($sameDay);
        $sameDay->assertJsonPath('data.repayment.allocations.0.component', 'interest');
        $sameDay->assertJsonPath('data.repayment.allocations.0.amount_minor', 3000);
        $sameDay->assertJsonPath('data.repayment.allocations.1.component', 'fees');
        $sameDay->assertJsonPath('data.repayment.allocations.1.amount_minor', 1000);
        $sameDay->assertJsonPath('data.repayment.allocations.2.component', 'insurance');
        $sameDay->assertJsonPath('data.repayment.allocations.2.amount_minor', 500);
        $sameDay->assertJsonPath('data.repayment.allocations.3.component', 'tax');
        $sameDay->assertJsonPath('data.repayment.allocations.3.amount_minor', 250);
        $sameDay->assertJsonPath('data.repayment.allocations.4.installment_number', 2);
        $sameDay->assertJsonPath('data.repayment.allocations.4.component', 'principal');
        $sameDay->assertJsonPath('data.repayment.allocations.4.amount_minor', 5250);

        $overpayment = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/loans/'.$loan->public_id.'/repayments', [
                'customer_account_public_id' => $repaymentAccount['public_id'],
                'amount_minor' => 100000,
                'paid_on' => '2026-06-13',
            ]);
        $this->assertJsonSuccess($overpayment);
        $overpayment->assertJsonPath('data.repayment.received_amount_minor', 100000);
        $overpayment->assertJsonPath('data.repayment.allocated_amount_minor', 51500);
        $overpayment->assertJsonPath('data.repayment.overpayment_retained_minor', 48500);
        $overpaymentJournalPublicId = $this->requireStringJsonPath($overpayment, 'data.journal_entry.public_id');
        $overpaymentJournalId = DB::table('journal_entries')->where('public_id', $overpaymentJournalPublicId)->value('id');
        self::assertIsInt($overpaymentJournalId);
        self::assertSame(51500, (int) DB::table('journal_lines')->where('journal_entry_id', $overpaymentJournalId)->sum('debit_minor'));
        self::assertSame(51500, (int) DB::table('journal_lines')->where('journal_entry_id', $overpaymentJournalId)->sum('credit_minor'));

        self::assertSame(3, DB::table('loan_repayments')->where('loan_id', $loan->id)->count());
        self::assertSame(12, DB::table('loan_repayment_allocations')->count());
        $this->assertDatabaseHas('loans', [
            'id' => $loan->id,
            'installments_repaid_count' => 2,
            'last_repayment_date' => '2026-06-13',
        ]);
    }

    public function test_repayment_prioritizes_scheduled_dues_before_old_penalties_then_oldest_penalty(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agencyId = $this->createAgency('CR16P');
        $client = $this->createClientRecord($agencyId, 'verified');
        $product = $this->createLoanProduct($agencyId);
        $accountLedgerId = $this->createCustomerLiabilityLedger($agencyId, 'REPAY-P');
        $repaymentAccount = $this->createCustomerAccount($agencyId, $client['id'], $accountLedgerId);
        $loan = $this->createLoanApplication($agencyId, $client['id'], $product->id, 100000);
        $loan->forceFill([
            'status' => Loan::STATUS_ACTIVE,
            'approved_principal_minor' => 100000,
            'approved_on' => '2026-05-13',
            'disbursed_on' => '2026-05-13',
        ])->save();
        $this->createActiveSchedule($loan->id, [
            ['due_date' => '2026-05-13', 'principal_minor' => 0, 'interest_minor' => 0, 'fees_minor' => 0, 'insurance_minor' => 0, 'tax_minor' => 0, 'penalty_minor' => 6000],
            ['due_date' => '2026-06-13', 'principal_minor' => 50000, 'interest_minor' => 5000, 'fees_minor' => 0, 'insurance_minor' => 0, 'tax_minor' => 0, 'penalty_minor' => 7000],
        ]);
        $this->fundCustomerAccount($agencyId, $repaymentAccount['id'], $accountLedgerId, 100000);

        $currentInstallment = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/loans/'.$loan->public_id.'/repayments', [
                'customer_account_public_id' => $repaymentAccount['public_id'],
                'amount_minor' => 55000,
                'paid_on' => '2026-06-13',
            ]);
        $this->assertJsonSuccess($currentInstallment);
        $currentInstallment->assertJsonPath('data.repayment.allocations.0.installment_number', 2);
        $currentInstallment->assertJsonPath('data.repayment.allocations.0.component', 'principal');
        $currentInstallment->assertJsonPath('data.repayment.allocations.0.amount_minor', 50000);
        $currentInstallment->assertJsonPath('data.repayment.allocations.1.installment_number', 2);
        $currentInstallment->assertJsonPath('data.repayment.allocations.1.component', 'interest');
        $currentInstallment->assertJsonPath('data.repayment.allocations.1.amount_minor', 5000);

        $penalties = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/loans/'.$loan->public_id.'/repayments', [
                'customer_account_public_id' => $repaymentAccount['public_id'],
                'amount_minor' => 13000,
                'paid_on' => '2026-06-13',
            ]);
        $this->assertJsonSuccess($penalties);
        $penalties->assertJsonPath('data.repayment.allocations.0.installment_number', 1);
        $penalties->assertJsonPath('data.repayment.allocations.0.component', 'penalty');
        $penalties->assertJsonPath('data.repayment.allocations.0.amount_minor', 6000);
        $penalties->assertJsonPath('data.repayment.allocations.1.installment_number', 2);
        $penalties->assertJsonPath('data.repayment.allocations.1.component', 'penalty');
        $penalties->assertJsonPath('data.repayment.allocations.1.amount_minor', 7000);
    }

    public function test_arrears_assessment_endpoint_applies_monthly_penalty_rule(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agencyId = $this->createAgency('CR16A');
        $client = $this->createClientRecord($agencyId, 'verified');
        $product = $this->createLoanProduct($agencyId, [
            'penalty_grace_days' => 5,
        ]);
        $loan = $this->createLoanApplication($agencyId, $client['id'], $product->id, 100000);
        $loan->forceFill([
            'status' => Loan::STATUS_ACTIVE,
            'approved_principal_minor' => 100000,
            'approved_on' => '2026-05-13',
            'disbursed_on' => '2026-05-13',
        ])->save();
        $this->createActiveSchedule($loan->id, [
            ['due_date' => '2026-05-01', 'principal_minor' => 50000, 'interest_minor' => 5000, 'fees_minor' => 0, 'insurance_minor' => 0, 'tax_minor' => 0],
        ]);

        config(['formulas.policies.penalties_and_arrears.approved' => false]);
        $blocked = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/loans/'.$loan->public_id.'/arrears/assess', [
                'as_of_date' => '2026-05-07',
            ]);
        $blocked->assertStatus(422);
        $blocked->assertJsonValidationErrors(['penalties_and_arrears']);

        config(['formulas.policies.penalties_and_arrears.approved' => true]);
        $dueDate = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/loans/'.$loan->public_id.'/arrears/assess', [
                'as_of_date' => '2026-05-01',
            ]);
        $this->assertJsonSuccess($dueDate);
        $dueDate->assertJsonPath('data.assessed_penalty_minor', 0);

        $beforeBoundary = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/loans/'.$loan->public_id.'/arrears/assess', [
                'as_of_date' => '2026-05-05',
            ]);
        $this->assertJsonSuccess($beforeBoundary);
        $beforeBoundary->assertJsonPath('data.assessed_penalty_minor', 0);

        $assessed = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/loans/'.$loan->public_id.'/arrears/assess', [
                'as_of_date' => '2026-05-06',
            ]);

        $this->assertJsonSuccess($assessed);
        $assessed->assertJsonPath('data.assessed_penalty_minor', 6100);
        $assessed->assertJsonPath('data.arrears.0.original_due_minor', 55000);
        $assessed->assertJsonPath('data.arrears.0.unpaid_minor', 55000);
        $assessed->assertJsonPath('data.arrears.0.penalty_base_minor', 55000);
        $this->assertDatabaseHas('loan_schedule_lines', [
            'loan_schedule_snapshot_id' => DB::table('loan_schedule_snapshots')->where('loan_id', $loan->id)->value('id'),
            'penalty_minor' => 6100,
            'total_installment_minor' => 61100,
        ]);
        self::assertSame(0, DB::table('journal_entries')->where('source_type', 'loan_penalty_assessment')->count());
        $sameMonthRepeat = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/loans/'.$loan->public_id.'/arrears/assess', [
                'as_of_date' => '2026-05-07',
            ]);
        $this->assertJsonSuccess($sameMonthRepeat);
        $sameMonthRepeat->assertJsonPath('data.assessed_penalty_minor', 0);
        $this->assertDatabaseHas('activity_log', [
            'event' => 'loan.arrears.assessed',
        ]);
    }

    public function test_batch_run_executes_loan_arrears_assessment_for_agency_scope(): void
    {
        config(['formulas.policies.penalties_and_arrears.approved' => true]);

        $actor = $this->createUserWithRole('platform-admin');
        $agencyId = $this->createAgency('CR-B1');
        $otherAgencyId = $this->createAgency('CR-B2');
        $product = $this->createLoanProduct($agencyId, ['penalty_grace_days' => 5]);
        $otherProduct = $this->createLoanProduct($otherAgencyId, ['penalty_grace_days' => 5]);

        $loan = $this->createLoanApplication($agencyId, $this->createClient($agencyId), $product->id, 100000);
        $loan->forceFill([
            'status' => Loan::STATUS_DISBURSED,
            'approved_principal_minor' => 100000,
            'disbursed_on' => '2026-04-03',
        ])->save();
        $this->createActiveSchedule($loan->id, [
            ['due_date' => '2026-05-01', 'principal_minor' => 50000, 'interest_minor' => 5000, 'fees_minor' => 0, 'insurance_minor' => 0, 'tax_minor' => 0],
        ]);

        $otherLoan = $this->createLoanApplication($otherAgencyId, $this->createClient($otherAgencyId), $otherProduct->id, 100000);
        $otherLoan->forceFill([
            'status' => Loan::STATUS_DISBURSED,
            'approved_principal_minor' => 100000,
            'disbursed_on' => '2026-04-03',
        ])->save();
        $this->createActiveSchedule($otherLoan->id, [
            ['due_date' => '2026-05-01', 'principal_minor' => 50000, 'interest_minor' => 5000, 'fees_minor' => 0, 'insurance_minor' => 0, 'tax_minor' => 0],
        ]);

        $procedure = BatchProcedure::query()->create([
            'public_id' => (string) Str::ulid(),
            'code' => 'LOAN_ARREARS_ASSESSMENT',
            'name' => 'Loan Arrears Assessment',
            'schedule_type' => 'daily',
            'status' => BatchProcedure::STATUS_ACTIVE,
        ]);
        $run = BatchRun::query()->create([
            'public_id' => (string) Str::ulid(),
            'batch_procedure_id' => $procedure->id,
            'agency_id' => $agencyId,
            'business_date' => '2026-05-07',
            'status' => BatchRun::STATUS_PENDING,
            'operator_user_id' => $actor->id,
        ]);

        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/batch-runs/'.$run->public_id.'/execute');

        $this->assertJsonSuccess($response);
        $response->assertJsonPath('data.status', BatchRun::STATUS_SUCCEEDED);
        $response->assertJsonPath('data.summary_payload.assessed_loans', 1);
        $response->assertJsonPath('data.summary_payload.loans_with_new_penalties', 1);
        $response->assertJsonPath('data.summary_payload.assessed_penalty_minor', 6100);
        $this->assertDatabaseHas('loan_schedule_lines', [
            'loan_schedule_snapshot_id' => DB::table('loan_schedule_snapshots')->where('loan_id', $loan->id)->value('id'),
            'penalty_minor' => 6100,
        ]);
        $this->assertDatabaseHas('loan_schedule_lines', [
            'loan_schedule_snapshot_id' => DB::table('loan_schedule_snapshots')->where('loan_id', $otherLoan->id)->value('id'),
            'penalty_minor' => 0,
        ]);
    }

    public function test_batch_run_execute_preserves_loan_batch_validation_errors(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agencyId = $this->createAgency('CR-B3');

        $procedure = BatchProcedure::query()->create([
            'public_id' => (string) Str::ulid(),
            'code' => 'LOAN_ARREARS_ASSESSMENT',
            'name' => 'Loan Arrears Assessment',
            'schedule_type' => 'daily',
            'status' => BatchProcedure::STATUS_ACTIVE,
        ]);
        $run = BatchRun::query()->create([
            'public_id' => (string) Str::ulid(),
            'batch_procedure_id' => $procedure->id,
            'agency_id' => $agencyId,
            'business_date' => '2026-05-07',
            'status' => BatchRun::STATUS_SUCCEEDED,
            'operator_user_id' => $actor->id,
        ]);

        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/batch-runs/'.$run->public_id.'/execute');

        $this->assertJsonError($response, 422, 'Only pending or failed batch runs can be executed.');
    }

    public function test_batch_run_fails_closed_when_loan_arrears_formula_policy_is_disabled(): void
    {
        config(['formulas.policies.penalties_and_arrears.approved' => false]);

        $actor = $this->createUserWithRole('platform-admin');
        $agencyId = $this->createAgency('CR-B4');
        $product = $this->createLoanProduct($agencyId, ['penalty_grace_days' => 5]);
        $loan = $this->createLoanApplication($agencyId, $this->createClient($agencyId), $product->id, 100000);
        $loan->forceFill([
            'status' => Loan::STATUS_DISBURSED,
            'approved_principal_minor' => 100000,
            'disbursed_on' => '2026-04-03',
        ])->save();
        $this->createActiveSchedule($loan->id, [
            ['due_date' => '2026-05-01', 'principal_minor' => 50000, 'interest_minor' => 5000, 'fees_minor' => 0, 'insurance_minor' => 0, 'tax_minor' => 0],
        ]);

        $procedure = BatchProcedure::query()->create([
            'public_id' => (string) Str::ulid(),
            'code' => 'LOAN_ARREARS_ASSESSMENT',
            'name' => 'Loan Arrears Assessment',
            'schedule_type' => 'daily',
            'status' => BatchProcedure::STATUS_ACTIVE,
        ]);
        $run = BatchRun::query()->create([
            'public_id' => (string) Str::ulid(),
            'batch_procedure_id' => $procedure->id,
            'agency_id' => $agencyId,
            'business_date' => '2026-05-07',
            'status' => BatchRun::STATUS_PENDING,
            'operator_user_id' => $actor->id,
        ]);

        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/batch-runs/'.$run->public_id.'/execute');

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $message = $response->json('message');
        self::assertIsString($message);
        self::assertStringContainsString('Formula policy [penalties_and_arrears] is not approved', $message);
        self::assertSame(BatchRun::STATUS_FAILED, $run->refresh()->status);
    }

    public function test_delinquency_tracking_records_follow_up_and_enforces_agency_scope(): void
    {
        $agencyId = $this->createAgency('CR-D1');
        $otherAgencyId = $this->createAgency('CR-D2');
        $officer = $this->createUserWithRole('loan-officer');
        $this->assignStaffToAgency($officer, $agencyId, 'loan_officer');

        $product = $this->createLoanProduct($agencyId);
        $client = $this->createClient($agencyId);
        $loan = $this->createLoanApplication($agencyId, $client, $product->id, 100000);
        $loan->forceFill([
            'status' => Loan::STATUS_ACTIVE,
            'approved_principal_minor' => 100000,
            'disbursed_on' => '2026-05-01',
        ])->save();

        $otherProduct = $this->createLoanProduct($otherAgencyId);
        $otherLoan = $this->createLoanApplication($otherAgencyId, $this->createClient($otherAgencyId), $otherProduct->id, 100000);
        $otherLoan->forceFill([
            'status' => Loan::STATUS_ACTIVE,
            'approved_principal_minor' => 100000,
            'disbursed_on' => '2026-05-01',
        ])->save();

        $created = $this->withApiHeaders()
            ->actingAsSanctum($officer)
            ->postJson('/api/v1/loans/'.$loan->public_id.'/delinquency-trackings', [
                'tracking_date' => '2026-06-10',
                'reason_code' => 'missed_visit',
                'appointment_type' => 'field_visit',
                'appointment_date' => '2026-06-15',
                'promised_amount_minor' => 25000,
                'comments' => 'Client promised a partial payment.',
            ]);

        $this->assertJsonSuccess($created, 201);
        $trackingPublicId = $this->requireStringJsonPath($created, 'data.public_id');
        $created->assertJsonPath('data.loan_public_id', $loan->public_id);
        $created->assertJsonPath('data.tracking_date', '2026-06-10');
        $created->assertJsonPath('data.promised_amount_minor', 25000);
        $created->assertJsonPath('data.created_by_user_public_id', $officer->public_id);
        $this->assertDatabaseHas('delinquency_trackings', [
            'public_id' => $trackingPublicId,
            'loan_id' => $loan->id,
            'client_id' => $client,
            'agency_id' => $agencyId,
            'reason_code' => 'missed_visit',
            'promised_amount_minor' => 25000,
        ]);

        $updated = $this->withApiHeaders()
            ->actingAsSanctum($officer)
            ->patchJson('/api/v1/loans/'.$loan->public_id.'/delinquency-trackings/'.$trackingPublicId, [
                'appointment_date' => '2026-06-18',
                'promised_amount_minor' => 30000,
                'comments' => 'Visit postponed by client.',
            ]);

        $this->assertJsonSuccess($updated);
        $updated->assertJsonPath('data.appointment_date', '2026-06-18');
        $updated->assertJsonPath('data.promised_amount_minor', 30000);

        $list = $this->withApiHeaders()
            ->actingAsSanctum($officer)
            ->getJson('/api/v1/loans/'.$loan->public_id.'/delinquency-trackings');
        $this->assertJsonSuccess($list);
        $list->assertJsonPath('data.delinquency_trackings.0.public_id', $trackingPublicId);

        $outsideAgency = $this->withApiHeaders()
            ->actingAsSanctum($officer)
            ->postJson('/api/v1/loans/'.$otherLoan->public_id.'/delinquency-trackings', [
                'tracking_date' => '2026-06-10',
            ]);
        $outsideAgency->assertForbidden();

        $this->assertDatabaseHas('activity_log', [
            'event' => 'loan.delinquency_tracking.created',
        ]);
        $this->assertDatabaseHas('activity_log', [
            'event' => 'loan.delinquency_tracking.updated',
        ]);
    }

    public function test_loan_transfer_reassigns_manager_and_preserves_history_with_agency_scope(): void
    {
        $agencyId = $this->createAgency('CR-T1');
        $otherAgencyId = $this->createAgency('CR-T2');
        $manager = $this->createUserWithRole('agency-manager');
        $initialManager = $this->createUserWithRole('loan-officer');
        $newManager = $this->createUserWithRole('loan-officer');
        $outsideManager = $this->createUserWithRole('loan-officer');
        $outsideActor = $this->createUserWithRole('agency-manager');
        $this->assignStaffToAgency($manager, $agencyId, 'agency_manager');
        $this->assignStaffToAgency($initialManager, $agencyId, 'loan_officer');
        $this->assignStaffToAgency($newManager, $agencyId, 'loan_officer');
        $this->assignStaffToAgency($outsideManager, $otherAgencyId, 'loan_officer');
        $this->assignStaffToAgency($outsideActor, $otherAgencyId, 'agency_manager');

        $product = $this->createLoanProduct($agencyId);
        $loan = $this->createLoanApplication($agencyId, $this->createClient($agencyId), $product->id, 100000);
        $loan->forceFill([
            'status' => Loan::STATUS_ACTIVE,
            'credit_agent_id' => $initialManager->id,
            'approved_principal_minor' => 100000,
            'disbursed_on' => '2026-05-01',
        ])->save();

        $wrongAgencyManager = $this->withApiHeaders()
            ->actingAsSanctum($manager)
            ->postJson('/api/v1/loans/'.$loan->public_id.'/transfers', [
                'new_manager_public_id' => $outsideManager->public_id,
                'transfer_reason' => 'portfolio_rebalancing',
                'transfer_date' => '2026-06-20',
            ]);
        $wrongAgencyManager->assertStatus(422);
        $wrongAgencyManager->assertJsonValidationErrors(['new_manager_public_id']);

        $created = $this->withApiHeaders()
            ->actingAsSanctum($manager)
            ->postJson('/api/v1/loans/'.$loan->public_id.'/transfers', [
                'new_manager_public_id' => $newManager->public_id,
                'transfer_reason' => 'portfolio_rebalancing',
                'transfer_date' => '2026-06-20',
            ]);

        $this->assertJsonSuccess($created, 201);
        $transferPublicId = $this->requireStringJsonPath($created, 'data.transfer.public_id');
        $created->assertJsonPath('data.loan.credit_agent_public_id', $newManager->public_id);
        $created->assertJsonPath('data.transfer.initial_manager_public_id', $initialManager->public_id);
        $created->assertJsonPath('data.transfer.new_manager_public_id', $newManager->public_id);
        $created->assertJsonPath('data.transfer.approved_by_user_public_id', $manager->public_id);
        $this->assertDatabaseHas('loan_transfers', [
            'public_id' => $transferPublicId,
            'loan_id' => $loan->id,
            'agency_id' => $agencyId,
            'initial_manager_id' => $initialManager->id,
            'new_manager_id' => $newManager->id,
            'transfer_reason' => 'portfolio_rebalancing',
        ]);
        $this->assertDatabaseHas('loans', [
            'id' => $loan->id,
            'credit_agent_id' => $newManager->id,
        ]);

        $list = $this->withApiHeaders()
            ->actingAsSanctum($manager)
            ->getJson('/api/v1/loans/'.$loan->public_id.'/transfers');
        $this->assertJsonSuccess($list);
        $list->assertJsonPath('data.loan_transfers.0.public_id', $transferPublicId);

        $outsideAgencyActor = $this->withApiHeaders()
            ->actingAsSanctum($outsideActor)
            ->postJson('/api/v1/loans/'.$loan->public_id.'/transfers', [
                'new_manager_public_id' => $initialManager->public_id,
                'transfer_reason' => 'outside_scope_attempt',
            ]);
        $outsideAgencyActor->assertForbidden();

        $this->assertDatabaseHas('activity_log', [
            'event' => 'loan.transfer.created',
        ]);
    }

    public function test_automated_recovery_debits_recovery_account_then_priority_linked_accounts(): void
    {
        config(['formulas.policies.repayment_allocation_order.approved' => true]);

        $actor = $this->createUserWithRole('platform-admin');
        $agencyId = $this->createAgency('CR-R1');
        $client = $this->createClientRecord($agencyId, 'verified');
        $product = $this->createLoanProduct($agencyId);
        $recoveryLedgerId = $this->createCustomerLiabilityLedger($agencyId, 'REC-A');
        $fallbackLedgerId = $this->createCustomerLiabilityLedger($agencyId, 'REC-B');
        $offsetLedger = $this->createLedgerAccount($agencyId);
        $recoveryAccount = $this->createCustomerAccount($agencyId, $client['id'], $recoveryLedgerId);
        $fallbackAccount = $this->createCustomerAccount($agencyId, $client['id'], $fallbackLedgerId);
        $loan = $this->createLoanApplication($agencyId, $client['id'], $product->id, 100000);
        $loan->forceFill([
            'status' => Loan::STATUS_ACTIVE,
            'approved_principal_minor' => 100000,
            'approved_on' => '2026-05-13',
            'disbursed_on' => '2026-05-13',
            'recovery_account_id' => $recoveryAccount['id'],
        ])->save();
        $this->createActiveSchedule($loan->id, [
            ['due_date' => '2026-05-13', 'principal_minor' => 50000, 'interest_minor' => 5000, 'fees_minor' => 0, 'insurance_minor' => 0, 'tax_minor' => 0],
        ]);
        $this->postCustomerAccountCredit($agencyId, $recoveryAccount['id'], $recoveryLedgerId, $offsetLedger['id'], $actor->id, 30000);
        $this->postCustomerAccountCredit($agencyId, $fallbackAccount['id'], $fallbackLedgerId, $offsetLedger['id'], $actor->id, 30000);
        DB::table('loan_recovery_accounts')->insert([
            'public_id' => (string) Str::ulid(),
            'loan_id' => $loan->id,
            'customer_account_id' => $fallbackAccount['id'],
            'priority' => 1,
            'is_primary' => true,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/loans/'.$loan->public_id.'/recovery-attempts', [
                'requested_amount_minor' => 55000,
                'recovered_on' => '2026-06-30',
            ]);

        $this->assertJsonSuccess($response);
        $response->assertJsonPath('data.requested_amount_minor', 55000);
        $response->assertJsonPath('data.recovered_amount_minor', 55000);
        $response->assertJsonPath('data.remaining_amount_minor', 0);
        $response->assertJsonPath('data.attempts.0.customer_account_public_id', $recoveryAccount['public_id']);
        $response->assertJsonPath('data.attempts.0.recovered_amount_minor', 30000);
        $response->assertJsonPath('data.attempts.1.customer_account_public_id', $fallbackAccount['public_id']);
        $response->assertJsonPath('data.attempts.1.recovered_amount_minor', 25000);
        self::assertSame(2, DB::table('loan_recovery_attempts')->where('loan_id', $loan->id)->where('status', 'succeeded')->count());
        self::assertSame(55000, (int) DB::table('loan_repayments')->where('loan_id', $loan->id)->sum('allocated_amount_minor'));
        $this->assertDatabaseHas('activity_log', [
            'event' => 'loan.recovery.attempted',
        ]);

        $list = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->getJson('/api/v1/loans/'.$loan->public_id.'/recovery-attempts');
        $this->assertJsonSuccess($list);
        $list->assertJsonPath('data.recovery_attempts.0.status', 'succeeded');
    }

    public function test_credit_reporting_generates_portfolio_par_and_collection_metrics(): void
    {
        config([
            'formulas.policies.repayment_allocation_order.approved' => true,
            'formulas.policies.portfolio_reporting_metrics.approved' => true,
        ]);

        $actor = $this->createUserWithRole('platform-admin');
        $agencyId = $this->createAgency('CR-REP');
        $agencyPublicId = DB::table('agencies')->where('id', $agencyId)->value('public_id');
        self::assertIsString($agencyPublicId);
        $client = $this->createClientRecord($agencyId, 'verified');
        $product = $this->createLoanProduct($agencyId);
        $accountLedgerId = $this->createCustomerLiabilityLedger($agencyId, 'REPAY-R');
        $repaymentAccount = $this->createCustomerAccount($agencyId, $client['id'], $accountLedgerId);
        $loan = $this->createLoanApplication($agencyId, $client['id'], $product->id, 100000);
        $loan->forceFill([
            'status' => Loan::STATUS_ACTIVE,
            'approved_principal_minor' => 100000,
            'approved_on' => '2026-05-01',
            'disbursed_on' => '2026-05-01',
        ])->save();
        $this->createActiveSchedule($loan->id, [
            ['due_date' => '2026-05-01', 'principal_minor' => 50000, 'interest_minor' => 5000, 'fees_minor' => 1000, 'insurance_minor' => 500, 'tax_minor' => 250, 'penalty_minor' => 6000],
        ]);
        $writtenOff = $this->createLoanApplication($agencyId, $client['id'], $product->id, 100000);
        $writtenOff->forceFill([
            'status' => Loan::STATUS_WRITTEN_OFF,
            'approved_principal_minor' => 100000,
            'approved_on' => '2026-05-01',
            'disbursed_on' => '2026-05-01',
        ])->save();
        $this->createActiveSchedule($writtenOff->id, [
            ['due_date' => '2026-05-01', 'principal_minor' => 90000, 'interest_minor' => 9000, 'fees_minor' => 0, 'insurance_minor' => 0, 'tax_minor' => 0],
        ]);
        $this->fundCustomerAccount($agencyId, $repaymentAccount['id'], $accountLedgerId, 10000);

        $repayment = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/loans/'.$loan->public_id.'/repayments', [
                'customer_account_public_id' => $repaymentAccount['public_id'],
                'amount_minor' => 10000,
                'paid_on' => '2026-05-20',
            ]);
        $this->assertJsonSuccess($repayment);

        $portfolioDefinition = $this->createReportDefinition('CR_PORT', ReportDefinition::TYPE_CREDIT_PORTFOLIO_OUTSTANDING);
        $parDefinition = $this->createReportDefinition('CR_PAR', ReportDefinition::TYPE_CREDIT_PAR_DELINQUENCY);
        $collectionDefinition = $this->createReportDefinition('CR_COLL', ReportDefinition::TYPE_CREDIT_COLLECTION_PERFORMANCE);

        $portfolio = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/report-runs', [
                'report_definition_public_id' => $portfolioDefinition,
                'agency_public_id' => $agencyPublicId,
                'period_ends_on' => '2026-06-15',
                'currency' => 'XAF',
            ]);
        $this->assertJsonSuccess($portfolio, 201);
        $portfolio->assertJsonPath('data.summary.loan_count', 1);
        $portfolio->assertJsonPath('data.summary.outstanding_minor', 51000);
        $portfolio->assertJsonPath('data.summary.penalty_outstanding_minor', 6000);

        $par = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/report-runs', [
                'report_definition_public_id' => $parDefinition,
                'agency_public_id' => $agencyPublicId,
                'period_ends_on' => '2026-06-15',
                'currency' => 'XAF',
            ]);
        $this->assertJsonSuccess($par, 201);
        $par->assertJsonPath('data.summary.loan_count', 1);
        $par->assertJsonPath('data.summary.par30_outstanding_at_risk_minor', 51000);
        $par->assertJsonPath('data.summary.delinquent_overdue_amount_minor', 51000);

        $collection = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/report-runs', [
                'report_definition_public_id' => $collectionDefinition,
                'agency_public_id' => $agencyPublicId,
                'period_starts_on' => '2026-05-01',
                'period_ends_on' => '2026-05-31',
                'currency' => 'XAF',
            ]);
        $this->assertJsonSuccess($collection, 201);
        $collection->assertJsonPath('data.summary.expected_collection_minor', 61000);
        $collection->assertJsonPath('data.summary.actual_collection_minor', 10000);
        $collection->assertJsonPath('data.summary.collection_gap_minor', 51000);
    }

    public function test_early_repayment_enforces_minimum_default_future_interest_and_direction_override(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agencyId = $this->createAgency('CR17');
        $client = $this->createClientRecord($agencyId, 'verified');
        $product = $this->createLoanProduct($agencyId, [
            'insurance_rate' => '2.000000',
            'rules' => [
                'early_repayment' => [
                    'minimum_months_after_disbursement' => 3,
                ],
            ],
        ]);
        $accountLedgerId = $this->createCustomerLiabilityLedger($agencyId, 'REPAY-E');
        $repaymentAccount = $this->createCustomerAccount($agencyId, $client['id'], $accountLedgerId);
        $loan = $this->createLoanApplication($agencyId, $client['id'], $product->id, 100000);
        $loan->forceFill([
            'status' => Loan::STATUS_ACTIVE,
            'approved_principal_minor' => 100000,
            'approved_on' => '2026-01-13',
            'disbursed_on' => '2026-01-13',
            'insurance_amount_minor' => 2000,
        ])->save();
        $this->createActiveSchedule($loan->id, [
            ['due_date' => '2026-02-13', 'principal_minor' => 50000, 'interest_minor' => 5000, 'fees_minor' => 0, 'insurance_minor' => 0, 'tax_minor' => 0],
            ['due_date' => '2026-06-13', 'principal_minor' => 50000, 'interest_minor' => 5000, 'fees_minor' => 0, 'insurance_minor' => 0, 'tax_minor' => 0],
        ]);
        $guarantor = $this->createVerifiedClientGuarantor($agencyId, $client['id'], 'Early Closure Guarantor');
        DB::table('loan_guarantee_obligations')->insert([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agencyId,
            'loan_id' => $loan->id,
            'client_guarantor_id' => $guarantor['id'],
            'obligation_type' => 'personal_guarantee',
            'obligation_amount_minor' => 100000,
            'currency' => 'XAF',
            'status' => 'active',
            'guarantor_identity_snapshot' => json_encode(['guarantor_full_name' => 'Early Closure Guarantor'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->fundCustomerAccount($agencyId, $repaymentAccount['id'], $accountLedgerId, 110000);

        config(['formulas.policies.repayment_allocation_order.approved' => true]);

        $tooEarly = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/loans/'.$loan->public_id.'/early-repayment', [
                'customer_account_public_id' => $repaymentAccount['public_id'],
                'amount_minor' => 110000,
                'paid_on' => '2026-03-13',
            ]);
        $tooEarly->assertStatus(422);
        $tooEarly->assertJsonValidationErrors(['early_repayment']);

        $insufficientWithoutWaiver = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/loans/'.$loan->public_id.'/early-repayment', [
                'customer_account_public_id' => $repaymentAccount['public_id'],
                'amount_minor' => 105000,
                'paid_on' => '2026-04-13',
            ]);
        $insufficientWithoutWaiver->assertStatus(422);
        $insufficientWithoutWaiver->assertJsonValidationErrors(['early_repayment']);

        $closed = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/loans/'.$loan->public_id.'/early-repayment', [
                'customer_account_public_id' => $repaymentAccount['public_id'],
                'amount_minor' => 105000,
                'paid_on' => '2026-04-13',
                'direction_interest_waiver' => true,
                'notes' => 'Direction waived future interest.',
            ]);
        $this->assertJsonSuccess($closed);
        $closed->assertJsonPath('data.loan.status', Loan::STATUS_CLOSED);
        $closed->assertJsonPath('data.loan.closed_on', '2026-04-13');
        $closed->assertJsonPath('data.payoff_amount_minor', 105000);
        $closed->assertJsonPath('data.direction_interest_waiver', true);
        $closed->assertJsonPath('data.early_repayment_fee_minor', 0);
        $closed->assertJsonPath('data.insurance_refunded_minor', 0);
        $closed->assertJsonPath('data.released_guarantee_obligations_count', 1);
        $closed->assertJsonPath('data.repayment.allocated_amount_minor', 105000);
        $closed->assertJsonPath('data.repayment.allocations.0.component', 'principal');
        $closed->assertJsonPath('data.repayment.allocations.0.amount_minor', 50000);
        $closed->assertJsonPath('data.repayment.allocations.1.installment_number', 2);
        $closed->assertJsonPath('data.repayment.allocations.1.component', 'principal');
        $closed->assertJsonPath('data.repayment.allocations.1.amount_minor', 50000);
        $closed->assertJsonPath('data.repayment.allocations.2.component', 'interest');
        $closed->assertJsonPath('data.repayment.allocations.2.amount_minor', 5000);

        $this->assertDatabaseHas('loan_guarantee_obligations', [
            'loan_id' => $loan->id,
            'status' => 'released',
            'released_by_user_id' => $actor->id,
        ]);
        $this->assertDatabaseHas('loans', [
            'id' => $loan->id,
            'status' => Loan::STATUS_CLOSED,
            'global_outstanding_amount_minor' => 0,
        ]);
    }

    public function test_early_repayment_supports_direction_negotiated_total_interest_concession(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agencyId = $this->createAgency('CR17N');
        $client = $this->createClientRecord($agencyId, 'verified');
        $product = $this->createLoanProduct($agencyId, [
            'rules' => [
                'early_repayment' => [
                    'minimum_months_after_disbursement' => 3,
                ],
            ],
        ]);
        $accountLedgerId = $this->createCustomerLiabilityLedger($agencyId, 'REPAY-N');
        $repaymentAccount = $this->createCustomerAccount($agencyId, $client['id'], $accountLedgerId);
        $loan = $this->createLoanApplication($agencyId, $client['id'], $product->id, 15000000);
        $loan->forceFill([
            'status' => Loan::STATUS_ACTIVE,
            'approved_principal_minor' => 15000000,
            'approved_on' => '2026-01-13',
            'disbursed_on' => '2026-01-13',
        ])->save();
        $this->createActiveSchedule($loan->id, [
            ['due_date' => '2026-02-13', 'principal_minor' => 7500000, 'interest_minor' => 1500000, 'fees_minor' => 0, 'insurance_minor' => 0, 'tax_minor' => 0],
            ['due_date' => '2026-06-13', 'principal_minor' => 7500000, 'interest_minor' => 1500000, 'fees_minor' => 0, 'insurance_minor' => 0, 'tax_minor' => 0],
        ]);
        $this->fundCustomerAccount($agencyId, $repaymentAccount['id'], $accountLedgerId, 17000000);

        config(['formulas.policies.repayment_allocation_order.approved' => true]);

        $closed = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/loans/'.$loan->public_id.'/early-repayment', [
                'customer_account_public_id' => $repaymentAccount['public_id'],
                'amount_minor' => 17000000,
                'paid_on' => '2026-04-13',
                'direction_negotiated_total_interest_minor' => 2000000,
                'notes' => 'Direction reduced total interest from 3M to 2M.',
            ]);
        $this->assertJsonSuccess($closed);
        $closed->assertJsonPath('data.loan.status', Loan::STATUS_CLOSED);
        $closed->assertJsonPath('data.payoff_amount_minor', 17000000);
        $closed->assertJsonPath('data.direction_interest_waiver', false);
        $closed->assertJsonPath('data.direction_negotiated_total_interest_minor', 2000000);
        $closed->assertJsonPath('data.interest_concession_minor', 1000000);
        $closed->assertJsonPath('data.repayment.allocated_amount_minor', 17000000);
        $closed->assertJsonPath('data.repayment.allocations.0.component', 'principal');
        $closed->assertJsonPath('data.repayment.allocations.0.amount_minor', 7500000);
        $closed->assertJsonPath('data.repayment.allocations.1.installment_number', 2);
        $closed->assertJsonPath('data.repayment.allocations.1.component', 'principal');
        $closed->assertJsonPath('data.repayment.allocations.1.amount_minor', 7500000);
        $closed->assertJsonPath('data.repayment.allocations.2.component', 'interest');
        $closed->assertJsonPath('data.repayment.allocations.2.amount_minor', 1500000);
        $closed->assertJsonPath('data.repayment.allocations.3.installment_number', 2);
        $closed->assertJsonPath('data.repayment.allocations.3.component', 'interest');
        $closed->assertJsonPath('data.repayment.allocations.3.amount_minor', 500000);
    }

    public function test_schedule_generation_is_formula_gated_deterministic_and_absorbs_final_residual(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agencyId = $this->createAgency('CR09');
        $client = $this->createClientRecord($agencyId, 'verified');
        $product = $this->createLoanProduct($agencyId, [
            'interest_rate' => '12.000000',
            'due_date_day' => 15,
        ]);
        $loan = $this->createLoanApplication($agencyId, $client['id'], $product->id, 200000);
        $loan->forceFill([
            'status' => Loan::STATUS_APPROVED,
            'approved_principal_minor' => 200000,
            'approved_on' => '2026-05-12',
            'first_installment_date' => '2026-06-15',
            'number_of_installments' => 4,
            'dossier_fees_minor' => 4000,
            'dossier_fees_tax_minor' => 800,
            'insurance_amount_minor' => 2000,
            'formula_policy_snapshot' => ['approved' => true, 'source' => 'test'],
        ])->save();

        config([
            'formulas.policies.xaf_rounding.approved' => false,
            'formulas.policies.loan_interest_method.approved' => false,
            'formulas.policies.loan_installment_amount.approved' => false,
            'formulas.policies.fees_taxes_insurance.approved' => false,
        ]);

        $blocked = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/loans/'.$loan->public_id.'/schedule/generate');
        $blocked->assertStatus(422);
        $blocked->assertJsonValidationErrors(['formula_policy']);

        config([
            'formulas.policies.xaf_rounding.approved' => true,
            'formulas.policies.loan_interest_method.approved' => true,
            'formulas.policies.loan_installment_amount.approved' => true,
            'formulas.policies.fees_taxes_insurance.approved' => true,
        ]);

        $generated = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/loans/'.$loan->public_id.'/schedule/generate');
        $this->assertJsonSuccess($generated);
        $generated->assertJsonPath('data.snapshot.formula_engine_key', 'installment');
        $policySnapshotHash = $generated->json('data.snapshot.policy_snapshot_hash');
        self::assertIsString($policySnapshotHash);
        self::assertSame(64, strlen($policySnapshotHash));
        $generated->assertJsonPath('data.snapshot.lines.0.installment_number', 1);
        $generated->assertJsonPath('data.snapshot.lines.0.due_date', '2026-06-15');
        $generated->assertJsonPath('data.snapshot.lines.0.principal_minor', 50000);
        $generated->assertJsonPath('data.snapshot.lines.0.interest_minor', 6000);
        $generated->assertJsonPath('data.snapshot.lines.0.fees_minor', 0);
        $generated->assertJsonPath('data.snapshot.lines.0.insurance_minor', 0);
        $generated->assertJsonPath('data.snapshot.lines.0.tax_minor', 0);
        $generated->assertJsonPath('data.snapshot.lines.0.remaining_principal_minor', 150000);
        $generated->assertJsonPath('data.snapshot.lines.0.total_installment_minor', 56000);
        $generated->assertJsonPath('data.snapshot.lines.3.remaining_principal_minor', 0);

        $repeat = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/loans/'.$loan->public_id.'/schedule/generate');
        $this->assertJsonSuccess($repeat);
        $repeat->assertJsonPath('data.snapshot.public_id', $generated->json('data.snapshot.public_id'));
        self::assertSame(1, DB::table('loan_schedule_snapshots')->where('loan_id', $loan->id)->count());
        self::assertSame(4, DB::table('loan_schedule_lines')->where('loan_schedule_snapshot_id', DB::table('loan_schedule_snapshots')->where('loan_id', $loan->id)->value('id'))->count());

        $residualProduct = $this->createLoanProduct($agencyId, [
            'interest_rate' => '0.000000',
        ]);
        $roundingLoan = $this->createLoanApplication($agencyId, $client['id'], $residualProduct->id, 100001);
        $roundingLoan->forceFill([
            'status' => Loan::STATUS_APPROVED,
            'approved_principal_minor' => 100001,
            'approved_on' => '2026-05-12',
            'number_of_installments' => 4,
        ])->save();

        $residualSchedule = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/loans/'.$roundingLoan->public_id.'/schedule/generate');
        $this->assertJsonSuccess($residualSchedule);
        $residualSchedule->assertJsonPath('data.snapshot.lines.0.principal_minor', 25000);
        $residualSchedule->assertJsonPath('data.snapshot.lines.1.principal_minor', 25000);
        $residualSchedule->assertJsonPath('data.snapshot.lines.2.principal_minor', 25000);
        $residualSchedule->assertJsonPath('data.snapshot.lines.3.principal_minor', 25001);
        $residualSchedule->assertJsonPath('data.snapshot.lines.3.remaining_principal_minor', 0);
    }

    public function test_rescheduling_preserves_old_schedule_and_blocks_capitalization_without_accounting_workflow(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agencyId = $this->createAgency('CR10');
        $client = $this->createClientRecord($agencyId, 'verified');
        $product = $this->createLoanProduct($agencyId, [
            'interest_rate' => '10.000000',
        ]);
        $loan = $this->createLoanApplication($agencyId, $client['id'], $product->id, 200000);
        $loan->forceFill([
            'status' => Loan::STATUS_APPROVED,
            'approved_principal_minor' => 200000,
            'approved_on' => '2026-05-12',
            'first_installment_date' => '2026-06-15',
            'number_of_installments' => 4,
            'formula_policy_snapshot' => ['approved' => true, 'source' => 'test'],
        ])->save();

        config([
            'formulas.policies.xaf_rounding.approved' => true,
            'formulas.policies.loan_interest_method.approved' => true,
            'formulas.policies.loan_installment_amount.approved' => true,
            'formulas.policies.fees_taxes_insurance.approved' => true,
        ]);

        $initial = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/loans/'.$loan->public_id.'/schedule/generate');
        $this->assertJsonSuccess($initial);
        $initialSnapshotPublicId = $this->requireStringJsonPath($initial, 'data.snapshot.public_id');
        $initialSnapshotId = DB::table('loan_schedule_snapshots')->where('public_id', $initialSnapshotPublicId)->value('id');
        self::assertIsInt($initialSnapshotId);

        $loan->refresh()->forceFill(['status' => Loan::STATUS_ACTIVE])->save();

        $capitalizationBlocked = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/loans/'.$loan->public_id.'/schedule/reschedule', [
                'first_installment_date' => '2026-07-15',
                'number_of_installments' => 5,
                'capitalized_interest_minor' => 1000,
                'reason' => 'Capitalization should need separate approval.',
            ]);
        $capitalizationBlocked->assertStatus(422);
        $capitalizationBlocked->assertJsonValidationErrors(['reschedule']);

        $rescheduled = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/loans/'.$loan->public_id.'/schedule/reschedule', [
                'first_installment_date' => '2026-07-15',
                'number_of_installments' => 5,
                'reason' => 'Approved reschedule without capitalization.',
            ]);
        $this->assertJsonSuccess($rescheduled);
        $rescheduled->assertJsonPath('data.loan.public_id', $loan->public_id);
        $rescheduled->assertJsonPath('data.loan.status', Loan::STATUS_RESCHEDULED);
        $rescheduled->assertJsonPath('data.snapshot.lines.0.due_date', '2026-07-15');
        $rescheduled->assertJsonPath('data.snapshot.lines.0.principal_minor', 40000);
        $rescheduled->assertJsonPath('data.snapshot.lines.0.interest_minor', 4000);
        $rescheduled->assertJsonPath('data.snapshot.lines.4.remaining_principal_minor', 0);

        $this->assertDatabaseHas('loan_schedule_snapshots', [
            'id' => $initialSnapshotId,
            'status' => 'superseded',
        ]);
        self::assertSame(4, DB::table('loan_schedule_lines')->where('loan_schedule_snapshot_id', $initialSnapshotId)->count());
        self::assertSame(2, DB::table('loan_schedule_snapshots')->where('loan_id', $loan->id)->count());
        self::assertSame(1, DB::table('loan_schedule_snapshots')->where('loan_id', $loan->id)->where('status', 'active')->count());
        $this->assertDatabaseHas('loan_status_transitions', [
            'loan_id' => $loan->id,
            'from_status' => Loan::STATUS_ACTIVE,
            'to_status' => Loan::STATUS_RESCHEDULED,
            'reason' => 'schedule_rescheduled',
        ]);
    }

    public function test_collateral_and_items_lifecycle_enforces_agency_scope_and_release_on_closure(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agencyId = $this->createAgency('CR11');
        $otherAgencyId = $this->createAgency('CR12');
        $client = $this->createClientRecord($agencyId, 'verified');
        $otherClient = $this->createClientRecord($otherAgencyId, 'verified');
        $product = $this->createLoanProduct($agencyId);
        $loan = $this->createLoanApplication($agencyId, $client['id'], $product->id, 500000);
        $documentPublicId = $this->createDocument($agencyId);

        $crossAgency = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/loans/'.$loan->public_id.'/collaterals', [
                'client_public_id' => $otherClient['public_id'],
                'collateral_type' => 'movable',
                'description' => 'Wrong agency collateral',
            ]);
        $crossAgency->assertStatus(422);
        $crossAgency->assertJsonValidationErrors(['client_public_id']);

        $create = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/loans/'.$loan->public_id.'/collaterals', [
                'client_public_id' => $client['public_id'],
                'document_public_id' => $documentPublicId,
                'collateral_type' => 'movable',
                'description' => 'Motorbike collateral',
                'owner_full_name' => 'Loan Client',
                'valuation_date' => '2026-05-12',
                'declared_value_minor' => 250000,
                'currency' => 'XAF',
            ]);
        $this->assertJsonSuccess($create, 201);
        $collateralPublicId = $this->requireStringJsonPath($create, 'data.public_id');
        $create->assertJsonPath('data.loan_public_id', $loan->public_id);
        $create->assertJsonPath('data.client_public_id', $client['public_id']);
        $create->assertJsonPath('data.document_public_id', $documentPublicId);
        $create->assertJsonMissing(['id' => 1]);

        $item = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/loans/'.$loan->public_id.'/collaterals/'.$collateralPublicId.'/items', [
                'quantity' => 1,
                'description' => 'Motorbike',
                'reference' => 'BIKE-001',
                'chassis_number' => 'CH-123',
                'registration_number' => 'LT-123-AA',
                'amount_minor' => 250000,
                'currency' => 'XAF',
                'metadata' => ['condition' => 'good'],
            ]);
        $this->assertJsonSuccess($item, 201);
        $itemPublicId = $this->requireStringJsonPath($item, 'data.public_id');
        $item->assertJsonPath('data.reference', 'BIKE-001');

        $updatedItem = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->patchJson('/api/v1/loans/'.$loan->public_id.'/collaterals/'.$collateralPublicId.'/items/'.$itemPublicId, [
                'amount_minor' => 260000,
                'metadata' => ['condition' => 'revalued'],
            ]);
        $this->assertJsonSuccess($updatedItem);
        $updatedItem->assertJsonPath('data.amount_minor', 260000);
        $updatedItem->assertJsonPath('data.metadata.condition', 'revalued');

        $list = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->getJson('/api/v1/loans/'.$loan->public_id.'/collaterals');
        $this->assertJsonSuccess($list);
        $list->assertJsonPath('data.collaterals.0.public_id', $collateralPublicId);
        $list->assertJsonPath('data.collaterals.0.items.0.public_id', $itemPublicId);

        $releaseBlocked = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/loans/'.$loan->public_id.'/collaterals/'.$collateralPublicId.'/release');
        $releaseBlocked->assertStatus(422);
        $releaseBlocked->assertJsonValidationErrors(['loan']);

        $loan->forceFill(['status' => Loan::STATUS_CLOSED, 'closed_on' => now()->toDateString()])->save();
        $release = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/loans/'.$loan->public_id.'/collaterals/'.$collateralPublicId.'/release');
        $this->assertJsonSuccess($release);
        $release->assertJsonPath('data.status', 'released');

        $this->assertDatabaseHas('collaterals', [
            'public_id' => $collateralPublicId,
            'loan_id' => $loan->id,
            'agency_id' => $agencyId,
            'status' => 'released',
        ]);
        $this->assertDatabaseHas('collateral_items', [
            'public_id' => $itemPublicId,
            'amount_minor' => 260000,
        ]);
    }

    public function test_guarantee_obligation_release_condition_is_restricted_to_loan_closed(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agencyId = $this->createAgency('CR-REL');
        $client = $this->createClientRecord($agencyId, 'verified');
        $product = $this->createLoanProduct($agencyId);
        $loan = $this->createLoanApplication($agencyId, $client['id'], $product->id, 200000);
        $guarantor = $this->createVerifiedClientGuarantor($agencyId, $client['id'], 'Release Guarantor');

        // An unsupported release condition is rejected.
        $invalid = $this->withApiHeaders()->actingAsSanctum($actor)
            ->postJson('/api/v1/loans/'.$loan->public_id.'/guarantee-obligations', [
                'client_guarantor_public_id' => $guarantor['public_id'],
                'obligation_type' => 'personal_guarantee',
                'release_condition' => 'manual',
            ]);
        $invalid->assertStatus(422);
        $invalid->assertJsonValidationErrors(['release_condition']);

        // The only supported value is accepted.
        $valid = $this->withApiHeaders()->actingAsSanctum($actor)
            ->postJson('/api/v1/loans/'.$loan->public_id.'/guarantee-obligations', [
                'client_guarantor_public_id' => $guarantor['public_id'],
                'obligation_type' => 'personal_guarantee',
                'release_condition' => 'loan_closed',
            ]);
        $this->assertJsonSuccess($valid, 201);
    }

    public function test_loan_guarantee_obligations_scope_release_and_snapshot_immutability(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agencyId = $this->createAgency('CR13');
        $otherAgencyId = $this->createAgency('CR14');
        $client = $this->createClientRecord($agencyId, 'verified');
        $otherClient = $this->createClientRecord($otherAgencyId, 'verified');
        $product = $this->createLoanProduct($agencyId);
        $loan = $this->createLoanApplication($agencyId, $client['id'], $product->id, 600000);
        $documentPublicId = $this->createDocument($agencyId);
        $guarantor = $this->createVerifiedClientGuarantor($agencyId, $client['id'], 'Original Guarantor');
        $otherAgencyGuarantor = $this->createVerifiedClientGuarantor($otherAgencyId, $otherClient['id'], 'Other Agency Guarantor');

        $crossAgency = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/loans/'.$loan->public_id.'/guarantee-obligations', [
                'client_guarantor_public_id' => $otherAgencyGuarantor['public_id'],
                'obligation_type' => 'personal_guarantee',
                'obligation_amount_minor' => 150000,
            ]);
        $crossAgency->assertStatus(422);
        $crossAgency->assertJsonValidationErrors(['client_guarantor_public_id']);

        $create = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/loans/'.$loan->public_id.'/guarantee-obligations', [
                'client_guarantor_public_id' => $guarantor['public_id'],
                'document_public_id' => $documentPublicId,
                'obligation_type' => 'personal_guarantee',
                'obligation_amount_minor' => 150000,
                'obligation_percentage' => '25.000000',
                'currency' => 'XAF',
                'starts_on' => '2026-05-13',
                'release_condition' => 'loan_closed',
            ]);
        $this->assertJsonSuccess($create, 201);
        $obligationPublicId = $this->requireStringJsonPath($create, 'data.public_id');
        $create->assertJsonPath('data.loan_public_id', $loan->public_id);
        $create->assertJsonPath('data.client_guarantor_public_id', $guarantor['public_id']);
        $create->assertJsonPath('data.document_public_id', $documentPublicId);
        $create->assertJsonPath('data.guarantor_identity_snapshot.guarantor_full_name', 'Original Guarantor');
        $create->assertJsonMissing(['id' => 1]);

        DB::table('client_guarantors')
            ->where('public_id', $guarantor['public_id'])
            ->update([
                'guarantor_full_name' => 'Edited Guarantor',
                'guarantor_phone_number' => '+237699999999',
                'updated_at' => now(),
            ]);

        $updated = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->patchJson('/api/v1/loans/'.$loan->public_id.'/guarantee-obligations/'.$obligationPublicId, [
                'obligation_amount_minor' => 175000,
                'ends_on' => '2026-12-31',
            ]);
        $this->assertJsonSuccess($updated);
        $updated->assertJsonPath('data.obligation_amount_minor', 175000);
        $updated->assertJsonPath('data.guarantor_identity_snapshot.guarantor_full_name', 'Original Guarantor');
        $updated->assertJsonPath('data.guarantor_identity_snapshot.guarantor_phone_number', '+237699000000');

        $releaseBlocked = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/loans/'.$loan->public_id.'/guarantee-obligations/'.$obligationPublicId.'/release');
        $releaseBlocked->assertStatus(422);
        $releaseBlocked->assertJsonValidationErrors(['loan']);

        $loan->forceFill(['status' => Loan::STATUS_CLOSED, 'closed_on' => now()->toDateString()])->save();
        $release = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/loans/'.$loan->public_id.'/guarantee-obligations/'.$obligationPublicId.'/release');
        $this->assertJsonSuccess($release);
        $release->assertJsonPath('data.status', 'released');
        $release->assertJsonPath('data.released_by_user_public_id', $actor->public_id);

        $list = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->getJson('/api/v1/loans/'.$loan->public_id.'/guarantee-obligations');
        $this->assertJsonSuccess($list);
        $list->assertJsonPath('data.guarantee_obligations.0.public_id', $obligationPublicId);
        $list->assertJsonPath('data.guarantee_obligations.0.guarantor_identity_snapshot.guarantor_full_name', 'Original Guarantor');

        $this->assertDatabaseHas('loan_guarantee_obligations', [
            'public_id' => $obligationPublicId,
            'loan_id' => $loan->id,
            'agency_id' => $agencyId,
            'client_guarantor_id' => $guarantor['id'],
            'status' => 'released',
            'obligation_amount_minor' => 175000,
        ]);
    }

    private function createUserWithRole(string $role): User
    {
        $user = User::factory()->createOne([
            'status' => User::STATUS_ACTIVE,
            'phone_verified_at' => now(),
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function createAgency(string $code): int
    {
        return DB::table('agencies')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'code' => $code,
            'name' => 'Agency '.$code,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array{id:int, public_id:string}
     */
    private function createLedgerAccount(int $agencyId, string $status = LedgerAccount::STATUS_ACTIVE): array
    {
        $id = DB::table('ledger_accounts')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agencyId,
            'code' => 'LN-'.Str::ulid(),
            'name' => 'Loan Ledger',
            'account_class' => LedgerAccount::ACCOUNT_CLASS_ASSET,
            'normal_balance_side' => LedgerAccount::NORMAL_BALANCE_DEBIT,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $ledger = DB::table('ledger_accounts')->where('id', $id)->first(['public_id']);
        self::assertIsObject($ledger);
        self::assertIsString($ledger->public_id);

        return [
            'id' => $id,
            'public_id' => $ledger->public_id,
        ];
    }

    private function createCustomerLiabilityLedger(int $agencyId, string $prefix): int
    {
        return DB::table('ledger_accounts')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agencyId,
            'code' => $prefix.'-'.Str::ulid(),
            'name' => 'Customer Liability '.$prefix,
            'account_class' => LedgerAccount::ACCOUNT_CLASS_LIABILITY,
            'normal_balance_side' => LedgerAccount::NORMAL_BALANCE_CREDIT,
            'status' => LedgerAccount::STATUS_ACTIVE,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createRevenueLedger(int $agencyId, string $prefix): int
    {
        return DB::table('ledger_accounts')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agencyId,
            'code' => $prefix.'-'.Str::ulid(),
            'name' => 'Revenue '.$prefix,
            'account_class' => LedgerAccount::ACCOUNT_CLASS_REVENUE,
            'normal_balance_side' => LedgerAccount::NORMAL_BALANCE_CREDIT,
            'status' => LedgerAccount::STATUS_ACTIVE,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function postCustomerAccountCredit(int $agencyId, int $customerAccountId, int $customerLedgerId, int $offsetLedgerId, int $postedByUserId, int $amountMinor): void
    {
        $journalEntryId = DB::table('journal_entries')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'reference' => 'BAL-'.Str::ulid(),
            'business_date' => '2026-06-01',
            'posted_at' => null,
            'agency_id' => $agencyId,
            'source_module' => 'test',
            'source_type' => 'customer_account_seed',
            'status' => JournalEntry::STATUS_DRAFT,
            'description' => 'Seed customer account balance',
            'created_by_user_id' => $postedByUserId,
            'posted_by_user_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('journal_lines')->insert([
            [
                'public_id' => (string) Str::ulid(),
                'agency_id' => $agencyId,
                'journal_entry_id' => $journalEntryId,
                'ledger_account_id' => $offsetLedgerId,
                'customer_account_id' => null,
                'loan_id' => null,
                'debit_minor' => $amountMinor,
                'credit_minor' => 0,
                'currency' => 'XAF',
                'line_memo' => 'Offset for customer account seed',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'public_id' => (string) Str::ulid(),
                'agency_id' => $agencyId,
                'journal_entry_id' => $journalEntryId,
                'ledger_account_id' => $customerLedgerId,
                'customer_account_id' => $customerAccountId,
                'loan_id' => null,
                'debit_minor' => 0,
                'credit_minor' => $amountMinor,
                'currency' => 'XAF',
                'line_memo' => 'Customer account seed balance',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->postSeedJournal($journalEntryId, $postedByUserId);
    }

    private function createReportDefinition(string $code, string $reportType): string
    {
        $publicId = (string) Str::ulid();
        DB::table('report_definitions')->insert([
            'public_id' => $publicId,
            'code' => $code,
            'name' => 'Credit Report '.$code,
            'report_type' => $reportType,
            'module' => 'credit',
            'status' => ReportDefinition::STATUS_ACTIVE,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $publicId;
    }

    private function requireStringJsonPath(mixed $response, string $path): string
    {
        $value = $response instanceof TestResponse ? $response->json($path) : null;
        self::assertIsString($value);

        return $value;
    }

    private function createClient(int $agencyId): int
    {
        return $this->createClientRecord($agencyId)['id'];
    }

    /**
     * @return array{id:int, public_id:string}
     */
    private function createClientRecord(int $agencyId, string $kycStatus = 'verified'): array
    {
        $id = DB::table('clients')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agencyId,
            'client_reference' => 'CLI-'.Str::ulid(),
            'first_name' => 'Loan',
            'last_name' => 'Client',
            'status' => 'active',
            'kyc_status' => $kycStatus,
        ]);
        $client = DB::table('clients')->where('id', $id)->first(['public_id']);
        self::assertIsObject($client);
        self::assertIsString($client->public_id);

        return [
            'id' => $id,
            'public_id' => $client->public_id,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createLoanProduct(int $agencyId, array $overrides = []): LoanProduct
    {
        $ledger = $this->createLedgerAccount($agencyId);

        $product = LoanProduct::query()->create(array_merge([
            'public_id' => (string) Str::ulid(),
            'ledger_account_id' => $ledger['id'],
            'code' => 'LP-'.Str::ulid(),
            'name' => 'Loan Product',
            'status' => LoanProduct::STATUS_ACTIVE,
            'min_amount_minor' => 100000,
            'max_amount_minor' => 1000000,
            'min_term_count' => 3,
            'max_term_count' => 12,
            'term_unit' => LoanProduct::TERM_UNIT_MONTH,
        ], $overrides));

        $this->createRepaymentComponentMappings([
            'principal' => $ledger['id'],
            'interest' => $ledger['id'],
            'fees' => $ledger['id'],
            'insurance' => $ledger['id'],
            'tax' => $ledger['id'],
            'penalty' => $ledger['id'],
        ]);

        return $product;
    }

    /**
     * @param  array<string, int>  $componentLedgerIds
     */
    private function createRepaymentComponentMappings(array $componentLedgerIds, ?string $currency = null): void
    {
        foreach ($componentLedgerIds as $component => $ledgerId) {
            DB::table('operation_account_mappings')->insert([
                'public_id' => (string) Str::ulid(),
                'operation_code_id' => $this->operationCodeIdForRepaymentComponent($component),
                'debit_ledger_account_id' => null,
                'credit_ledger_account_id' => $ledgerId,
                'currency' => $currency,
                'status' => 'active',
                'rules' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function operationCodeIdForRepaymentComponent(string $component): int
    {
        $code = match ($component) {
            'principal' => 'loan_repayment_principal',
            'interest' => 'loan_repayment_interest',
            'fees' => 'loan_repayment_fees',
            'insurance' => 'loan_repayment_insurance',
            'tax' => 'loan_repayment_tax',
            'penalty' => 'loan_repayment_penalty',
            default => self::fail('Unsupported repayment component '.$component),
        };

        $existing = DB::table('operation_codes')->where('code', $code)->first(['id']);
        if (is_object($existing) && is_int($existing->id)) {
            return $existing->id;
        }

        return DB::table('operation_codes')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'code' => $code,
            'label' => str_replace('_', ' ', $code),
            'module' => 'loan',
            'operation_type' => 'repayment',
            'direction' => 'credit',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, int>  $chargeLedgerIds
     */
    private function createSetupChargeMappings(array $chargeLedgerIds, ?string $currency = null): void
    {
        foreach ($chargeLedgerIds as $chargeType => $ledgerId) {
            DB::table('operation_account_mappings')->insert([
                'public_id' => (string) Str::ulid(),
                'operation_code_id' => $this->operationCodeIdForSetupCharge($chargeType),
                'debit_ledger_account_id' => null,
                'credit_ledger_account_id' => $ledgerId,
                'currency' => $currency,
                'status' => 'active',
                'approval_status' => 'approved',
                'rules' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function operationCodeIdForSetupCharge(string $chargeType): int
    {
        $code = match ($chargeType) {
            'dossier_fee' => 'loan_setup_dossier_fee',
            'dossier_fee_tax' => 'loan_setup_tax',
            'guarantee_deposit' => 'loan_setup_guarantee_deposit',
            default => self::fail('Unsupported setup charge type '.$chargeType),
        };

        $existing = DB::table('operation_codes')->where('code', $code)->first(['id']);
        if (is_object($existing) && is_int($existing->id)) {
            return $existing->id;
        }

        return DB::table('operation_codes')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'code' => $code,
            'label' => str_replace('_', ' ', $code),
            'module' => 'loan',
            'operation_type' => 'setup_charge',
            'direction' => 'credit',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array{id:int, public_id:string}
     */
    private function createCustomerAccount(int $agencyId, int $clientId, ?int $ledgerAccountId = null): array
    {
        $id = DB::table('customer_accounts')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'client_id' => $clientId,
            'agency_id' => $agencyId,
            'ledger_account_id' => $ledgerAccountId,
            'account_number' => 'CA-'.Str::ulid(),
            'account_title' => 'Client Account',
            'account_type' => 'ordinary_savings',
            'currency' => 'XAF',
            'unavailable_amount_minor' => 0,
            'opened_on' => now()->toDateString(),
            'status' => 'active',
        ]);
        $account = DB::table('customer_accounts')->where('id', $id)->first(['public_id']);
        self::assertIsObject($account);
        self::assertIsString($account->public_id);

        return [
            'id' => $id,
            'public_id' => $account->public_id,
        ];
    }

    private function fundCustomerAccount(int $agencyId, int $customerAccountId, int $customerLedgerAccountId, int $amountMinor): void
    {
        $offsetLedger = $this->createLedgerAccount($agencyId);
        $postedByUserId = DB::table('users')->value('id');
        self::assertIsInt($postedByUserId);
        $journalEntryId = DB::table('journal_entries')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'reference' => 'CUSTOMER-FUND-'.Str::ulid(),
            'business_date' => '2026-05-13',
            'posted_at' => null,
            'agency_id' => $agencyId,
            'source_module' => 'test',
            'source_type' => 'customer_account_funding',
            'status' => JournalEntry::STATUS_DRAFT,
            'created_by_user_id' => $postedByUserId,
            'posted_by_user_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('journal_lines')->insert([
            [
                'public_id' => (string) Str::ulid(),
                'agency_id' => $agencyId,
                'journal_entry_id' => $journalEntryId,
                'ledger_account_id' => $offsetLedger['id'],
                'customer_account_id' => null,
                'loan_id' => null,
                'debit_minor' => $amountMinor,
                'credit_minor' => 0,
                'currency' => 'XAF',
                'line_memo' => 'Seed customer account funding offset',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'public_id' => (string) Str::ulid(),
                'agency_id' => $agencyId,
                'journal_entry_id' => $journalEntryId,
                'ledger_account_id' => $customerLedgerAccountId,
                'customer_account_id' => $customerAccountId,
                'loan_id' => null,
                'debit_minor' => 0,
                'credit_minor' => $amountMinor,
                'currency' => 'XAF',
                'line_memo' => 'Seed customer account funding',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->postSeedJournal($journalEntryId, $postedByUserId);
    }

    /**
     * @return array{id:int, public_id:string, till_id:int}
     */
    private function createOpenTellerSession(int $agencyId, int $cashLedgerAccountId, int $cashBalanceMinor): array
    {
        $teller = $this->createUserWithRole('teller');
        $this->assignStaffToAgency($teller, $agencyId, 'teller');

        $tillId = DB::table('tills')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agencyId,
            'code' => 'TILL-'.Str::ulid(),
            'name' => 'Loan Cash Till',
            'type' => 'counter',
            'status' => 'active',
            'daily_state' => 'open',
            'requires_denominations' => false,
            'currency' => 'XAF',
            'assigned_user_id' => $teller->id,
            'ledger_account_id' => $cashLedgerAccountId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $sessionId = DB::table('teller_sessions')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'till_id' => $tillId,
            'agency_id' => $agencyId,
            'teller_user_id' => $teller->id,
            'business_date' => '2026-05-13',
            'opened_at' => now(),
            'opening_declaration_minor' => $cashBalanceMinor,
            'currency' => 'XAF',
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $offsetLedger = $this->createLedgerAccount($agencyId);
        $journalEntryId = DB::table('journal_entries')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'reference' => 'CASH-SEED-'.Str::ulid(),
            'business_date' => '2026-05-13',
            'posted_at' => null,
            'agency_id' => $agencyId,
            'source_module' => 'cash',
            'source_type' => 'till_opening_seed',
            'status' => JournalEntry::STATUS_DRAFT,
            'created_by_user_id' => $teller->id,
            'posted_by_user_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('journal_lines')->insert([
            [
                'public_id' => (string) Str::ulid(),
                'agency_id' => $agencyId,
                'journal_entry_id' => $journalEntryId,
                'ledger_account_id' => $cashLedgerAccountId,
                'customer_account_id' => null,
                'loan_id' => null,
                'debit_minor' => $cashBalanceMinor,
                'credit_minor' => 0,
                'currency' => 'XAF',
                'line_memo' => 'Seed till cash for loan disbursement test',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'public_id' => (string) Str::ulid(),
                'agency_id' => $agencyId,
                'journal_entry_id' => $journalEntryId,
                'ledger_account_id' => $offsetLedger['id'],
                'customer_account_id' => null,
                'loan_id' => null,
                'debit_minor' => 0,
                'credit_minor' => $cashBalanceMinor,
                'currency' => 'XAF',
                'line_memo' => 'Offset for loan cash till seed',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->postSeedJournal($journalEntryId, $teller->id);

        $session = DB::table('teller_sessions')->where('id', $sessionId)->first(['public_id']);
        self::assertIsObject($session);
        self::assertIsString($session->public_id);

        return [
            'id' => $sessionId,
            'public_id' => $session->public_id,
            'till_id' => $tillId,
        ];
    }

    private function assignStaffToAgency(User $user, int $agencyId, string $roleAtAgency): void
    {
        StaffAgencyAssignment::query()->create([
            'public_id' => (string) Str::ulid(),
            'user_id' => $user->id,
            'agency_id' => $agencyId,
            'role_at_agency' => $roleAtAgency,
            'starts_on' => now()->toDateString(),
            'is_primary' => true,
            'status' => StaffAgencyAssignment::STATUS_ACTIVE,
        ]);
    }

    private function postSeedJournal(int $journalEntryId, int $actorUserId): void
    {
        DB::table('journal_entries')->where('id', $journalEntryId)->update([
            'status' => JournalEntry::STATUS_SUBMITTED,
            'submitted_at' => now(),
            'submitted_by_user_id' => $actorUserId,
            'updated_at' => now(),
        ]);
        DB::table('journal_entries')->where('id', $journalEntryId)->update([
            'status' => JournalEntry::STATUS_APPROVED,
            'reviewed_at' => now(),
            'reviewed_by_user_id' => $actorUserId,
            'updated_at' => now(),
        ]);
        DB::table('journal_entries')->where('id', $journalEntryId)->update([
            'status' => JournalEntry::STATUS_POSTED,
            'posted_at' => now(),
            'posted_by_user_id' => $actorUserId,
            'updated_at' => now(),
        ]);
    }

    private function createDocument(int $agencyId): string
    {
        $publicId = (string) Str::ulid();
        DB::table('documents')->insert([
            'public_id' => $publicId,
            'agency_id' => $agencyId,
            'category' => 'collateral',
            'title' => 'Collateral document',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $publicId;
    }

    /**
     * @return array{id:int, public_id:string}
     */
    private function createVerifiedClientGuarantor(int $agencyId, int $clientId, string $fullName): array
    {
        $publicId = (string) Str::ulid();
        $id = DB::table('client_guarantors')->insertGetId([
            'public_id' => $publicId,
            'agency_id' => $agencyId,
            'client_id' => $clientId,
            'guarantor_full_name' => $fullName,
            'guarantor_phone_number' => '+237699000000',
            'relationship_type' => 'family',
            'status' => ClientGuarantor::STATUS_ACTIVE,
            'verification_status' => ClientGuarantor::VERIFICATION_VERIFIED,
            'submitted_at' => now(),
            'verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'id' => $id,
            'public_id' => $publicId,
        ];
    }

    /**
     * @param  array<int, array{due_date:string, principal_minor:int, interest_minor:int, fees_minor:int, insurance_minor:int, tax_minor:int, penalty_minor?:int}>  $lines
     */
    private function createActiveSchedule(int $loanId, array $lines): void
    {
        $snapshotId = DB::table('loan_schedule_snapshots')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'loan_id' => $loanId,
            'formula_engine_key' => 'installment',
            'formula_engine_version' => 'test',
            'policy_snapshot_hash' => hash('sha256', 'schedule-'.$loanId),
            'generated_at' => now(),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($lines as $index => $line) {
            $penalty = $line['penalty_minor'] ?? 0;
            $total = $line['principal_minor'] + $line['interest_minor'] + $line['fees_minor'] + $line['insurance_minor'] + $line['tax_minor'] + $penalty;
            DB::table('loan_schedule_lines')->insert([
                'loan_schedule_snapshot_id' => $snapshotId,
                'installment_number' => $index + 1,
                'due_date' => $line['due_date'],
                'principal_minor' => $line['principal_minor'],
                'interest_minor' => $line['interest_minor'],
                'fees_minor' => $line['fees_minor'],
                'insurance_minor' => $line['insurance_minor'],
                'tax_minor' => $line['tax_minor'],
                'penalty_minor' => $penalty,
                'capitalized_interest_minor' => 0,
                'remaining_principal_minor' => $index === count($lines) - 1 ? 0 : 50000,
                'total_installment_minor' => $total,
                'currency' => 'XAF',
                'status' => 'scheduled',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function createLoanApplication(int $agencyId, int $clientId, int $productId, int $amountMinor): Loan
    {
        return Loan::query()->create([
            'public_id' => (string) Str::ulid(),
            'client_id' => $clientId,
            'agency_id' => $agencyId,
            'loan_product_id' => $productId,
            'loan_number' => 'LN-'.Str::ulid(),
            'requested_amount_minor' => $amountMinor,
            'currency' => 'XAF',
            'applied_on' => now()->toDateString(),
            'status' => Loan::STATUS_APPLICATION,
        ]);
    }

    public function test_loan_setup_charge_state_reflects_lifecycle_and_shares_disbursement_readiness(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agencyId = $this->createAgency('CR06S');
        $client = $this->createClientRecord($agencyId, 'verified');
        $product = $this->createLoanProduct($agencyId, [
            'interest_rate' => '10.000000',
            'tax_rate' => '19.250000',
            'insurance_rate' => '2.000000',
            'guarantee_deposit_type' => 'percentage',
            'guarantee_deposit_value' => '10.000000',
            'rules' => [
                'setup_charges' => [
                    'dossier_fee_rate' => '3.000000',
                    'tax_base' => 'principal_plus_interest',
                    'guarantee_deposit_collection_method' => 'cash',
                ],
            ],
        ]);

        $loan = Loan::query()->create([
            'public_id' => (string) Str::ulid(),
            'client_id' => $client['id'],
            'agency_id' => $agencyId,
            'loan_product_id' => $product->id,
            'loan_number' => 'LN-'.Str::ulid(),
            'requested_amount_minor' => 200000,
            'currency' => 'XAF',
            'applied_on' => now()->toDateString(),
            'status' => Loan::STATUS_APPLICATION,
        ]);

        // Before assessment: explicit not_assessed readiness and required next action.
        $before = $this->getSetupState($actor, $loan->public_id);
        $this->assertJsonSuccess($before);
        $before->assertJsonPath('data.readiness_status', 'not_assessed');
        $before->assertJsonPath('data.ready_for_disbursement', false);
        $before->assertJsonPath('data.required_next_actions.0.action', 'assess_setup_charges');
        self::assertSame([], $before->json('data.setup_charges'));
        $before->assertJsonPath('data.loan_assurance.managed_as_premium', false);

        // Assess, and prove repeated assessment is idempotent (no duplicate rows).
        $this->assertJsonSuccess($this->assessSetupCharges($actor, $loan->public_id));
        $this->assertJsonSuccess($this->assessSetupCharges($actor, $loan->public_id));
        self::assertSame(3, DB::table('loan_charge_assessments')->where('loan_id', $loan->id)->count());
        self::assertSame(0, DB::table('insurance_premium_assessments')->where('loan_id', $loan->id)->count());

        // After assessment: all assessed setup charges are visible and blocking.
        $assessed = $this->getSetupState($actor, $loan->public_id);
        $assessed->assertJsonPath('data.readiness_status', 'collection_pending');
        $assessed->assertJsonPath('data.ready_for_disbursement', false);
        $assessed->assertJsonPath('data.loan_assurance.amount_minor', 4000);
        $assessed->assertJsonPath('data.loan_assurance.blocking_disbursement', false);
        $assessed->assertJsonPath('data.loan_assurance.managed_as_premium', false);
        $charges = $assessed->json('data.setup_charges');
        self::assertIsArray($charges);
        self::assertCount(3, $charges);
        foreach ($charges as $charge) {
            self::assertIsArray($charge);
            self::assertSame('assessed', $charge['status']);
            self::assertTrue($charge['collectable']);
            self::assertTrue($charge['blocking_disbursement']);
            self::assertNull($charge['paid_at']);
            self::assertNull($charge['journal_entry_public_id']);
        }
        // Lock the documented field contract so fields cannot silently disappear.
        $assessed->assertJsonStructure([
            'data' => [
                'loan_public_id',
                'loan_status',
                'currency',
                'readiness_status',
                'ready_for_disbursement',
                'required_next_actions',
                'setup_charges' => [[
                    'public_id', 'charge_type', 'assessed_amount_minor', 'currency', 'status',
                    'paid_at', 'journal_entry_public_id', 'waiver_decision', 'collectable', 'blocking_disbursement',
                ]],
                'loan_assurance' => [
                    'amount_minor', 'rate', 'currency', 'blocking_disbursement', 'managed_as_premium',
                ],
            ],
        ]);

        // Wire ledgers/mappings/accounts/session and approve the loan.
        $feeIncomeLedgerId = $this->createRevenueLedger($agencyId, 'SETUP-FEE');
        $taxPayableLedgerId = $this->createCustomerLiabilityLedger($agencyId, 'SETUP-TAX');
        $guaranteeLiabilityLedgerId = $this->createCustomerLiabilityLedger($agencyId, 'SETUP-GUAR');
        $this->createSetupChargeMappings([
            'dossier_fee' => $feeIncomeLedgerId,
            'dossier_fee_tax' => $taxPayableLedgerId,
            'guarantee_deposit' => $guaranteeLiabilityLedgerId,
        ], 'XAF');
        $collectionLedgerId = $this->createCustomerLiabilityLedger($agencyId, 'SETUP-COLLECT');
        $collectionAccount = $this->createCustomerAccount($agencyId, $client['id'], $collectionLedgerId);
        $this->fundCustomerAccount($agencyId, $collectionAccount['id'], $collectionLedgerId, 52350);
        $cashLedger = $this->createLedgerAccount($agencyId);
        $session = $this->createOpenTellerSession($agencyId, $cashLedger['id'], 500000);
        $transferLedger = $this->createLedgerAccount($agencyId);
        $transferAccount = $this->createCustomerAccount($agencyId, $client['id'], $transferLedger['id']);
        $loan->forceFill([
            'status' => Loan::STATUS_APPROVED,
            'approved_principal_minor' => 200000,
            'approved_on' => '2026-05-13',
            'transfer_account_id' => $transferAccount['id'],
        ])->save();

        // Disbursement is rejected while GET reports not-ready (shared readiness).
        $this->getSetupState($actor, $loan->public_id)->assertJsonPath('data.ready_for_disbursement', false);
        $this->disburse($actor, $loan->public_id)->assertStatus(422)->assertJsonValidationErrors(['disbursement']);

        $chargePublicIds = DB::table('loan_charge_assessments')->where('loan_id', $loan->id)->pluck('public_id', 'charge_type');
        $feeChargePublicId = $chargePublicIds->get('dossier_fee');
        $taxChargePublicId = $chargePublicIds->get('dossier_fee_tax');
        $guaranteeChargePublicId = $chargePublicIds->get('guarantee_deposit');
        self::assertIsString($feeChargePublicId);
        self::assertIsString($taxChargePublicId);
        self::assertIsString($guaranteeChargePublicId);

        // Collect one charge: it becomes paid while the others remain blocking.
        $this->assertJsonSuccess($this->collectFromAccount($actor, $loan->public_id, $feeChargePublicId, $collectionAccount['public_id'], 'collect-dossier_fee'));
        $afterOne = $this->getSetupState($actor, $loan->public_id);
        $afterOne->assertJsonPath('data.readiness_status', 'collection_pending');
        $afterOne->assertJsonPath('data.ready_for_disbursement', false);
        $paidCharge = $this->chargeByType($afterOne->json('data.setup_charges'), 'dossier_fee');
        self::assertSame('paid', $paidCharge['status']);
        self::assertFalse($paidCharge['blocking_disbursement']);
        self::assertFalse($paidCharge['collectable']);
        self::assertNotNull($paidCharge['paid_at']);
        self::assertIsString($paidCharge['journal_entry_public_id']);
        self::assertTrue($this->chargeByType($afterOne->json('data.setup_charges'), 'dossier_fee_tax')['blocking_disbursement']);

        // Collect the rest: tax from account and guarantee from cash. Loan assurance is not a premium collection step.
        $this->assertJsonSuccess($this->collectFromAccount($actor, $loan->public_id, $taxChargePublicId, $collectionAccount['public_id'], 'collect-dossier_fee_tax'));
        $this->assertJsonSuccess($this->withApiHeaders()->actingAsSanctum($actor)
            ->postJson('/api/v1/loans/'.$loan->public_id.'/setup-charges/'.$guaranteeChargePublicId.'/collect', [
                'payment_source' => 'teller_cash',
                'teller_session_public_id' => $session['public_id'],
                'paid_on' => '2026-05-13',
                'idempotency_key' => 'collect-guarantee_deposit',
            ]));
        self::assertSame(0, DB::table('insurance_premium_assessments')->where('loan_id', $loan->id)->count());

        // After all setup charges are collected: ready_for_disbursement true.
        $ready = $this->getSetupState($actor, $loan->public_id);
        $ready->assertJsonPath('data.readiness_status', 'ready');
        $ready->assertJsonPath('data.ready_for_disbursement', true);
        $ready->assertJsonPath('data.required_next_actions.0.action', 'disburse_loan');
        $ready->assertJsonPath('data.loan_assurance.blocking_disbursement', false);

        // include_setup_charges on GET /loans/{loan} reuses the same serializer.
        $loanShow = $this->withApiHeaders()->actingAsSanctum($actor)
            ->getJson('/api/v1/loans/'.$loan->public_id.'?include_setup_charges=true');
        $this->assertJsonSuccess($loanShow);
        $loanShow->assertJsonPath('data.setup_charges.readiness_status', 'ready');
        $loanShow->assertJsonPath('data.setup_charges.ready_for_disbursement', true);
        // Default loan show omits the projection.
        self::assertNull($this->withApiHeaders()->actingAsSanctum($actor)->getJson('/api/v1/loans/'.$loan->public_id)->json('data.setup_charges'));

        // Disbursement now succeeds (shared readiness with the GET endpoint).
        $disburse = $this->disburse($actor, $loan->public_id);
        $this->assertJsonSuccess($disburse);
        $disburse->assertJsonPath('data.loan.status', Loan::STATUS_DISBURSED);
    }

    public function test_loan_setup_charge_state_surfaces_direction_waiver_and_counts_it_ready(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agencyId = $this->createAgency('CR06W');
        $client = $this->createClientRecord($agencyId, 'verified');
        $product = $this->createLoanProduct($agencyId, [
            'interest_rate' => '10.000000',
            'rules' => [
                'setup_charges' => [
                    'dossier_fee_rate' => '3.000000',
                ],
            ],
        ]);
        $loan = Loan::query()->create([
            'public_id' => (string) Str::ulid(),
            'client_id' => $client['id'],
            'agency_id' => $agencyId,
            'loan_product_id' => $product->id,
            'loan_number' => 'LN-'.Str::ulid(),
            'requested_amount_minor' => 200000,
            'currency' => 'XAF',
            'applied_on' => now()->toDateString(),
            'status' => Loan::STATUS_APPLICATION,
        ]);

        $this->assertJsonSuccess($this->assessSetupCharges($actor, $loan->public_id));
        self::assertSame(1, DB::table('loan_charge_assessments')->where('loan_id', $loan->id)->count());
        self::assertSame(0, DB::table('insurance_premium_assessments')->where('loan_id', $loan->id)->count());

        $charge = DB::table('loan_charge_assessments')->where('loan_id', $loan->id)->first(['public_id']);
        self::assertIsObject($charge);
        self::assertIsString($charge->public_id);

        // The single dossier fee is blocking until a direction decision waives it.
        $this->getSetupState($actor, $loan->public_id)->assertJsonPath('data.ready_for_disbursement', false);

        $decision = $this->withApiHeaders()->actingAsSanctum($actor)
            ->postJson('/api/v1/loans/'.$loan->public_id.'/setup-charges/'.$charge->public_id.'/direction-decision', [
                'decision' => 'waive',
                'comments' => 'Waived by direction for a relationship client.',
            ]);
        $this->assertJsonSuccess($decision);

        $state = $this->getSetupState($actor, $loan->public_id);
        $waived = $this->chargeByType($state->json('data.setup_charges'), 'dossier_fee');
        self::assertSame('waived_by_direction', $waived['status']);
        self::assertFalse($waived['blocking_disbursement']);
        self::assertFalse($waived['collectable']);
        self::assertIsArray($waived['waiver_decision']);
        self::assertSame('waive', $waived['waiver_decision']['decision']);
        // The waiver is counted by the readiness calculation.
        $state->assertJsonPath('data.readiness_status', 'ready');
        $state->assertJsonPath('data.ready_for_disbursement', true);

        // Re-running assessment is idempotent and preserves the waived state.
        $this->assertJsonSuccess($this->assessSetupCharges($actor, $loan->public_id));
        self::assertSame(1, DB::table('loan_charge_assessments')->where('loan_id', $loan->id)->count());
        $reloaded = $this->getSetupState($actor, $loan->public_id);
        $reloaded->assertJsonPath('data.ready_for_disbursement', true);
        self::assertSame('waived_by_direction', $this->chargeByType($reloaded->json('data.setup_charges'), 'dossier_fee')['status']);
    }

    public function test_loan_setup_charge_state_matches_loan_view_scope_and_permissions(): void
    {
        $owner = $this->createUserWithRole('platform-admin');
        $loanAgencyId = $this->createAgency('CR06X');
        $client = $this->createClientRecord($loanAgencyId, 'verified');
        $product = $this->createLoanProduct($loanAgencyId, [
            'rules' => ['setup_charges' => ['dossier_fee_rate' => '3.000000']],
        ]);
        $loan = Loan::query()->create([
            'public_id' => (string) Str::ulid(),
            'client_id' => $client['id'],
            'agency_id' => $loanAgencyId,
            'loan_product_id' => $product->id,
            'loan_number' => 'LN-'.Str::ulid(),
            'requested_amount_minor' => 200000,
            'currency' => 'XAF',
            'applied_on' => now()->toDateString(),
            'status' => Loan::STATUS_APPLICATION,
        ]);
        $this->assertJsonSuccess($this->assessSetupCharges($owner, $loan->public_id));

        // Positive control: a loan officer scoped to the loan agency CAN inspect
        // setup charges, so the cross-agency 403 below is attributable to scope,
        // not an unrelated failure that would make the assertion vacuous.
        $sameAgencyOfficer = $this->createUserWithRole('loan-officer');
        $this->assignUserToAgency($sameAgencyOfficer, $loanAgencyId, 'loan-officer');
        $this->assertJsonSuccess($this->getSetupState($sameAgencyOfficer, $loan->public_id));

        // A loan officer scoped to a different agency cannot inspect setup charges.
        $otherAgencyId = $this->createAgency('CR06Y');
        $crossAgencyOfficer = $this->createUserWithRole('loan-officer');
        $this->assignUserToAgency($crossAgencyOfficer, $otherAgencyId, 'loan-officer');
        $this->getSetupState($crossAgencyOfficer, $loan->public_id)->assertForbidden();

        // A user lacking loans.view (teller) is denied even inside the loan agency.
        $teller = $this->createUserWithRole('teller');
        $this->assignUserToAgency($teller, $loanAgencyId, 'teller');
        $this->getSetupState($teller, $loan->public_id)->assertForbidden();
    }

    private function getSetupState(User $actor, string $loanPublicId): TestResponse
    {
        return $this->withApiHeaders()->actingAsSanctum($actor)
            ->getJson('/api/v1/loans/'.$loanPublicId.'/setup-charges');
    }

    private function assessSetupCharges(User $actor, string $loanPublicId): TestResponse
    {
        return $this->withApiHeaders()->actingAsSanctum($actor)
            ->postJson('/api/v1/loans/'.$loanPublicId.'/setup-charges/assess');
    }

    private function collectFromAccount(User $actor, string $loanPublicId, string $chargePublicId, string $accountPublicId, string $idempotencyKey): TestResponse
    {
        return $this->withApiHeaders()->actingAsSanctum($actor)
            ->postJson('/api/v1/loans/'.$loanPublicId.'/setup-charges/'.$chargePublicId.'/collect', [
                'customer_account_public_id' => $accountPublicId,
                'paid_on' => '2026-05-13',
                'idempotency_key' => $idempotencyKey,
            ]);
    }

    private function disburse(User $actor, string $loanPublicId): TestResponse
    {
        return $this->withApiHeaders()->actingAsSanctum($actor)
            ->postJson('/api/v1/loans/'.$loanPublicId.'/disburse', [
                'disbursement_channel' => 'transfer_account',
                'business_date' => '2026-05-13',
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function chargeByType(mixed $charges, string $type): array
    {
        self::assertIsArray($charges);
        foreach ($charges as $charge) {
            if (is_array($charge) && ($charge['charge_type'] ?? null) === $type) {
                return $charge;
            }
        }

        self::fail('Charge type '.$type.' is not present in the setup state.');
    }

    private function assignUserToAgency(User $user, int $agencyId, string $roleAtAgency): void
    {
        DB::table('staff_agency_assignments')->insert([
            'public_id' => (string) Str::ulid(),
            'user_id' => $user->id,
            'agency_id' => $agencyId,
            'role_at_agency' => $roleAtAgency,
            'starts_on' => now()->toDateString(),
            'ends_on' => null,
            'is_primary' => true,
            'status' => StaffAgencyAssignment::STATUS_ACTIVE,
        ]);
    }

    public function test_global_loan_product_disburses_across_agencies_via_agency_specific_principal_mappings(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agencyA = $this->createAgency('MAPDA');
        $agencyB = $this->createAgency('MAPDB');
        $clientA = $this->createClientRecord($agencyA, 'verified');
        $clientB = $this->createClientRecord($agencyB, 'verified');

        // A single global product (product ledger lives in agency A) used by both agencies.
        $product = $this->createLoanProduct($agencyA);

        // Agency A: an approved principal mapping pointing at a dedicated A ledger
        // (distinct from the product ledger) proves the mapping is preferred.
        $principalLedgerA = $this->createLedgerAccount($agencyA);
        $this->createOperationMapping('loan_principal_disbursement', 'loan', $agencyA, $principalLedgerA['id'], null);
        $loanA = $this->makeApprovedLoan($agencyA, $clientA['id'], $product);
        $disburseA = $this->disburse($actor, $loanA->public_id);
        $this->assertJsonSuccess($disburseA);
        $disburseA->assertJsonPath('data.loan.status', Loan::STATUS_DISBURSED);
        $this->assertDatabaseHas('journal_lines', [
            'loan_id' => $loanA->id,
            'ledger_account_id' => $principalLedgerA['id'],
            'debit_minor' => 200000,
        ]);

        // Agency B: same global product, no B mapping yet — product ledger is in A,
        // so disbursement is rejected with a precise missing-mapping error.
        $loanB = $this->makeApprovedLoan($agencyB, $clientB['id'], $product);
        $missing = $this->disburse($actor, $loanB->public_id);
        $missing->assertStatus(422)->assertJsonValidationErrors(['disbursement']);
        self::assertStringContainsString('loan principal disbursement ledger mapping', $this->stringJson($missing, 'errors.disbursement.0'));

        // Configure B's agency-specific principal mapping → disburses into B's ledger.
        $principalLedgerB = $this->createLedgerAccount($agencyB);
        $this->createOperationMapping('loan_principal_disbursement', 'loan', $agencyB, $principalLedgerB['id'], null);
        $disburseB = $this->disburse($actor, $loanB->public_id);
        $this->assertJsonSuccess($disburseB);
        $disburseB->assertJsonPath('data.loan.status', Loan::STATUS_DISBURSED);
        $this->assertDatabaseHas('journal_lines', [
            'loan_id' => $loanB->id,
            'ledger_account_id' => $principalLedgerB['id'],
            'debit_minor' => 200000,
        ]);
    }

    public function test_disbursement_surfaces_precise_errors_for_unusable_principal_mappings(): void
    {
        $actor = $this->createUserWithRole('platform-admin');

        // Unapproved mapping → 422 "not approved".
        $agency1 = $this->createAgency('MAPU1');
        $client1 = $this->createClientRecord($agency1, 'verified');
        $product1 = $this->createLoanProduct($agency1);
        $ledger1 = $this->createLedgerAccount($agency1);
        $this->createOperationMapping('loan_principal_disbursement', 'loan', $agency1, $ledger1['id'], null, ['approval_status' => 'draft']);
        $loan1 = $this->makeApprovedLoan($agency1, $client1['id'], $product1);
        $r1 = $this->disburse($actor, $loan1->public_id);
        $r1->assertStatus(422)->assertJsonValidationErrors(['disbursement']);
        self::assertStringContainsString('not approved', $this->stringJson($r1, 'errors.disbursement.0'));

        // Expired effective window → 422 "outside its effective date window".
        $agency2 = $this->createAgency('MAPU2');
        $client2 = $this->createClientRecord($agency2, 'verified');
        $product2 = $this->createLoanProduct($agency2);
        $ledger2 = $this->createLedgerAccount($agency2);
        $this->createOperationMapping('loan_principal_disbursement', 'loan', $agency2, $ledger2['id'], null, ['effective_from' => '2020-01-01', 'effective_to' => '2020-12-31']);
        $loan2 = $this->makeApprovedLoan($agency2, $client2['id'], $product2);
        $r2 = $this->disburse($actor, $loan2->public_id);
        $r2->assertStatus(422);
        self::assertStringContainsString('effective date window', $this->stringJson($r2, 'errors.disbursement.0'));

        // Mapping pointing at a cross-agency ledger → 422 "cross-agency".
        $agency3 = $this->createAgency('MAPU3');
        $otherAgency = $this->createAgency('MAPU3X');
        $client3 = $this->createClientRecord($agency3, 'verified');
        $product3 = $this->createLoanProduct($agency3);
        $crossLedger = $this->createLedgerAccount($otherAgency);
        $this->createOperationMapping('loan_principal_disbursement', 'loan', $agency3, $crossLedger['id'], null);
        $loan3 = $this->makeApprovedLoan($agency3, $client3['id'], $product3);
        $r3 = $this->disburse($actor, $loan3->public_id);
        $r3->assertStatus(422);
        self::assertStringContainsString('cross-agency', $this->stringJson($r3, 'errors.disbursement.0'));
    }

    public function test_setup_charge_collection_requires_approved_mapping_like_disbursement(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agencyId = $this->createAgency('MAPSC');
        $client = $this->createClientRecord($agencyId, 'verified');
        $product = $this->createLoanProduct($agencyId, [
            'rules' => ['setup_charges' => ['dossier_fee_rate' => '3.000000']],
        ]);
        $loan = Loan::query()->create([
            'public_id' => (string) Str::ulid(),
            'client_id' => $client['id'],
            'agency_id' => $agencyId,
            'loan_product_id' => $product->id,
            'loan_number' => 'LN-'.Str::ulid(),
            'requested_amount_minor' => 200000,
            'currency' => 'XAF',
            'applied_on' => now()->toDateString(),
            'status' => Loan::STATUS_APPLICATION,
        ]);

        $this->assertJsonSuccess($this->assessSetupCharges($actor, $loan->public_id));

        // A draft (unapproved) credit mapping must be rejected by collection, the
        // same approval gate disbursement enforces.
        $feeLedger = $this->createRevenueLedger($agencyId, 'SC-FEE');
        $this->createOperationMapping('loan_setup_dossier_fee', 'loan', $agencyId, null, $feeLedger, ['approval_status' => 'draft']);

        $collectionLedgerId = $this->createCustomerLiabilityLedger($agencyId, 'SC-COLLECT');
        $collectionAccount = $this->createCustomerAccount($agencyId, $client['id'], $collectionLedgerId);
        $this->fundCustomerAccount($agencyId, $collectionAccount['id'], $collectionLedgerId, 6000);

        $chargePublicId = DB::table('loan_charge_assessments')->where('loan_id', $loan->id)->where('charge_type', 'dossier_fee')->value('public_id');
        self::assertIsString($chargePublicId);

        $collect = $this->collectFromAccount($actor, $loan->public_id, $chargePublicId, $collectionAccount['public_id'], 'collect-draft-fee');
        $collect->assertStatus(422);
        self::assertStringContainsString('not approved', $this->stringJson($collect, 'errors.setup_charge_collection.0'));
    }

    public function test_operation_account_mapping_api_configures_agency_mappings_and_rejects_overlap(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('MAPAPI');
        $debit = $this->createLedgerAccount($agency);
        $credit = $this->createLedgerAccount($agency);
        $operationCode = $this->createOperationCodeRecord('loan_principal_disbursement', 'loan');
        $agencyPublicId = $this->agencyPublicId($agency);

        $create = $this->withApiHeaders()->actingAsSanctum($actor)
            ->postJson('/api/v1/operation-account-mappings', [
                'operation_code_public_id' => $operationCode['public_id'],
                'agency_public_id' => $agencyPublicId,
                'debit_ledger_account_public_id' => $debit['public_id'],
                'credit_ledger_account_public_id' => $credit['public_id'],
                'currency' => 'XAF',
                'effective_from' => '2026-01-01',
                'approval_status' => 'approved',
            ]);
        $this->assertJsonSuccess($create, 201);
        $create->assertJsonPath('data.agency_public_id', $agencyPublicId);
        $create->assertJsonPath('data.approval_status', 'approved');
        $create->assertJsonPath('data.effective_from', '2026-01-01');
        $firstPublicId = $this->requireStringJsonPath($create, 'data.public_id');

        // A second active, approved mapping for the same scope and overlapping
        // window is rejected.
        $overlap = $this->withApiHeaders()->actingAsSanctum($actor)
            ->postJson('/api/v1/operation-account-mappings', [
                'operation_code_public_id' => $operationCode['public_id'],
                'agency_public_id' => $agencyPublicId,
                'debit_ledger_account_public_id' => $debit['public_id'],
                'credit_ledger_account_public_id' => $credit['public_id'],
                'currency' => 'XAF',
                'effective_from' => '2026-06-01',
                'approval_status' => 'approved',
            ]);
        $overlap->assertStatus(422)->assertJsonValidationErrors(['effective_from']);

        // Suspending the first frees the scope for a new approved mapping.
        $suspend = $this->withApiHeaders()->actingAsSanctum($actor)
            ->patchJson('/api/v1/operation-account-mappings/'.$firstPublicId, ['approval_status' => 'suspended']);
        $this->assertJsonSuccess($suspend);
        $suspend->assertJsonPath('data.approval_status', 'suspended');

        $second = $this->withApiHeaders()->actingAsSanctum($actor)
            ->postJson('/api/v1/operation-account-mappings', [
                'operation_code_public_id' => $operationCode['public_id'],
                'agency_public_id' => $agencyPublicId,
                'debit_ledger_account_public_id' => $debit['public_id'],
                'credit_ledger_account_public_id' => $credit['public_id'],
                'currency' => 'XAF',
                'effective_from' => '2026-06-01',
                'approval_status' => 'approved',
            ]);
        $this->assertJsonSuccess($second, 201);
    }

    public function test_operation_account_mapping_readiness_reports_blockers_per_operation(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('MAPRDY');
        $agencyPublicId = $this->agencyPublicId($agency);
        $url = '/api/v1/operation-account-mappings/readiness?agency_public_id='.$agencyPublicId.'&currency=XAF';

        // No mappings configured: every operation is missing and not ready.
        $empty = $this->withApiHeaders()->actingAsSanctum($actor)->getJson($url);
        $this->assertJsonSuccess($empty);
        $empty->assertJsonPath('data.ready', false);
        self::assertSame('missing', $this->readinessStatus($empty, 'loan_principal_disbursement'));
        self::assertFalse($this->readinessHasOperation($empty, 'loan_insurance_premium'));

        // Configure approved mappings for every loan posting operation.
        $this->createOperationMapping('loan_principal_disbursement', 'loan', $agency, $this->createLedgerAccount($agency)['id'], null);
        foreach (['loan_setup_dossier_fee', 'loan_setup_tax', 'loan_setup_guarantee_deposit'] as $code) {
            $this->createOperationMapping($code, 'loan', $agency, null, $this->createLedgerAccount($agency)['id']);
        }

        $ready = $this->withApiHeaders()->actingAsSanctum($actor)->getJson($url);
        $ready->assertJsonPath('data.ready', true);
        self::assertSame('ready', $this->readinessStatus($ready, 'loan_principal_disbursement'));

        // Make a setup-charge mapping unapproved -> reported as unapproved, not ready.
        DB::table('operation_account_mappings as m')
            ->join('operation_codes as oc', 'oc.id', '=', 'm.operation_code_id')
            ->where('oc.code', 'loan_setup_guarantee_deposit')
            ->where('m.agency_id', $agency)
            ->update(['m.approval_status' => 'draft']);
        $unapproved = $this->withApiHeaders()->actingAsSanctum($actor)->getJson($url);
        $unapproved->assertJsonPath('data.ready', false);
        self::assertSame('unapproved', $this->readinessStatus($unapproved, 'loan_setup_guarantee_deposit'));

        // A duplicate approved principal mapping is reported as overlapping.
        $this->createOperationMapping('loan_principal_disbursement', 'loan', $agency, $this->createLedgerAccount($agency)['id'], null);
        $overlapping = $this->withApiHeaders()->actingAsSanctum($actor)->getJson($url);
        self::assertSame('overlapping', $this->readinessStatus($overlapping, 'loan_principal_disbursement'));
    }

    private function makeApprovedLoan(int $agencyId, int $clientId, LoanProduct $product, int $principalMinor = 200000): Loan
    {
        $loan = Loan::query()->create([
            'public_id' => (string) Str::ulid(),
            'client_id' => $clientId,
            'agency_id' => $agencyId,
            'loan_product_id' => $product->id,
            'loan_number' => 'LN-'.Str::ulid(),
            'requested_amount_minor' => $principalMinor,
            'currency' => 'XAF',
            'applied_on' => now()->toDateString(),
            'status' => Loan::STATUS_APPLICATION,
        ]);

        $transferLedger = $this->createLedgerAccount($agencyId);
        $transferAccount = $this->createCustomerAccount($agencyId, $clientId, $transferLedger['id']);
        $loan->forceFill([
            'status' => Loan::STATUS_APPROVED,
            'approved_principal_minor' => $principalMinor,
            'approved_on' => '2026-05-13',
            'transfer_account_id' => $transferAccount['id'],
        ])->save();

        return $loan;
    }

    /**
     * @param  array<string, mixed>  $opts
     */
    private function createOperationMapping(string $code, string $module, ?int $agencyId, ?int $debitLedgerId, ?int $creditLedgerId, array $opts = []): string
    {
        $operationCode = $this->createOperationCodeRecord($code, $module);
        $publicId = (string) Str::ulid();
        DB::table('operation_account_mappings')->insert([
            'public_id' => $publicId,
            'operation_code_id' => $operationCode['id'],
            'agency_id' => $agencyId,
            'debit_ledger_account_id' => $debitLedgerId,
            'credit_ledger_account_id' => $creditLedgerId,
            'currency' => $opts['currency'] ?? 'XAF',
            'effective_from' => $opts['effective_from'] ?? null,
            'effective_to' => $opts['effective_to'] ?? null,
            'status' => $opts['status'] ?? 'active',
            'approval_status' => $opts['approval_status'] ?? 'approved',
            'rules' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $publicId;
    }

    /**
     * @return array{id:int, public_id:string}
     */
    private function createOperationCodeRecord(string $code, string $module): array
    {
        $existing = DB::table('operation_codes')->where('code', $code)->first(['id', 'public_id']);
        if (is_object($existing) && is_int($existing->id) && is_string($existing->public_id)) {
            return ['id' => $existing->id, 'public_id' => $existing->public_id];
        }

        $publicId = (string) Str::ulid();
        $id = DB::table('operation_codes')->insertGetId([
            'public_id' => $publicId,
            'code' => $code,
            'label' => str_replace('_', ' ', $code),
            'module' => $module,
            'operation_type' => 'posting',
            'direction' => 'debit_credit',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['id' => $id, 'public_id' => $publicId];
    }

    private function agencyPublicId(int $agencyId): string
    {
        $publicId = DB::table('agencies')->where('id', $agencyId)->value('public_id');
        self::assertIsString($publicId);

        return $publicId;
    }

    private function readinessStatus(TestResponse $response, string $operationCode): string
    {
        $operations = $response->json('data.operations');
        self::assertIsArray($operations);
        foreach ($operations as $operation) {
            if (is_array($operation) && ($operation['operation_code'] ?? null) === $operationCode) {
                self::assertIsString($operation['status']);

                return $operation['status'];
            }
        }

        self::fail('Operation '.$operationCode.' missing from readiness response.');
    }

    private function readinessHasOperation(TestResponse $response, string $operationCode): bool
    {
        $operations = $response->json('data.operations');
        self::assertIsArray($operations);
        foreach ($operations as $operation) {
            if (is_array($operation) && ($operation['operation_code'] ?? null) === $operationCode) {
                return true;
            }
        }

        return false;
    }

    private function stringJson(TestResponse $response, string $path): string
    {
        $value = $response->json($path);

        return is_string($value) ? $value : '';
    }
}
