<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Client;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class InsuranceModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_platform_admin_can_create_insurance_product_and_process_claim_lifecycle(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('INS01');
        $ledger = $this->createLedgerAccount($agency['id']);
        $client = $this->createClient($agency['id']);

        $partner = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/insurance-partners', [
                'agency_public_id' => $agency['public_id'],
                'ledger_account_public_id' => $ledger['public_id'],
                'code' => 'INS-PARTNER-1',
                'name' => 'Insurance Partner',
                'email' => 'claims@example.test',
            ]);
        $this->assertJsonSuccess($partner, 201);
        $partnerPublicId = $this->requireStringJsonPath($partner, 'data.public_id');

        $product = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/insurance-products', [
                'insurance_partner_public_id' => $partnerPublicId,
                'code' => 'LOAN-COVER-1',
                'name' => 'Loan Cover',
                'product_type' => 'loan_insurance',
                'premium_calculation_type' => 'percentage',
                'premium_rate' => '2.000000',
                'currency' => 'XAF',
                'payment_mode' => 'upfront',
                'coverages' => [
                    [
                        'coverage_code' => 'DEATH',
                        'coverage_name' => 'Death cover',
                    ],
                ],
            ]);
        $this->assertJsonSuccess($product, 201);
        $productPublicId = $this->requireStringJsonPath($product, 'data.public_id');
        $productId = $this->requireIntId('insurance_products', $productPublicId);

        $this->createInsurancePremiumCollectionMapping($ledger['id']);
        $claimDebitLedger = $this->createLedgerAccount($agency['id']);
        $claimCreditLedger = $this->createLedgerAccount($agency['id']);
        $this->createInsuranceClaimSettlementMapping($claimDebitLedger['id'], $claimCreditLedger['id']);
        DB::table('insurance_product_rule_versions')->insert([
            'public_id' => (string) Str::ulid(),
            'insurance_product_id' => $productId,
            'version_number' => 1,
            'calculation_type' => 'flat_rate',
            'base_description' => 'insured_amount',
            'rate' => null,
            'fixed_premium_minor' => 15000,
            'cap_minor' => null,
            'floor_minor' => null,
            'frequency' => 'one_time',
            'source_reference' => 'test-contract',
            'effective_from' => '2026-01-01',
            'effective_until' => null,
            'status' => 'approved',
            'created_by_user_id' => $actor->id,
            'approved_by_user_id' => $actor->id,
            'approved_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('insurance_claim_evidence_configs')->insert([
            'public_id' => (string) Str::ulid(),
            'insurance_product_id' => $productId,
            'claim_type' => 'standard',
            'document_type' => 'claim_form',
            'is_required' => true,
            'description' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('insurance_products')
            ->where('id', $productId)
            ->update([
                'business_model' => 'broker',
                'report_category' => 'operations',
                'updated_at' => now(),
            ]);

        $activation = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/insurance-products/'.$productPublicId.'/activate');
        $this->assertJsonSuccess($activation);

        $subscription = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/insurance-subscriptions', [
                'client_public_id' => $client['public_id'],
                'agency_public_id' => $agency['public_id'],
                'insurance_product_public_id' => $productPublicId,
                'starts_on' => '2026-05-13',
                'coverage_amount_minor' => 500000,
                'currency' => 'XAF',
            ]);
        $this->assertJsonSuccess($subscription, 201);
        $subscriptionPublicId = $this->requireStringJsonPath($subscription, 'data.public_id');

        $claim = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/insurance-claims', [
                'insurance_subscription_public_id' => $subscriptionPublicId,
                'claim_type' => 'death',
                'incident_date' => '2026-05-14',
                'claimed_amount_minor' => 300000,
                'currency' => 'XAF',
                'description' => 'Borrower claim file opened.',
            ]);
        $this->assertJsonSuccess($claim, 201);
        $claimPublicId = $this->requireStringJsonPath($claim, 'data.public_id');
        $claim->assertJsonPath('data.status', 'pending');

        $maker = $this->createUserWithRole('platform-admin');
        $checker = $this->createUserWithRole('platform-admin');

        $requested = $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/insurance-claims/'.$claimPublicId.'/decision-requests', [
                'decision' => 'settle',
                'indemnified_amount_minor' => 250000,
                'settled_on' => '2026-05-20',
            ]);
        $this->assertJsonSuccess($requested, 201);
        $decisionPublicId = $this->requireStringJsonPath($requested, 'data.public_id');

        $settled = $this->withApiHeaders()
            ->actingAsSanctum($checker)
            ->postJson('/api/v1/insurance-claim-decisions/'.$decisionPublicId.'/review', [
                'review_decision' => 'approve',
            ]);
        $this->assertJsonSuccess($settled);
        $settled->assertJsonPath('data.claim.status', 'settled');
        $settled->assertJsonPath('data.claim.indemnified_amount_minor', 250000);

        $this->assertDatabaseHas('insurance_product_coverages', [
            'coverage_code' => 'DEATH',
            'coverage_name' => 'Death cover',
        ]);
        $this->assertDatabaseHas('insurance_claims', [
            'public_id' => $claimPublicId,
            'status' => 'settled',
            'indemnified_amount_minor' => 250000,
        ]);
    }

    public function test_standalone_premium_assessment_can_be_created_for_active_subscription(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        [$subscriptionPublicId] = $this->createStandaloneSubscription($actor);

        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/insurance-subscriptions/'.$subscriptionPublicId.'/premium-assessments', [
                'premium_amount_minor' => 15000,
                'due_on' => '2026-06-30',
                'base_amount_minor' => 500000,
                'rate' => '3.000000',
            ]);

        $this->assertJsonSuccess($response, 201);
        $response->assertJsonPath('data.status', 'assessed');
        $response->assertJsonPath('data.premium_amount_minor', 15000);
        $response->assertJsonPath('data.due_on', '2026-06-30');
        $response->assertJsonPath('data.currency', 'XAF');
        $response->assertJsonPath('data.subscription_public_id', $subscriptionPublicId);

        $assessmentPublicId = $this->requireStringJsonPath($response, 'data.public_id');
        $this->assertDatabaseHas('insurance_premium_assessments', [
            'public_id' => $assessmentPublicId,
            'status' => 'assessed',
            'premium_amount_minor' => 15000,
            'currency' => 'XAF',
            'journal_entry_id' => null,
        ]);
        $this->assertDatabaseMissing('journal_entries', [
            'source_public_id' => $assessmentPublicId,
        ]);
    }

    public function test_standalone_premium_assessment_rejects_inactive_subscription(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        [$subscriptionPublicId, $subscriptionId] = $this->createStandaloneSubscription($actor);

        DB::table('insurance_subscriptions')
            ->where('id', $subscriptionId)
            ->update(['status' => 'cancelled']);

        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/insurance-subscriptions/'.$subscriptionPublicId.'/premium-assessments', [
                'premium_amount_minor' => 15000,
                'due_on' => '2026-06-30',
            ]);

        $this->assertJsonError($response, 422);
        $this->assertDatabaseMissing('insurance_premium_assessments', [
            'insurance_subscription_id' => $subscriptionId,
        ]);
    }

    public function test_standalone_premium_assessment_rejects_currency_mismatch(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        [$subscriptionPublicId] = $this->createStandaloneSubscription($actor);

        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/insurance-subscriptions/'.$subscriptionPublicId.'/premium-assessments', [
                'premium_amount_minor' => 15000,
                'due_on' => '2026-06-30',
                'currency' => 'EUR',
            ]);

        $this->assertJsonError($response, 422);
    }

    public function test_standalone_premium_assessment_rejects_non_positive_amount(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        [$subscriptionPublicId] = $this->createStandaloneSubscription($actor);

        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/insurance-subscriptions/'.$subscriptionPublicId.'/premium-assessments', [
                'premium_amount_minor' => 0,
                'due_on' => '2026-06-30',
            ]);

        $this->assertJsonError($response, 422);
    }

    public function test_premium_collection_from_account_posts_journal_and_marks_assessment_paid(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $context = $this->createCollectionContext($actor, premiumMinor: 12000, accountFundingMinor: 50000);

        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/insurance-premium-assessments/'.$context['assessment_public_id'].'/collect-from-account', [
                'customer_account_public_id' => $context['customer_account_public_id'],
                'paid_on' => '2026-05-15',
            ]);

        $this->assertJsonSuccess($response);
        $response->assertJsonPath('data.assessment.status', 'paid');
        $response->assertJsonPath('data.payment.status', 'posted');
        $response->assertJsonPath('data.payment.amount_minor', 12000);
        $response->assertJsonPath('data.payment.currency', 'XAF');

        $this->assertDatabaseHas('insurance_premium_assessments', [
            'public_id' => $context['assessment_public_id'],
            'status' => 'paid',
        ]);
        $this->assertDatabaseHas('insurance_premium_payments', [
            'insurance_premium_assessment_id' => $context['assessment_id'],
            'customer_account_id' => $context['customer_account_id'],
            'amount_minor' => 12000,
            'currency' => 'XAF',
            'payment_method' => 'customer_account',
            'status' => 'posted',
        ]);

        $journalEntryPublicId = $response->json('data.journal_entry_public_id');
        self::assertIsString($journalEntryPublicId);
        $this->assertDatabaseHas('journal_entries', [
            'public_id' => $journalEntryPublicId,
            'status' => JournalEntry::STATUS_POSTED,
            'source_module' => 'insurance',
            'source_type' => 'insurance_premium_payment',
            'source_public_id' => $context['assessment_public_id'],
        ]);

        $journalEntryId = $this->requireIntId('journal_entries', $journalEntryPublicId);
        $lines = DB::table('journal_lines')->where('journal_entry_id', $journalEntryId)->orderBy('id')->get();
        self::assertCount(2, $lines);
        $first = $lines[0];
        $second = $lines[1];
        self::assertIsObject($first);
        self::assertIsObject($second);
        self::assertSame(12000, $this->requireIntFromRow($first, 'debit_minor'));
        self::assertSame(0, $this->requireIntFromRow($first, 'credit_minor'));
        self::assertSame($context['customer_account_id'], $this->requireIntFromRow($first, 'customer_account_id'));
        self::assertSame(0, $this->requireIntFromRow($second, 'debit_minor'));
        self::assertSame(12000, $this->requireIntFromRow($second, 'credit_minor'));
    }

    public function test_premium_collection_from_account_requires_active_operation_mapping(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $context = $this->createCollectionContext($actor, premiumMinor: 12000, accountFundingMinor: 50000, createMapping: false);

        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/insurance-premium-assessments/'.$context['assessment_public_id'].'/collect-from-account', [
                'customer_account_public_id' => $context['customer_account_public_id'],
            ]);

        $this->assertJsonError($response, 422);
        $this->assertDatabaseHas('insurance_premium_assessments', [
            'public_id' => $context['assessment_public_id'],
            'status' => 'assessed',
        ]);
        $this->assertDatabaseMissing('insurance_premium_payments', [
            'insurance_premium_assessment_id' => $context['assessment_id'],
        ]);
        $this->assertDatabaseMissing('journal_entries', [
            'source_public_id' => $context['assessment_public_id'],
        ]);
    }

    public function test_premium_collection_from_account_rejects_insufficient_balance(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $context = $this->createCollectionContext($actor, premiumMinor: 100000, accountFundingMinor: 5000);

        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/insurance-premium-assessments/'.$context['assessment_public_id'].'/collect-from-account', [
                'customer_account_public_id' => $context['customer_account_public_id'],
            ]);

        $this->assertJsonError($response, 422);
        $this->assertDatabaseHas('insurance_premium_assessments', [
            'public_id' => $context['assessment_public_id'],
            'status' => 'assessed',
        ]);
        $this->assertDatabaseMissing('insurance_premium_payments', [
            'insurance_premium_assessment_id' => $context['assessment_id'],
        ]);
    }

    public function test_premium_collection_from_account_rejects_wrong_client_account(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $context = $this->createCollectionContext($actor, premiumMinor: 12000, accountFundingMinor: 50000);

        $foreignClient = $this->createClient($context['agency_id']);
        $foreignLedger = $this->createLedgerAccount($context['agency_id']);
        $foreignAccount = $this->createCustomerAccountFor(
            agencyId: $context['agency_id'],
            clientId: $foreignClient['id'],
            ledgerAccountId: $foreignLedger['id'],
        );
        $this->fundCustomerAccount(
            agencyId: $context['agency_id'],
            customerAccountId: $foreignAccount['id'],
            customerLedgerAccountId: $foreignLedger['id'],
            amountMinor: 50000,
            actorUserId: $actor->id,
        );

        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/insurance-premium-assessments/'.$context['assessment_public_id'].'/collect-from-account', [
                'customer_account_public_id' => $foreignAccount['public_id'],
            ]);

        $this->assertJsonError($response, 422);
        $this->assertDatabaseHas('insurance_premium_assessments', [
            'public_id' => $context['assessment_public_id'],
            'status' => 'assessed',
        ]);
    }

    public function test_premium_collection_from_account_rejects_duplicate_collection(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $context = $this->createCollectionContext($actor, premiumMinor: 12000, accountFundingMinor: 100000);

        $first = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/insurance-premium-assessments/'.$context['assessment_public_id'].'/collect-from-account', [
                'customer_account_public_id' => $context['customer_account_public_id'],
            ]);
        $this->assertJsonSuccess($first);

        $second = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/insurance-premium-assessments/'.$context['assessment_public_id'].'/collect-from-account', [
                'customer_account_public_id' => $context['customer_account_public_id'],
            ]);
        $this->assertJsonError($second, 422);

        self::assertSame(1, DB::table('insurance_premium_payments')
            ->where('insurance_premium_assessment_id', $context['assessment_id'])
            ->count());
    }

    public function test_active_subscriptions_report_returns_active_rows_only(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $context = $this->createReportSeedContext($actor);

        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->getJson('/api/v1/insurance-reports/active-subscriptions');

        $this->assertJsonSuccess($response);
        $response->assertJsonPath('data.totals.count', 2);
        $response->assertJsonPath('data.totals.coverage_amount_minor', 1500000);
        $response->assertJsonPath('meta.report', 'active_subscriptions');
        unset($context);
    }

    public function test_premiums_report_aggregates_by_status(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $context = $this->createReportSeedContext($actor);

        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->getJson('/api/v1/insurance-reports/premiums');

        $this->assertJsonSuccess($response);
        $response->assertJsonPath('data.by_status.assessed.count', 1);
        $response->assertJsonPath('data.by_status.assessed.amount_minor', 25000);
        $response->assertJsonPath('data.by_status.paid.count', 1);
        $response->assertJsonPath('data.by_status.paid.amount_minor', 15000);
        $response->assertJsonPath('data.totals.count', 2);
        $response->assertJsonPath('data.totals.amount_minor', 40000);
        unset($context);
    }

    public function test_unpaid_premiums_report_lists_assessed_only(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $context = $this->createReportSeedContext($actor);

        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->getJson('/api/v1/insurance-reports/unpaid-premiums');

        $this->assertJsonSuccess($response);
        $response->assertJsonPath('data.totals.count', 1);
        $response->assertJsonPath('data.totals.amount_minor', 25000);
        unset($context);
    }

    public function test_claims_report_groups_claims_by_status(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $context = $this->createReportSeedContext($actor);

        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->getJson('/api/v1/insurance-reports/claims');

        $this->assertJsonSuccess($response);
        $response->assertJsonPath('data.by_status.pending.count', 1);
        $response->assertJsonPath('data.totals.count', 1);
        $response->assertJsonPath('data.totals.claimed_amount_minor', 100000);
        unset($context);
    }

    public function test_expiring_coverage_report_filters_by_window(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $context = $this->createReportSeedContext($actor);

        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->getJson('/api/v1/insurance-reports/expiring-coverage?period_start=2026-05-18&period_end=2026-09-30');

        $this->assertJsonSuccess($response);
        $response->assertJsonPath('data.totals.count', 1);
        unset($context);
    }

    public function test_active_subscriptions_report_respects_agency_scope_for_agency_manager(): void
    {
        $platformAdmin = $this->createUserWithRole('platform-admin');
        $contextA = $this->createReportSeedContext($platformAdmin);

        $agencyB = $this->createAgency('B-'.Str::random(4));
        $managerB = $this->createUserWithRole('agency-manager');
        $this->assignStaffToAgency($managerB, $agencyB['id']);

        $ledgerB = $this->createLedgerAccount($agencyB['id']);
        $clientB = $this->createClient($agencyB['id']);
        $partnerB = $this->withApiHeaders()
            ->actingAsSanctum($platformAdmin)
            ->postJson('/api/v1/insurance-partners', [
                'agency_public_id' => $agencyB['public_id'],
                'ledger_account_public_id' => $ledgerB['public_id'],
                'code' => 'INS-PARTNER-B-'.Str::random(4),
                'name' => 'Partner B',
            ]);
        $this->assertJsonSuccess($partnerB, 201);
        $partnerBPublicId = $this->requireStringJsonPath($partnerB, 'data.public_id');

        $productB = $this->withApiHeaders()
            ->actingAsSanctum($platformAdmin)
            ->postJson('/api/v1/insurance-products', [
                'insurance_partner_public_id' => $partnerBPublicId,
                'code' => 'PROD-B-'.Str::random(4),
                'name' => 'Product B',
                'product_type' => 'health',
                'currency' => 'XAF',
            ]);
        $this->assertJsonSuccess($productB, 201);
        $productBPublicId = $this->requireStringJsonPath($productB, 'data.public_id');
        $productBId = $this->requireIntId('insurance_products', $productBPublicId);
        $this->createInsurancePremiumCollectionMapping($ledgerB['id']);
        $claimDebitLedgerB = $this->createLedgerAccount($agencyB['id']);
        $claimCreditLedgerB = $this->createLedgerAccount($agencyB['id']);
        $this->createInsuranceClaimSettlementMapping($claimDebitLedgerB['id'], $claimCreditLedgerB['id']);
        DB::table('insurance_product_rule_versions')->insert([
            'public_id' => (string) Str::ulid(),
            'insurance_product_id' => $productBId,
            'version_number' => 1,
            'calculation_type' => 'flat_rate',
            'base_description' => 'insured_amount',
            'rate' => null,
            'fixed_premium_minor' => 15000,
            'cap_minor' => null,
            'floor_minor' => null,
            'frequency' => 'one_time',
            'source_reference' => 'test-contract',
            'effective_from' => '2026-01-01',
            'effective_until' => null,
            'status' => 'approved',
            'created_by_user_id' => $platformAdmin->id,
            'approved_by_user_id' => $platformAdmin->id,
            'approved_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('insurance_claim_evidence_configs')->insert([
            'public_id' => (string) Str::ulid(),
            'insurance_product_id' => $productBId,
            'claim_type' => 'standard',
            'document_type' => 'claim_form',
            'is_required' => true,
            'description' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('insurance_products')
            ->where('id', $productBId)
            ->update([
                'business_model' => 'broker',
                'report_category' => 'operations',
                'updated_at' => now(),
            ]);
        $activationB = $this->withApiHeaders()
            ->actingAsSanctum($platformAdmin)
            ->postJson('/api/v1/insurance-products/'.$productBPublicId.'/activate');
        $this->assertJsonSuccess($activationB);

        $subB = $this->withApiHeaders()
            ->actingAsSanctum($platformAdmin)
            ->postJson('/api/v1/insurance-subscriptions', [
                'client_public_id' => $clientB['public_id'],
                'agency_public_id' => $agencyB['public_id'],
                'insurance_product_public_id' => $productBPublicId,
                'starts_on' => '2026-05-13',
                'coverage_amount_minor' => 300000,
                'currency' => 'XAF',
            ]);
        $this->assertJsonSuccess($subB, 201);

        $response = $this->withApiHeaders()
            ->actingAsSanctum($managerB)
            ->getJson('/api/v1/insurance-reports/active-subscriptions');
        $this->assertJsonSuccess($response);
        $response->assertJsonPath('data.totals.count', 1);
        $response->assertJsonPath('data.totals.coverage_amount_minor', 300000);

        $crossAttempt = $this->withApiHeaders()
            ->actingAsSanctum($managerB)
            ->getJson('/api/v1/insurance-reports/active-subscriptions?agency_public_id='.$contextA['agency_public_id']);
        $this->assertJsonError($crossAttempt, 422);
    }

    /**
     * @return array{
     *     agency_id:int,
     *     agency_public_id:string,
     *     subscription_public_id:string,
     *     paid_assessment_public_id:string,
     *     unpaid_assessment_public_id:string,
     *     claim_public_id:string,
     * }
     */
    private function createReportSeedContext(User $actor): array
    {
        [$subscriptionPublicId, $subscriptionId] = $this->createStandaloneSubscription($actor);
        $subscription = DB::table('insurance_subscriptions')->where('id', $subscriptionId)->first();
        self::assertIsObject($subscription);
        $agencyId = $this->requireIntFromRow($subscription, 'agency_id');
        $agencyPublicId = $this->requirePublicIdById('agencies', $agencyId);

        DB::table('insurance_subscriptions')->where('id', $subscriptionId)->update([
            'coverage_amount_minor' => 500000,
            'ends_on' => '2026-08-31',
        ]);

        $secondSubResponse = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/insurance-subscriptions', [
                'client_public_id' => $this->requirePublicIdById('clients', $this->requireIntFromRow($subscription, 'client_id')),
                'agency_public_id' => $agencyPublicId,
                'insurance_product_public_id' => $this->requirePublicIdById('insurance_products', $this->requireIntFromRow($subscription, 'insurance_product_id')),
                'starts_on' => '2026-05-14',
                'coverage_amount_minor' => 1000000,
                'currency' => 'XAF',
            ]);
        $this->assertJsonSuccess($secondSubResponse, 201);

        $paidResponse = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/insurance-subscriptions/'.$subscriptionPublicId.'/premium-assessments', [
                'premium_amount_minor' => 15000,
                'due_on' => '2026-06-30',
            ]);
        $this->assertJsonSuccess($paidResponse, 201);
        $paidAssessmentPublicId = $this->requireStringJsonPath($paidResponse, 'data.public_id');

        $customerLedger = $this->createLedgerAccount($agencyId);
        $customerAccount = $this->createCustomerAccountFor(
            agencyId: $agencyId,
            clientId: (int) $subscription->client_id,
            ledgerAccountId: $customerLedger['id'],
        );
        $this->fundCustomerAccount(
            agencyId: $agencyId,
            customerAccountId: $customerAccount['id'],
            customerLedgerAccountId: $customerLedger['id'],
            amountMinor: 50000,
            actorUserId: $actor->id,
        );
        $creditLedger = $this->createLedgerAccount($agencyId);
        $this->createInsurancePremiumCollectionMapping($creditLedger['id']);

        $collected = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/insurance-premium-assessments/'.$paidAssessmentPublicId.'/collect-from-account', [
                'customer_account_public_id' => $customerAccount['public_id'],
            ]);
        $this->assertJsonSuccess($collected);

        $unpaidResponse = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/insurance-subscriptions/'.$subscriptionPublicId.'/premium-assessments', [
                'premium_amount_minor' => 25000,
                'due_on' => '2026-07-31',
            ]);
        $this->assertJsonSuccess($unpaidResponse, 201);
        $unpaidAssessmentPublicId = $this->requireStringJsonPath($unpaidResponse, 'data.public_id');

        $claimResponse = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/insurance-claims', [
                'insurance_subscription_public_id' => $subscriptionPublicId,
                'claim_type' => 'health',
                'incident_date' => '2026-05-15',
                'claimed_amount_minor' => 100000,
                'currency' => 'XAF',
            ]);
        $this->assertJsonSuccess($claimResponse, 201);
        $claimPublicId = $this->requireStringJsonPath($claimResponse, 'data.public_id');

        return [
            'agency_id' => $agencyId,
            'agency_public_id' => $agencyPublicId,
            'subscription_public_id' => $subscriptionPublicId,
            'paid_assessment_public_id' => $paidAssessmentPublicId,
            'unpaid_assessment_public_id' => $unpaidAssessmentPublicId,
            'claim_public_id' => $claimPublicId,
        ];
    }

    private function assignStaffToAgency(User $user, int $agencyId): void
    {
        DB::table('staff_agency_assignments')->insert([
            'public_id' => (string) Str::ulid(),
            'user_id' => $user->id,
            'agency_id' => $agencyId,
            'role_at_agency' => 'agency-manager',
            'starts_on' => now()->subDay()->toDateString(),
            'ends_on' => null,
            'is_primary' => true,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_claim_settlement_posting_records_journal_entry(): void
    {
        $maker = $this->createUserWithRole('platform-admin');
        $checker = $this->createUserWithRole('platform-admin');
        $context = $this->createSettledClaimContext($maker, $checker, indemnifiedMinor: 70000, businessModel: 'risk_carrier', createMapping: true);

        $response = $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/insurance-claims/'.$context['claim_public_id'].'/settlement-posting', []);

        $this->assertJsonSuccess($response);
        $response->assertJsonPath('data.business_model', 'risk_carrier');
        $response->assertJsonPath('data.claim.status', 'settled');

        $journalEntryPublicId = $response->json('data.journal_entry_public_id');
        self::assertIsString($journalEntryPublicId);
        $this->assertDatabaseHas('journal_entries', [
            'public_id' => $journalEntryPublicId,
            'status' => JournalEntry::STATUS_POSTED,
            'source_module' => 'insurance',
            'source_type' => 'insurance_claim_settlement',
            'source_public_id' => $context['claim_public_id'],
        ]);
        $journalEntryId = $this->requireIntId('journal_entries', $journalEntryPublicId);
        $lines = DB::table('journal_lines')->where('journal_entry_id', $journalEntryId)->orderBy('id')->get();
        self::assertCount(2, $lines);
        $first = $lines[0];
        $second = $lines[1];
        self::assertIsObject($first);
        self::assertIsObject($second);
        self::assertSame(70000, $this->requireIntFromRow($first, 'debit_minor'));
        self::assertSame(0, $this->requireIntFromRow($first, 'credit_minor'));
        self::assertSame(0, $this->requireIntFromRow($second, 'debit_minor'));
        self::assertSame(70000, $this->requireIntFromRow($second, 'credit_minor'));

        $this->assertDatabaseHas('insurance_claims', [
            'public_id' => $context['claim_public_id'],
            'journal_entry_id' => $journalEntryId,
        ]);
    }

    public function test_claim_settlement_posting_rejects_missing_business_model(): void
    {
        $maker = $this->createUserWithRole('platform-admin');
        $checker = $this->createUserWithRole('platform-admin');
        $context = $this->createSettledClaimContext($maker, $checker, indemnifiedMinor: 70000, businessModel: null, createMapping: true);

        $response = $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/insurance-claims/'.$context['claim_public_id'].'/settlement-posting', []);

        $this->assertJsonError($response, 422);
        $this->assertDatabaseHas('insurance_claims', [
            'public_id' => $context['claim_public_id'],
            'journal_entry_id' => null,
        ]);
    }

    public function test_claim_settlement_posting_rejects_missing_mapping(): void
    {
        $maker = $this->createUserWithRole('platform-admin');
        $checker = $this->createUserWithRole('platform-admin');
        $context = $this->createSettledClaimContext($maker, $checker, indemnifiedMinor: 70000, businessModel: 'broker', createMapping: false);

        $response = $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/insurance-claims/'.$context['claim_public_id'].'/settlement-posting', []);

        $this->assertJsonError($response, 422);
        $this->assertDatabaseHas('insurance_claims', [
            'public_id' => $context['claim_public_id'],
            'journal_entry_id' => null,
        ]);
    }

    public function test_claim_settlement_posting_rejects_duplicate(): void
    {
        $maker = $this->createUserWithRole('platform-admin');
        $checker = $this->createUserWithRole('platform-admin');
        $context = $this->createSettledClaimContext($maker, $checker, indemnifiedMinor: 70000, businessModel: 'risk_carrier', createMapping: true);

        $first = $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/insurance-claims/'.$context['claim_public_id'].'/settlement-posting', []);
        $this->assertJsonSuccess($first);

        $second = $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/insurance-claims/'.$context['claim_public_id'].'/settlement-posting', []);
        $this->assertJsonError($second, 422);

        self::assertSame(1, DB::table('journal_entries')
            ->where('source_public_id', $context['claim_public_id'])
            ->where('source_type', 'insurance_claim_settlement')
            ->count());
    }

    /**
     * @return array{
     *     agency_id:int,
     *     claim_public_id:string,
     *     claim_id:int,
     * }
     */
    private function createSettledClaimContext(User $maker, User $checker, int $indemnifiedMinor, ?string $businessModel, bool $createMapping): array
    {
        [$subscriptionPublicId, $subscriptionId] = $this->createStandaloneSubscription($maker);
        $subscription = DB::table('insurance_subscriptions')->where('id', $subscriptionId)->first();
        self::assertIsObject($subscription);
        $agencyId = (int) $subscription->agency_id;
        $productId = (int) $subscription->insurance_product_id;

        DB::table('insurance_products')
            ->where('id', $productId)
            ->update([
                'business_model' => $businessModel,
                'updated_at' => now(),
            ]);

        if (! $createMapping) {
            DB::table('operation_account_mappings')
                ->whereIn('operation_code_id', DB::table('operation_codes')
                    ->where('code', 'insurance_claim_settlement')
                    ->pluck('id'))
                ->delete();
        } else {
            $debit = $this->createLedgerAccount($agencyId);
            $credit = $this->createLedgerAccount($agencyId);
            $this->createInsuranceClaimSettlementMapping($debit['id'], $credit['id']);
        }

        $claimResponse = $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/insurance-claims', [
                'insurance_subscription_public_id' => $subscriptionPublicId,
                'claim_type' => 'health',
                'incident_date' => '2026-05-15',
                'claimed_amount_minor' => $indemnifiedMinor,
                'currency' => 'XAF',
            ]);
        $this->assertJsonSuccess($claimResponse, 201);
        $claimPublicId = $this->requireStringJsonPath($claimResponse, 'data.public_id');

        $request = $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/insurance-claims/'.$claimPublicId.'/decision-requests', [
                'decision' => 'settle',
                'indemnified_amount_minor' => $indemnifiedMinor,
                'settled_on' => '2026-05-20',
            ]);
        $this->assertJsonSuccess($request, 201);
        $decisionPublicId = $this->requireStringJsonPath($request, 'data.public_id');

        $review = $this->withApiHeaders()
            ->actingAsSanctum($checker)
            ->postJson('/api/v1/insurance-claim-decisions/'.$decisionPublicId.'/review', [
                'review_decision' => 'approve',
            ]);
        $this->assertJsonSuccess($review);

        $claimId = $this->requireIntId('insurance_claims', $claimPublicId);

        return [
            'agency_id' => $agencyId,
            'claim_public_id' => $claimPublicId,
            'claim_id' => $claimId,
        ];
    }

    private function createInsuranceClaimSettlementMapping(int $debitLedgerId, int $creditLedgerId): void
    {
        $code = 'insurance_claim_settlement';
        $existing = DB::table('operation_codes')->where('code', $code)->first(['id']);
        $operationCodeId = is_object($existing) && is_int($existing->id)
            ? $existing->id
            : DB::table('operation_codes')->insertGetId([
                'public_id' => (string) Str::ulid(),
                'code' => $code,
                'label' => 'Insurance claim settlement',
                'module' => 'insurance',
                'operation_type' => 'claim_settlement',
                'direction' => 'debit',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        DB::table('operation_account_mappings')->insert([
            'public_id' => (string) Str::ulid(),
            'operation_code_id' => $operationCodeId,
            'debit_ledger_account_id' => $debitLedgerId,
            'credit_ledger_account_id' => $creditLedgerId,
            'currency' => null,
            'status' => 'active',
            'rules' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_claim_decision_request_leaves_claim_pending_until_review(): void
    {
        $maker = $this->createUserWithRole('platform-admin');
        $context = $this->createClaimContext($maker);

        $response = $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/insurance-claims/'.$context['claim_public_id'].'/decision-requests', [
                'decision' => 'approve',
            ]);

        $this->assertJsonSuccess($response, 201);
        $response->assertJsonPath('data.status', 'pending');
        $response->assertJsonPath('data.decision', 'approve');

        $this->assertDatabaseHas('insurance_claims', [
            'public_id' => $context['claim_public_id'],
            'status' => 'pending',
        ]);
    }

    public function test_claim_decision_approval_transitions_claim(): void
    {
        $maker = $this->createUserWithRole('platform-admin');
        $checker = $this->createUserWithRole('platform-admin');
        $context = $this->createClaimContext($maker);

        $request = $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/insurance-claims/'.$context['claim_public_id'].'/decision-requests', [
                'decision' => 'settle',
                'indemnified_amount_minor' => 80000,
                'settled_on' => '2026-05-19',
            ]);
        $this->assertJsonSuccess($request, 201);
        $decisionPublicId = $this->requireStringJsonPath($request, 'data.public_id');

        $review = $this->withApiHeaders()
            ->actingAsSanctum($checker)
            ->postJson('/api/v1/insurance-claim-decisions/'.$decisionPublicId.'/review', [
                'review_decision' => 'approve',
            ]);
        $this->assertJsonSuccess($review);
        $review->assertJsonPath('data.decision.status', 'approved');
        $review->assertJsonPath('data.claim.status', 'settled');
        $review->assertJsonPath('data.claim.indemnified_amount_minor', 80000);

        $this->assertDatabaseHas('insurance_claims', [
            'public_id' => $context['claim_public_id'],
            'status' => 'settled',
            'indemnified_amount_minor' => 80000,
        ]);
    }

    public function test_claim_decision_rejected_by_checker_leaves_claim_unchanged(): void
    {
        $maker = $this->createUserWithRole('platform-admin');
        $checker = $this->createUserWithRole('platform-admin');
        $context = $this->createClaimContext($maker);

        $request = $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/insurance-claims/'.$context['claim_public_id'].'/decision-requests', [
                'decision' => 'approve',
            ]);
        $this->assertJsonSuccess($request, 201);
        $decisionPublicId = $this->requireStringJsonPath($request, 'data.public_id');

        $review = $this->withApiHeaders()
            ->actingAsSanctum($checker)
            ->postJson('/api/v1/insurance-claim-decisions/'.$decisionPublicId.'/review', [
                'review_decision' => 'reject',
                'review_comments' => 'Insufficient evidence.',
            ]);
        $this->assertJsonSuccess($review);
        $review->assertJsonPath('data.decision.status', 'rejected');
        $review->assertJsonPath('data.claim.status', 'pending');

        $this->assertDatabaseHas('insurance_claims', [
            'public_id' => $context['claim_public_id'],
            'status' => 'pending',
        ]);
    }

    public function test_claim_decision_requester_cannot_review_own_request(): void
    {
        $maker = $this->createUserWithRole('platform-admin');
        $context = $this->createClaimContext($maker);

        $request = $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/insurance-claims/'.$context['claim_public_id'].'/decision-requests', [
                'decision' => 'approve',
            ]);
        $this->assertJsonSuccess($request, 201);
        $decisionPublicId = $this->requireStringJsonPath($request, 'data.public_id');

        $review = $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/insurance-claim-decisions/'.$decisionPublicId.'/review', [
                'review_decision' => 'approve',
            ]);
        $this->assertJsonError($review, 422);
        $this->assertDatabaseHas('insurance_claims', [
            'public_id' => $context['claim_public_id'],
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('insurance_claim_decisions', [
            'public_id' => $decisionPublicId,
            'status' => 'pending',
        ]);
    }

    public function test_claim_approval_decision_rejects_indemnified_amount(): void
    {
        $maker = $this->createUserWithRole('platform-admin');
        $context = $this->createClaimContext($maker);

        $response = $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/insurance-claims/'.$context['claim_public_id'].'/decision-requests', [
                'decision' => 'approve',
                'indemnified_amount_minor' => 50000,
            ]);

        $this->assertJsonError($response, 422);
        $this->assertDatabaseHas('insurance_claims', [
            'public_id' => $context['claim_public_id'],
            'status' => 'pending',
            'indemnified_amount_minor' => null,
        ]);
        $this->assertDatabaseMissing('insurance_claim_decisions', [
            'insurance_claim_id' => $context['claim_id'],
        ]);
    }

    public function test_direct_claim_decision_endpoint_is_disabled(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $context = $this->createClaimContext($actor);

        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/insurance-claims/'.$context['claim_public_id'].'/decision', [
                'decision' => 'approve',
                'indemnified_amount_minor' => 50000,
            ]);
        $this->assertJsonError($response, 403);
        $this->assertDatabaseHas('insurance_claims', [
            'public_id' => $context['claim_public_id'],
            'status' => 'pending',
        ]);
    }

    /**
     * @return array{
     *     agency_id:int,
     *     claim_public_id:string,
     *     claim_id:int,
     * }
     */
    private function createClaimContext(User $actor): array
    {
        [$subscriptionPublicId, $subscriptionId] = $this->createStandaloneSubscription($actor);
        $subscription = DB::table('insurance_subscriptions')->where('id', $subscriptionId)->first();
        self::assertIsObject($subscription);

        $claimResponse = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/insurance-claims', [
                'insurance_subscription_public_id' => $subscriptionPublicId,
                'claim_type' => 'health',
                'incident_date' => '2026-05-15',
                'claimed_amount_minor' => 150000,
                'currency' => 'XAF',
            ]);
        $this->assertJsonSuccess($claimResponse, 201);
        $claimPublicId = $this->requireStringJsonPath($claimResponse, 'data.public_id');
        $claimId = $this->requireIntId('insurance_claims', $claimPublicId);

        return [
            'agency_id' => (int) $subscription->agency_id,
            'claim_public_id' => $claimPublicId,
            'claim_id' => $claimId,
        ];
    }

    public function test_claim_document_attachment_accepts_same_client_document(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $context = $this->createClaimAndDocumentContext($actor);

        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/insurance-claims/'.$context['claim_public_id'].'/documents', [
                'document_public_id' => $context['document_public_id'],
                'document_type' => 'medical_certificate',
            ]);

        $this->assertJsonSuccess($response, 201);
        $response->assertJsonPath('data.document_public_id', $context['document_public_id']);
        $response->assertJsonPath('data.document_type', 'medical_certificate');
        $response->assertJsonMissingPath('data.path');
        $response->assertJsonMissingPath('data.disk');

        $this->assertDatabaseHas('insurance_claim_documents', [
            'insurance_claim_id' => $context['claim_id'],
            'document_id' => $context['document_id'],
            'document_type' => 'medical_certificate',
        ]);
    }

    public function test_claim_document_attachment_rejects_cross_agency_document(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $context = $this->createClaimAndDocumentContext($actor);

        $otherAgency = $this->createAgency('OTHER-'.Str::random(4));
        $otherDocPublicId = $this->createDocument($otherAgency['id'], null, null);

        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/insurance-claims/'.$context['claim_public_id'].'/documents', [
                'document_public_id' => $otherDocPublicId,
            ]);

        $this->assertJsonError($response, 422);
        $this->assertDatabaseMissing('insurance_claim_documents', [
            'insurance_claim_id' => $context['claim_id'],
        ]);
    }

    public function test_claim_document_attachment_rejects_other_client_owned_document(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $context = $this->createClaimAndDocumentContext($actor);

        $foreignClient = $this->createClient($context['agency_id']);
        $foreignDocPublicId = $this->createDocument(
            $context['agency_id'],
            Client::class,
            $foreignClient['id'],
        );

        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/insurance-claims/'.$context['claim_public_id'].'/documents', [
                'document_public_id' => $foreignDocPublicId,
            ]);

        $this->assertJsonError($response, 422);
        $this->assertDatabaseMissing('insurance_claim_documents', [
            'insurance_claim_id' => $context['claim_id'],
        ]);
    }

    public function test_claim_document_attachment_is_idempotent_for_duplicate_pair(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $context = $this->createClaimAndDocumentContext($actor);

        $first = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/insurance-claims/'.$context['claim_public_id'].'/documents', [
                'document_public_id' => $context['document_public_id'],
                'document_type' => 'evidence',
            ]);
        $this->assertJsonSuccess($first, 201);

        $second = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/insurance-claims/'.$context['claim_public_id'].'/documents', [
                'document_public_id' => $context['document_public_id'],
                'document_type' => 'evidence',
            ]);
        $this->assertJsonSuccess($second, 200);

        self::assertSame(1, DB::table('insurance_claim_documents')
            ->where('insurance_claim_id', $context['claim_id'])
            ->where('document_id', $context['document_id'])
            ->count());
    }

    /**
     * @return array{
     *     agency_id:int,
     *     claim_public_id:string,
     *     claim_id:int,
     *     document_public_id:string,
     *     document_id:int,
     * }
     */
    private function createClaimAndDocumentContext(User $actor): array
    {
        [$subscriptionPublicId, $subscriptionId] = $this->createStandaloneSubscription($actor);
        $subscription = DB::table('insurance_subscriptions')->where('id', $subscriptionId)->first();
        self::assertIsObject($subscription);
        $agencyId = (int) $subscription->agency_id;
        $clientId = (int) $subscription->client_id;

        $claimResponse = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/insurance-claims', [
                'insurance_subscription_public_id' => $subscriptionPublicId,
                'claim_type' => 'health',
                'incident_date' => '2026-05-15',
                'claimed_amount_minor' => 100000,
                'currency' => 'XAF',
            ]);
        $this->assertJsonSuccess($claimResponse, 201);
        $claimPublicId = $this->requireStringJsonPath($claimResponse, 'data.public_id');
        $claimId = $this->requireIntId('insurance_claims', $claimPublicId);

        $documentPublicId = $this->createDocument($agencyId, Client::class, $clientId);
        $documentId = $this->requireIntId('documents', $documentPublicId);

        return [
            'agency_id' => $agencyId,
            'claim_public_id' => $claimPublicId,
            'claim_id' => $claimId,
            'document_public_id' => $documentPublicId,
            'document_id' => $documentId,
        ];
    }

    private function createDocument(int $agencyId, ?string $ownerType, ?int $ownerId): string
    {
        $publicId = (string) Str::ulid();
        $suffix = Str::ulid();
        DB::table('documents')->insert([
            'public_id' => $publicId,
            'agency_id' => $agencyId,
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'uploaded_by_user_id' => null,
            'category' => 'claim_evidence',
            'title' => 'Test Document '.$suffix,
            'disk' => 'local',
            'path' => 'documents/'.$suffix.'.pdf',
            'original_name' => 'evidence.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1234,
            'checksum_sha256' => str_pad((string) $suffix, 64, '0'),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $publicId;
    }

    public function test_premium_collection_cash_posts_journal_and_teller_transaction(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $context = $this->createCashCollectionContext($actor, premiumMinor: 8000);

        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/insurance-premium-assessments/'.$context['assessment_public_id'].'/collect-cash', [
                'teller_session_public_id' => $context['teller_session_public_id'],
                'paid_on' => '2026-05-15',
            ]);

        $this->assertJsonSuccess($response);
        $response->assertJsonPath('data.assessment.status', 'paid');
        $response->assertJsonPath('data.payment.payment_method', 'teller_cash');
        $response->assertJsonPath('data.payment.amount_minor', 8000);

        $this->assertDatabaseHas('insurance_premium_assessments', [
            'public_id' => $context['assessment_public_id'],
            'status' => 'paid',
        ]);
        $this->assertDatabaseHas('insurance_premium_payments', [
            'insurance_premium_assessment_id' => $context['assessment_id'],
            'amount_minor' => 8000,
            'payment_method' => 'teller_cash',
            'status' => 'posted',
        ]);

        $tellerTransactionPublicId = $response->json('data.teller_transaction_public_id');
        self::assertIsString($tellerTransactionPublicId);
        $this->assertDatabaseHas('teller_transactions', [
            'public_id' => $tellerTransactionPublicId,
            'teller_session_id' => $context['teller_session_id'],
            'transaction_type' => 'cash_deposit',
            'amount_minor' => 8000,
            'currency' => 'XAF',
            'status' => 'posted',
            'operation_code' => 'insurance_premium_collection',
        ]);

        $journalEntryPublicId = $response->json('data.journal_entry_public_id');
        self::assertIsString($journalEntryPublicId);
        $this->assertDatabaseHas('journal_entries', [
            'public_id' => $journalEntryPublicId,
            'status' => JournalEntry::STATUS_POSTED,
            'source_module' => 'insurance',
            'source_type' => 'insurance_premium_cash_payment',
        ]);
    }

    public function test_premium_collection_cash_rejects_closed_teller_session(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $context = $this->createCashCollectionContext($actor, premiumMinor: 8000);

        DB::table('teller_sessions')
            ->where('id', $context['teller_session_id'])
            ->update(['status' => 'closed', 'closed_at' => now()]);

        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/insurance-premium-assessments/'.$context['assessment_public_id'].'/collect-cash', [
                'teller_session_public_id' => $context['teller_session_public_id'],
            ]);

        $this->assertJsonError($response, 422);
        $this->assertDatabaseHas('insurance_premium_assessments', [
            'public_id' => $context['assessment_public_id'],
            'status' => 'assessed',
        ]);
        $this->assertDatabaseMissing('insurance_premium_payments', [
            'insurance_premium_assessment_id' => $context['assessment_id'],
        ]);
    }

    public function test_premium_collection_cash_requires_active_operation_mapping(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $context = $this->createCashCollectionContext($actor, premiumMinor: 8000, createMapping: false);

        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/insurance-premium-assessments/'.$context['assessment_public_id'].'/collect-cash', [
                'teller_session_public_id' => $context['teller_session_public_id'],
            ]);

        $this->assertJsonError($response, 422);
        $this->assertDatabaseHas('insurance_premium_assessments', [
            'public_id' => $context['assessment_public_id'],
            'status' => 'assessed',
        ]);
        $this->assertDatabaseMissing('teller_transactions', [
            'operation_code' => 'insurance_premium_collection',
        ]);
    }

    public function test_premium_collection_cash_rejects_duplicate_collection(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $context = $this->createCashCollectionContext($actor, premiumMinor: 8000);

        $first = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/insurance-premium-assessments/'.$context['assessment_public_id'].'/collect-cash', [
                'teller_session_public_id' => $context['teller_session_public_id'],
            ]);
        $this->assertJsonSuccess($first);

        $second = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/insurance-premium-assessments/'.$context['assessment_public_id'].'/collect-cash', [
                'teller_session_public_id' => $context['teller_session_public_id'],
            ]);
        $this->assertJsonError($second, 422);

        self::assertSame(1, DB::table('insurance_premium_payments')
            ->where('insurance_premium_assessment_id', $context['assessment_id'])
            ->count());
    }

    /**
     * @return array{
     *     agency_id:int,
     *     subscription_public_id:string,
     *     subscription_id:int,
     *     assessment_public_id:string,
     *     assessment_id:int,
     *     teller_session_public_id:string,
     *     teller_session_id:int,
     * }
     */
    private function createCashCollectionContext(User $actor, int $premiumMinor, bool $createMapping = true): array
    {
        [$subscriptionPublicId, $subscriptionId] = $this->createStandaloneSubscription($actor);
        $subscription = DB::table('insurance_subscriptions')->where('id', $subscriptionId)->first();
        self::assertIsObject($subscription);
        $agencyId = (int) $subscription->agency_id;

        if (! $createMapping) {
            DB::table('operation_account_mappings')
                ->whereIn('operation_code_id', DB::table('operation_codes')
                    ->where('code', 'insurance_premium_collection')
                    ->pluck('id'))
                ->delete();
        }

        $tillLedger = $this->createLedgerAccount($agencyId);
        $session = $this->createOpenTellerSession($agencyId, $tillLedger['id']);

        $assessmentResponse = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/insurance-subscriptions/'.$subscriptionPublicId.'/premium-assessments', [
                'premium_amount_minor' => $premiumMinor,
                'due_on' => '2026-06-30',
            ]);
        $this->assertJsonSuccess($assessmentResponse, 201);
        $assessmentPublicId = $this->requireStringJsonPath($assessmentResponse, 'data.public_id');
        $assessmentId = $this->requireIntId('insurance_premium_assessments', $assessmentPublicId);

        return [
            'agency_id' => $agencyId,
            'subscription_public_id' => $subscriptionPublicId,
            'subscription_id' => $subscriptionId,
            'assessment_public_id' => $assessmentPublicId,
            'assessment_id' => $assessmentId,
            'teller_session_public_id' => $session['public_id'],
            'teller_session_id' => $session['id'],
        ];
    }

    /**
     * @return array{id:int, public_id:string, till_id:int}
     */
    private function createOpenTellerSession(int $agencyId, int $tillLedgerId): array
    {
        $teller = $this->createUserWithRole('teller');

        $tillId = DB::table('tills')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agencyId,
            'code' => 'TILL-'.Str::ulid(),
            'name' => 'Insurance Cash Till',
            'type' => 'counter',
            'status' => 'active',
            'daily_state' => 'open',
            'requires_denominations' => false,
            'currency' => 'XAF',
            'assigned_user_id' => $teller->id,
            'ledger_account_id' => $tillLedgerId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $sessionPublicId = (string) Str::ulid();
        $sessionId = DB::table('teller_sessions')->insertGetId([
            'public_id' => $sessionPublicId,
            'till_id' => $tillId,
            'agency_id' => $agencyId,
            'teller_user_id' => $teller->id,
            'business_date' => '2026-05-13',
            'opened_at' => now(),
            'opening_declaration_minor' => 0,
            'currency' => 'XAF',
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['id' => $sessionId, 'public_id' => $sessionPublicId, 'till_id' => $tillId];
    }

    /**
     * @return array{
     *     agency_id:int,
     *     subscription_public_id:string,
     *     subscription_id:int,
     *     assessment_public_id:string,
     *     assessment_id:int,
     *     customer_account_public_id:string,
     *     customer_account_id:int,
     * }
     */
    private function createCollectionContext(User $actor, int $premiumMinor, int $accountFundingMinor, bool $createMapping = true): array
    {
        [$subscriptionPublicId, $subscriptionId] = $this->createStandaloneSubscription($actor);
        $subscription = DB::table('insurance_subscriptions')->where('id', $subscriptionId)->first();
        self::assertIsObject($subscription);

        $agencyId = (int) $subscription->agency_id;
        $clientId = (int) $subscription->client_id;
        if (! $createMapping) {
            DB::table('operation_account_mappings')
                ->whereIn('operation_code_id', DB::table('operation_codes')
                    ->where('code', 'insurance_premium_collection')
                    ->pluck('id'))
                ->delete();
        }

        $customerLedger = $this->createLedgerAccount($agencyId);
        $customerAccount = $this->createCustomerAccountFor(
            agencyId: $agencyId,
            clientId: $clientId,
            ledgerAccountId: $customerLedger['id'],
        );
        $this->fundCustomerAccount(
            agencyId: $agencyId,
            customerAccountId: $customerAccount['id'],
            customerLedgerAccountId: $customerLedger['id'],
            amountMinor: $accountFundingMinor,
            actorUserId: $actor->id,
        );

        $assessmentResponse = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/insurance-subscriptions/'.$subscriptionPublicId.'/premium-assessments', [
                'premium_amount_minor' => $premiumMinor,
                'due_on' => '2026-06-30',
            ]);
        $this->assertJsonSuccess($assessmentResponse, 201);
        $assessmentPublicId = $this->requireStringJsonPath($assessmentResponse, 'data.public_id');
        $assessmentId = $this->requireIntId('insurance_premium_assessments', $assessmentPublicId);

        return [
            'agency_id' => $agencyId,
            'subscription_public_id' => $subscriptionPublicId,
            'subscription_id' => $subscriptionId,
            'assessment_public_id' => $assessmentPublicId,
            'assessment_id' => $assessmentId,
            'customer_account_public_id' => $customerAccount['public_id'],
            'customer_account_id' => $customerAccount['id'],
        ];
    }

    /**
     * @return array{id:int, public_id:string}
     */
    private function createCustomerAccountFor(int $agencyId, int $clientId, int $ledgerAccountId): array
    {
        $publicId = (string) Str::ulid();
        $id = DB::table('customer_accounts')->insertGetId([
            'public_id' => $publicId,
            'client_id' => $clientId,
            'agency_id' => $agencyId,
            'ledger_account_id' => $ledgerAccountId,
            'account_number' => 'CA-'.Str::ulid(),
            'account_title' => 'Insurance Client Account',
            'account_type' => 'ordinary_savings',
            'currency' => 'XAF',
            'unavailable_amount_minor' => 0,
            'opened_on' => now()->toDateString(),
            'status' => 'active',
        ]);

        return ['id' => $id, 'public_id' => $publicId];
    }

    private function fundCustomerAccount(int $agencyId, int $customerAccountId, int $customerLedgerAccountId, int $amountMinor, int $actorUserId): void
    {
        $offsetLedger = $this->createLedgerAccount($agencyId);

        $journalEntryId = DB::table('journal_entries')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'reference' => 'CUSTOMER-FUND-'.Str::ulid(),
            'business_date' => '2026-05-13',
            'posted_at' => null,
            'agency_id' => $agencyId,
            'source_module' => 'test',
            'source_type' => 'customer_account_funding',
            'status' => JournalEntry::STATUS_DRAFT,
            'created_by_user_id' => $actorUserId,
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

    private function createInsurancePremiumCollectionMapping(int $creditLedgerId): void
    {
        $code = 'insurance_premium_collection';
        $existing = DB::table('operation_codes')->where('code', $code)->first(['id']);
        $operationCodeId = is_object($existing) && is_int($existing->id)
            ? $existing->id
            : DB::table('operation_codes')->insertGetId([
                'public_id' => (string) Str::ulid(),
                'code' => $code,
                'label' => 'Insurance premium collection',
                'module' => 'insurance',
                'operation_type' => 'premium_collection',
                'direction' => 'credit',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        DB::table('operation_account_mappings')->insert([
            'public_id' => (string) Str::ulid(),
            'operation_code_id' => $operationCodeId,
            'debit_ledger_account_id' => null,
            'credit_ledger_account_id' => $creditLedgerId,
            'currency' => null,
            'status' => 'active',
            'rules' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array{0:string, 1:int}
     */
    private function createStandaloneSubscription(User $actor): array
    {
        $agency = $this->createAgency('INS-PA-'.Str::random(4));
        $ledger = $this->createLedgerAccount($agency['id']);
        $client = $this->createClient($agency['id']);

        $partner = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/insurance-partners', [
                'agency_public_id' => $agency['public_id'],
                'ledger_account_public_id' => $ledger['public_id'],
                'code' => 'INS-PARTNER-PA-'.Str::random(4),
                'name' => 'Standalone Partner',
            ]);
        $this->assertJsonSuccess($partner, 201);
        $partnerPublicId = $this->requireStringJsonPath($partner, 'data.public_id');

        $product = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/insurance-products', [
                'insurance_partner_public_id' => $partnerPublicId,
                'code' => 'STANDALONE-COVER-'.Str::random(4),
                'name' => 'Standalone Cover',
                'product_type' => 'health',
                'currency' => 'XAF',
            ]);
        $this->assertJsonSuccess($product, 201);
        $productPublicId = $this->requireStringJsonPath($product, 'data.public_id');
        $productId = $this->requireIntId('insurance_products', $productPublicId);

        $this->createInsurancePremiumCollectionMapping($ledger['id']);
        $claimDebitLedger = $this->createLedgerAccount($agency['id']);
        $claimCreditLedger = $this->createLedgerAccount($agency['id']);
        $this->createInsuranceClaimSettlementMapping($claimDebitLedger['id'], $claimCreditLedger['id']);
        DB::table('insurance_product_rule_versions')->insert([
            'public_id' => (string) Str::ulid(),
            'insurance_product_id' => $productId,
            'version_number' => 1,
            'calculation_type' => 'flat_rate',
            'base_description' => 'insured_amount',
            'rate' => null,
            'fixed_premium_minor' => 15000,
            'cap_minor' => null,
            'floor_minor' => null,
            'frequency' => 'one_time',
            'source_reference' => 'test-contract',
            'effective_from' => '2026-01-01',
            'effective_until' => null,
            'status' => 'approved',
            'created_by_user_id' => $actor->id,
            'approved_by_user_id' => $actor->id,
            'approved_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('insurance_claim_evidence_configs')->insert([
            'public_id' => (string) Str::ulid(),
            'insurance_product_id' => $productId,
            'claim_type' => 'standard',
            'document_type' => 'claim_form',
            'is_required' => true,
            'description' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('insurance_products')
            ->where('id', $productId)
            ->update([
                'business_model' => 'broker',
                'report_category' => 'operations',
                'updated_at' => now(),
            ]);

        $activation = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/insurance-products/'.$productPublicId.'/activate');
        $this->assertJsonSuccess($activation);

        $subscription = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/insurance-subscriptions', [
                'client_public_id' => $client['public_id'],
                'agency_public_id' => $agency['public_id'],
                'insurance_product_public_id' => $productPublicId,
                'starts_on' => '2026-05-13',
                'coverage_amount_minor' => 500000,
                'currency' => 'XAF',
            ]);
        $this->assertJsonSuccess($subscription, 201);
        $subscriptionPublicId = $this->requireStringJsonPath($subscription, 'data.public_id');
        $subscriptionId = $this->requireIntId('insurance_subscriptions', $subscriptionPublicId);

        return [$subscriptionPublicId, $subscriptionId];
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

    private function requireIntId(string $table, string $publicId): int
    {
        $row = DB::table($table)->where('public_id', $publicId)->first(['id']);
        self::assertIsObject($row);
        self::assertTrue(property_exists($row, 'id'));
        $value = $row->id;
        self::assertIsNumeric($value);

        return (int) $value;
    }

    private function requirePublicIdById(string $table, int $id): string
    {
        $row = DB::table($table)->where('id', $id)->first(['public_id']);
        self::assertIsObject($row);
        self::assertTrue(property_exists($row, 'public_id'));
        $value = $row->public_id;
        self::assertIsString($value);

        return $value;
    }

    private function requireIntFromRow(object $row, string $key): int
    {
        $value = ((array) $row)[$key] ?? null;
        self::assertIsNumeric($value);

        return (int) $value;
    }

    private function requireStringJsonPath(TestResponse $response, string $path): string
    {
        $value = $response->json($path);
        self::assertIsString($value);

        return $value;
    }

    /**
     * @return array{id:int, public_id:string}
     */
    private function createAgency(string $code): array
    {
        $publicId = (string) Str::ulid();
        $id = DB::table('agencies')->insertGetId([
            'public_id' => $publicId,
            'code' => $code,
            'name' => 'Agency '.$code,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['id' => $id, 'public_id' => $publicId];
    }

    /**
     * @return array{id:int, public_id:string}
     */
    private function createLedgerAccount(int $agencyId): array
    {
        $publicId = (string) Str::ulid();
        $id = DB::table('ledger_accounts')->insertGetId([
            'public_id' => $publicId,
            'agency_id' => $agencyId,
            'code' => 'INS-'.Str::ulid(),
            'name' => 'Insurance Ledger',
            'account_class' => LedgerAccount::ACCOUNT_CLASS_LIABILITY,
            'normal_balance_side' => LedgerAccount::NORMAL_BALANCE_CREDIT,
            'status' => LedgerAccount::STATUS_ACTIVE,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['id' => $id, 'public_id' => $publicId];
    }

    /**
     * @return array{id:int, public_id:string}
     */
    private function createClient(int $agencyId): array
    {
        $publicId = (string) Str::ulid();
        $id = DB::table('clients')->insertGetId([
            'public_id' => $publicId,
            'agency_id' => $agencyId,
            'client_reference' => 'CLI-'.Str::ulid(),
            'first_name' => 'Insurance',
            'last_name' => 'Client',
            'status' => 'active',
            'kyc_status' => 'verified',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['id' => $id, 'public_id' => $publicId];
    }
}
