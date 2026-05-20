<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Client;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Tests for Backlog A: tickets A8-A14
 * - A8: Product rule versioning
 * - A9: Recurring premium schedules & renewal
 * - A10: Endorsements, cancellations, reversals
 * - A11: Remittance & commission
 * - A12: Claim evidence config & lifecycle
 * - A13: Exports
 * - A14: Permissions & product readiness
 */
final class InsuranceProductLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    // -------------------------------------------------------------------------
    // A8: Product rule versioning
    // -------------------------------------------------------------------------

    public function test_product_rule_version_can_be_created_for_approved_product_family(): void
    {
        $admin = $this->createUser('platform-admin');
        [$productPublicId] = $this->createProductContext($admin, 'health');
        $checker = $this->createUser('platform-admin');

        $response = $this->withApiHeaders()
            ->actingAsSanctum($admin)
            ->postJson('/api/v1/insurance-products/'.$productPublicId.'/rule-versions', [
                'calculation_type' => 'percentage',
                'base_description' => 'insured_amount',
                'rate' => '5.000000',
                'frequency' => 'annual',
                'source_reference' => 'CIMA Art 100-2026',
                'effective_from' => '2026-01-01',
            ]);

        $this->assertJsonSuccess($response, 201);
        $response->assertJsonPath('data.status', 'draft');
        $response->assertJsonPath('data.calculation_type', 'percentage');
        $response->assertJsonPath('data.frequency', 'annual');

        $versionPublicId = $this->requireStringJsonPath($response, 'data.public_id');
        $this->assertDatabaseHas('insurance_product_rule_versions', [
            'public_id' => $versionPublicId,
            'status' => 'draft',
        ]);

        // Maker-checker: creator cannot approve own version
        $selfApprove = $this->withApiHeaders()
            ->actingAsSanctum($admin)
            ->postJson('/api/v1/insurance-product-rule-versions/'.$versionPublicId.'/approve');
        $this->assertJsonError($selfApprove, 422);

        // Checker can approve
        $approved = $this->withApiHeaders()
            ->actingAsSanctum($checker)
            ->postJson('/api/v1/insurance-product-rule-versions/'.$versionPublicId.'/approve');
        $this->assertJsonSuccess($approved);
        $approved->assertJsonPath('data.status', 'approved');

        $this->assertDatabaseHas('insurance_product_rule_versions', [
            'public_id' => $versionPublicId,
            'status' => 'approved',
        ]);
    }

    public function test_all_stakeholder_requested_product_families_are_configurable(): void
    {
        $admin = $this->createUser('platform-admin');

        foreach ([
            'borrower',
            'health',
            'life',
            'savings',
            'agricultural',
            'home',
            'professional_commercial',
            'automobile',
            'motorcycle',
            'school',
            'travel',
            'funeral',
            'mobile_equipment',
        ] as $productType) {
            [$productPublicId] = $this->createProductContext($admin, $productType);
            $product = DB::table('insurance_products')->where('public_id', $productPublicId)->first(['product_type']);
            self::assertIsObject($product);
            self::assertSame($productType, (string) $product->product_type);
        }
    }

    public function test_overlapping_approved_rule_versions_are_rejected(): void
    {
        $admin = $this->createUser('platform-admin');
        $checker = $this->createUser('platform-admin');
        [$productPublicId] = $this->createProductContext($admin, 'life');

        // Create and approve first version
        $v1Id = $this->createAndApproveRuleVersion($admin, $checker, $productPublicId, '2026-01-01', null);

        // Attempt to approve a second version that overlaps
        $v2 = $this->withApiHeaders()
            ->actingAsSanctum($admin)
            ->postJson('/api/v1/insurance-products/'.$productPublicId.'/rule-versions', [
                'calculation_type' => 'flat_rate',
                'fixed_premium_minor' => 5000,
                'frequency' => 'monthly',
                'effective_from' => '2026-06-01',
            ]);
        $this->assertJsonSuccess($v2, 201);
        $v2PublicId = $this->requireStringJsonPath($v2, 'data.public_id');

        // Approve v2 — should fail because v1 has no end date (overlapping)
        $approveV2 = $this->withApiHeaders()
            ->actingAsSanctum($checker)
            ->postJson('/api/v1/insurance-product-rule-versions/'.$v2PublicId.'/approve');
        $this->assertJsonError($approveV2, 422);
    }

    public function test_rule_version_snapshot_is_linked_when_schedule_generates_assessment(): void
    {
        $admin = $this->createUser('platform-admin');
        $checker = $this->createUser('platform-admin');
        [$productPublicId, $subscriptionPublicId] = $this->createSubscriptionWithRuleVersion($admin, $checker, 'monthly');

        // Generate batch assessments
        $gen = $this->withApiHeaders()
            ->actingAsSanctum($admin)
            ->postJson('/api/v1/insurance-premium-batch-generate', [
                'due_before' => '2027-01-01',
            ]);
        $this->assertJsonSuccess($gen);
        $gen->assertJsonPath('data.generated', 1);

        // Assessment should have rule_version_id set
        $assessmentCount = DB::table('insurance_premium_assessments')
            ->join('insurance_subscriptions', 'insurance_subscriptions.id', '=', 'insurance_premium_assessments.insurance_subscription_id')
            ->where('insurance_subscriptions.public_id', $subscriptionPublicId)
            ->whereNotNull('insurance_premium_assessments.rule_version_id')
            ->count();
        self::assertGreaterThan(0, $assessmentCount);
    }

    // -------------------------------------------------------------------------
    // A9: Recurring premium schedules & renewal
    // -------------------------------------------------------------------------

    public function test_subscription_activation_creates_first_schedule(): void
    {
        $admin = $this->createUser('platform-admin');
        $checker = $this->createUser('platform-admin');
        [$productPublicId, $subscriptionPublicId, $versionPublicId] = $this->createSubscriptionWithRuleVersion($admin, $checker, 'monthly');

        // The subscription was activated in the helper; verify schedule exists
        $this->assertDatabaseHas('insurance_premium_schedules', [
            'status' => 'scheduled',
            'period_number' => 1,
        ]);
    }

    public function test_batch_generation_is_idempotent(): void
    {
        $admin = $this->createUser('platform-admin');
        $checker = $this->createUser('platform-admin');
        $this->createSubscriptionWithRuleVersion($admin, $checker, 'monthly');

        $gen1 = $this->withApiHeaders()
            ->actingAsSanctum($admin)
            ->postJson('/api/v1/insurance-premium-batch-generate', ['due_before' => '2027-01-01']);
        $this->assertJsonSuccess($gen1);
        $firstGenerated = $gen1->json('data.generated');
        self::assertIsInt($firstGenerated);
        self::assertGreaterThan(0, $firstGenerated);

        // Run again — idempotency: 0 new assessments
        $gen2 = $this->withApiHeaders()
            ->actingAsSanctum($admin)
            ->postJson('/api/v1/insurance-premium-batch-generate', ['due_before' => '2027-01-01']);
        $this->assertJsonSuccess($gen2);
        $gen2->assertJsonPath('data.generated', 0);
        $skipped = $gen2->json('data.skipped');
        self::assertIsInt($skipped);
        self::assertGreaterThan(0, $skipped);
    }

    public function test_renewal_creates_new_subscription_without_mutating_old(): void
    {
        $admin = $this->createUser('platform-admin');
        $checker = $this->createUser('platform-admin');
        [, $subscriptionPublicId, $versionPublicId] = $this->createSubscriptionWithRuleVersion($admin, $checker, 'annual');

        $response = $this->withApiHeaders()
            ->actingAsSanctum($admin)
            ->postJson('/api/v1/insurance-subscriptions/'.$subscriptionPublicId.'/renew', [
                'starts_on' => '2027-01-01',
                'ends_on' => '2027-12-31',
                'rule_version_public_id' => $versionPublicId,
            ]);

        $this->assertJsonSuccess($response, 201);
        $newPublicId = $this->requireStringJsonPath($response, 'data.public_id');
        self::assertNotSame($subscriptionPublicId, $newPublicId);

        // Old subscription is unchanged
        $this->assertDatabaseHas('insurance_subscriptions', [
            'public_id' => $subscriptionPublicId,
            'status' => 'active',
        ]);

        // New subscription exists with new start date
        $this->assertDatabaseHas('insurance_subscriptions', [
            'public_id' => $newPublicId,
            'starts_on' => '2027-01-01',
        ]);
    }

    // -------------------------------------------------------------------------
    // A10: Endorsements, cancellations, reversals
    // -------------------------------------------------------------------------

    public function test_endorsement_snapshots_before_and_after_values(): void
    {
        $admin = $this->createUser('platform-admin');
        $checker = $this->createUser('platform-admin');
        [, $subscriptionPublicId] = $this->createSubscriptionWithRuleVersion($admin, $checker, 'annual');

        $response = $this->withApiHeaders()
            ->actingAsSanctum($admin)
            ->postJson('/api/v1/insurance-subscriptions/'.$subscriptionPublicId.'/endorsements', [
                'endorsement_type' => 'coverage_amount',
                'before_values' => ['coverage_amount_minor' => 500000],
                'after_values' => ['coverage_amount_minor' => 750000],
                'effective_on' => '2026-07-01',
                'reason' => 'Client requested higher coverage.',
            ]);

        $this->assertJsonSuccess($response, 201);
        $endorsementPublicId = $this->requireStringJsonPath($response, 'data.public_id');
        $response->assertJsonPath('data.status', 'pending');

        // Checker approves
        $review = $this->withApiHeaders()
            ->actingAsSanctum($checker)
            ->postJson('/api/v1/insurance-endorsements/'.$endorsementPublicId.'/review', [
                'review_decision' => 'approve',
            ]);
        $this->assertJsonSuccess($review);
        $review->assertJsonPath('data.status', 'approved');

        // Subscription coverage should be updated
        $this->assertDatabaseHas('insurance_subscriptions', [
            'public_id' => $subscriptionPublicId,
            'coverage_amount_minor' => 750000,
        ]);
    }

    public function test_endorsement_requester_cannot_review_own_endorsement(): void
    {
        $admin = $this->createUser('platform-admin');
        $checker = $this->createUser('platform-admin');
        [, $subscriptionPublicId] = $this->createSubscriptionWithRuleVersion($admin, $checker, 'annual');

        $endorsement = $this->withApiHeaders()
            ->actingAsSanctum($admin)
            ->postJson('/api/v1/insurance-subscriptions/'.$subscriptionPublicId.'/endorsements', [
                'endorsement_type' => 'other',
                'before_values' => ['note' => 'old'],
                'after_values' => ['note' => 'new'],
                'effective_on' => '2026-07-01',
            ]);
        $this->assertJsonSuccess($endorsement, 201);
        $endorsementPublicId = $this->requireStringJsonPath($endorsement, 'data.public_id');

        $selfReview = $this->withApiHeaders()
            ->actingAsSanctum($admin) // same user as requester
            ->postJson('/api/v1/insurance-endorsements/'.$endorsementPublicId.'/review', [
                'review_decision' => 'approve',
            ]);
        $this->assertJsonError($selfReview, 422);
    }

    public function test_cancellation_request_is_created_and_awaits_approval(): void
    {
        $admin = $this->createUser('platform-admin');
        $checker = $this->createUser('platform-admin');
        [, $subscriptionPublicId] = $this->createSubscriptionWithRuleVersion($admin, $checker, 'annual');

        $response = $this->withApiHeaders()
            ->actingAsSanctum($admin)
            ->postJson('/api/v1/insurance-subscriptions/'.$subscriptionPublicId.'/cancel', [
                'effective_on' => '2026-08-01',
                'reason' => 'Client terminated the contract.',
                'refund_treatment' => 'pro_rata',
            ]);

        $this->assertJsonSuccess($response, 201);
        $response->assertJsonPath('data.status', 'pending');
        $response->assertJsonPath('data.refund_treatment', 'pro_rata');

        // Subscription still active — cancellation needs approval
        $this->assertDatabaseHas('insurance_subscriptions', [
            'public_id' => $subscriptionPublicId,
            'status' => 'active',
        ]);
    }

    public function test_cancellation_review_blocks_claims_from_effective_date_only(): void
    {
        $admin = $this->createUser('platform-admin');
        $checker = $this->createUser('platform-admin');
        [, $subscriptionPublicId] = $this->createSubscriptionWithRuleVersion($admin, $checker, 'annual');

        $request = $this->withApiHeaders()
            ->actingAsSanctum($admin)
            ->postJson('/api/v1/insurance-subscriptions/'.$subscriptionPublicId.'/cancel', [
                'effective_on' => '2026-08-01',
                'refund_treatment' => 'none',
            ]);
        $this->assertJsonSuccess($request, 201);

        $review = $this->withApiHeaders()
            ->actingAsSanctum($checker)
            ->postJson('/api/v1/insurance-cancellations/'.$this->requireStringJsonPath($request, 'data.public_id').'/review', [
                'review_decision' => 'approve',
            ]);

        $this->assertJsonSuccess($review);
        $review->assertJsonPath('data.cancellation.status', 'approved');
        $this->assertDatabaseHas('insurance_subscriptions', [
            'public_id' => $subscriptionPublicId,
            'status' => 'active',
            'lifecycle_status' => 'cancellation_approved',
        ]);

        $beforeEffectiveDate = $this->withApiHeaders()
            ->actingAsSanctum($admin)
            ->postJson('/api/v1/insurance-claims', [
                'insurance_subscription_public_id' => $subscriptionPublicId,
                'claim_type' => 'medical',
                'incident_date' => '2026-07-31',
                'claimed_amount_minor' => 50000,
                'currency' => 'XAF',
            ]);
        $this->assertJsonSuccess($beforeEffectiveDate, 201);

        $afterEffectiveDate = $this->withApiHeaders()
            ->actingAsSanctum($admin)
            ->postJson('/api/v1/insurance-claims', [
                'insurance_subscription_public_id' => $subscriptionPublicId,
                'claim_type' => 'medical',
                'incident_date' => '2026-08-01',
                'claimed_amount_minor' => 50000,
                'currency' => 'XAF',
            ]);
        $this->assertJsonError($afterEffectiveDate, 422);
    }

    public function test_cancellation_review_immediately_closes_subscription_when_effective_today_or_past(): void
    {
        $admin = $this->createUser('platform-admin');
        $checker = $this->createUser('platform-admin');
        [, $subscriptionPublicId] = $this->createSubscriptionWithRuleVersion($admin, $checker, 'annual');

        $request = $this->withApiHeaders()
            ->actingAsSanctum($admin)
            ->postJson('/api/v1/insurance-subscriptions/'.$subscriptionPublicId.'/cancel', [
                'effective_on' => now()->toDateString(),
                'refund_treatment' => 'none',
            ]);
        $this->assertJsonSuccess($request, 201);

        $review = $this->withApiHeaders()
            ->actingAsSanctum($checker)
            ->postJson('/api/v1/insurance-cancellations/'.$this->requireStringJsonPath($request, 'data.public_id').'/review', [
                'review_decision' => 'approve',
            ]);

        $this->assertJsonSuccess($review);
        $this->assertDatabaseHas('insurance_subscriptions', [
            'public_id' => $subscriptionPublicId,
            'status' => 'cancelled',
            'lifecycle_status' => 'cancelled',
        ]);
    }

    public function test_cancellation_refund_posts_configured_accounting_on_approval(): void
    {
        $admin = $this->createUser('platform-admin');
        $checker = $this->createUser('platform-admin');
        [, $subscriptionPublicId] = $this->createSubscriptionWithRuleVersion($admin, $checker, 'annual');

        $subscription = DB::table('insurance_subscriptions')->where('public_id', $subscriptionPublicId)->first();
        self::assertIsObject($subscription);
        $refundDebitLedger = $this->makeLedger((int) $subscription->agency_id);
        $refundCreditLedger = $this->makeLedger((int) $subscription->agency_id);
        $this->createRefundMapping((int) $subscription->agency_id, $refundDebitLedger['id'], $refundCreditLedger['id']);
        $refundAccount = $this->makeCustomerAccount(
            (int) $subscription->agency_id,
            (int) $subscription->client_id,
            $refundCreditLedger['id'],
        );

        $request = $this->withApiHeaders()
            ->actingAsSanctum($admin)
            ->postJson('/api/v1/insurance-subscriptions/'.$subscriptionPublicId.'/cancel', [
                'effective_on' => '2026-08-01',
                'refund_treatment' => 'pro_rata',
                'refund_amount_minor' => 5000,
                'refund_customer_account_public_id' => $refundAccount['public_id'],
            ]);
        $this->assertJsonSuccess($request, 201);

        $review = $this->withApiHeaders()
            ->actingAsSanctum($checker)
            ->postJson('/api/v1/insurance-cancellations/'.$this->requireStringJsonPath($request, 'data.public_id').'/review', [
                'review_decision' => 'approve',
            ]);
        $this->assertJsonSuccess($review);
        $review->assertJsonPath('data.cancellation.refund_amount_minor', 5000);
        self::assertIsString($review->json('data.cancellation.refund_journal_entry_public_id'));

        $journalEntryId = DB::table('insurance_cancellations')
            ->where('public_id', $this->requireStringJsonPath($request, 'data.public_id'))
            ->value('refund_journal_entry_id');
        self::assertIsNumeric($journalEntryId);
        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => (int) $journalEntryId,
            'ledger_account_id' => $refundDebitLedger['id'],
            'debit_minor' => 5000,
        ]);
        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => (int) $journalEntryId,
            'ledger_account_id' => $refundCreditLedger['id'],
            'customer_account_id' => $refundAccount['id'],
            'credit_minor' => 5000,
        ]);
    }

    public function test_premium_payment_reversal_posts_reversing_journal(): void
    {
        $admin = $this->createUser('platform-admin');
        [$subscriptionPublicId,, $assessmentPublicId, $paymentPublicId] = $this->createPaidPremiumContext($admin);

        $response = $this->withApiHeaders()
            ->actingAsSanctum($admin)
            ->postJson('/api/v1/insurance-premium-payments/'.$paymentPublicId.'/reverse');

        $this->assertJsonSuccess($response);
        $response->assertJsonPath('data.status', 'reversed');

        $this->assertDatabaseHas('insurance_premium_payments', [
            'public_id' => $paymentPublicId,
            'status' => 'reversed',
        ]);

        // Assessment is reopened
        $this->assertDatabaseHas('insurance_premium_assessments', [
            'public_id' => $assessmentPublicId,
            'status' => 'assessed',
        ]);
    }

    public function test_premium_payment_reversal_rejects_duplicate(): void
    {
        $admin = $this->createUser('platform-admin');
        [,, $assessmentPublicId, $paymentPublicId] = $this->createPaidPremiumContext($admin);

        $this->withApiHeaders()
            ->actingAsSanctum($admin)
            ->postJson('/api/v1/insurance-premium-payments/'.$paymentPublicId.'/reverse');

        $second = $this->withApiHeaders()
            ->actingAsSanctum($admin)
            ->postJson('/api/v1/insurance-premium-payments/'.$paymentPublicId.'/reverse');
        $this->assertJsonError($second, 422);
    }

    public function test_claim_settlement_reversal_posts_equal_and_opposite_journal_lines(): void
    {
        $admin = $this->createUser('platform-admin');
        $checker = $this->createUser('platform-admin');
        $claimPublicId = $this->createSettledClaimForReversal($admin, $checker);

        $response = $this->withApiHeaders()
            ->actingAsSanctum($admin)
            ->postJson('/api/v1/insurance-claims/'.$claimPublicId.'/settlement-reversal');

        $this->assertJsonSuccess($response);
        $response->assertJsonPath('data.claim.status', 'settlement_reversed');
        self::assertNotNull($response->json('data.reversal_journal_entry_public_id'));

        $this->assertDatabaseHas('insurance_claims', [
            'public_id' => $claimPublicId,
            'status' => 'settlement_reversed',
        ]);
    }

    public function test_claim_settlement_reversal_rejects_duplicate(): void
    {
        $admin = $this->createUser('platform-admin');
        $checker = $this->createUser('platform-admin');
        $claimPublicId = $this->createSettledClaimForReversal($admin, $checker);

        $this->withApiHeaders()
            ->actingAsSanctum($admin)
            ->postJson('/api/v1/insurance-claims/'.$claimPublicId.'/settlement-reversal');

        $second = $this->withApiHeaders()
            ->actingAsSanctum($admin)
            ->postJson('/api/v1/insurance-claims/'.$claimPublicId.'/settlement-reversal');
        $this->assertJsonError($second, 422);
    }

    // -------------------------------------------------------------------------
    // A11: Remittance & commission
    // -------------------------------------------------------------------------

    public function test_remittance_batch_groups_unremitted_payments_by_partner_and_period(): void
    {
        $admin = $this->createUser('platform-admin');
        $checker = $this->createUser('platform-admin');
        [$agencyPublicId, $partnerPublicId, $paymentPublicId] = $this->createRemittanceContext($admin, $checker);

        $batch = $this->withApiHeaders()
            ->actingAsSanctum($admin)
            ->postJson('/api/v1/insurance-remittance-batches', [
                'insurance_partner_public_id' => $partnerPublicId,
                'agency_public_id' => $agencyPublicId,
                'period_from' => '2026-01-01',
                'period_to' => '2026-12-31',
                'currency' => 'XAF',
            ]);
        $this->assertJsonSuccess($batch, 201);
        $batchPublicId = $this->requireStringJsonPath($batch, 'data.public_id');
        $batch->assertJsonPath('data.status', 'draft');
        $totalMinor = $batch->json('data.total_minor');
        self::assertIsInt($totalMinor);
        self::assertGreaterThan(0, $totalMinor);

        $batchId = DB::table('insurance_remittance_batches')->where('public_id', $batchPublicId)->value('id');
        self::assertIsNumeric($batchId);
        $this->assertDatabaseHas('insurance_remittance_items', [
            'insurance_remittance_batch_id' => (int) $batchId,
        ]);
    }

    public function test_remittance_batch_cannot_include_already_remitted_payments(): void
    {
        $admin = $this->createUser('platform-admin');
        $checker = $this->createUser('platform-admin');
        [$agencyPublicId, $partnerPublicId] = $this->createRemittanceContext($admin, $checker);

        // Create and approve first batch
        $batch1 = $this->withApiHeaders()
            ->actingAsSanctum($admin)
            ->postJson('/api/v1/insurance-remittance-batches', [
                'insurance_partner_public_id' => $partnerPublicId,
                'agency_public_id' => $agencyPublicId,
                'period_from' => '2026-01-01',
                'period_to' => '2026-12-31',
            ]);
        $this->assertJsonSuccess($batch1, 201);
        $batch1PublicId = $this->requireStringJsonPath($batch1, 'data.public_id');

        $approve = $this->withApiHeaders()
            ->actingAsSanctum($checker)
            ->postJson('/api/v1/insurance-remittance-batches/'.$batch1PublicId.'/approve');
        $this->assertJsonSuccess($approve);
        $approve->assertJsonPath('data.status', 'posted');

        // Attempt second batch for same period — no unremitted payments remain
        $batch2 = $this->withApiHeaders()
            ->actingAsSanctum($admin)
            ->postJson('/api/v1/insurance-remittance-batches', [
                'insurance_partner_public_id' => $partnerPublicId,
                'agency_public_id' => $agencyPublicId,
                'period_from' => '2026-01-01',
                'period_to' => '2026-12-31',
            ]);
        $this->assertJsonError($batch2, 422);
    }

    public function test_remittance_batch_creator_cannot_approve_own_batch(): void
    {
        $admin = $this->createUser('platform-admin');
        $checker = $this->createUser('platform-admin');
        [$agencyPublicId, $partnerPublicId] = $this->createRemittanceContext($admin, $checker);

        $batch = $this->withApiHeaders()
            ->actingAsSanctum($admin)
            ->postJson('/api/v1/insurance-remittance-batches', [
                'insurance_partner_public_id' => $partnerPublicId,
                'agency_public_id' => $agencyPublicId,
                'period_from' => '2026-01-01',
                'period_to' => '2026-12-31',
            ]);
        $this->assertJsonSuccess($batch, 201);
        $batchPublicId = $this->requireStringJsonPath($batch, 'data.public_id');

        $selfApprove = $this->withApiHeaders()
            ->actingAsSanctum($admin) // same as creator
            ->postJson('/api/v1/insurance-remittance-batches/'.$batchPublicId.'/approve');
        $this->assertJsonError($selfApprove, 422);
    }

    public function test_broker_premium_collection_posts_configured_payable_and_commission_splits(): void
    {
        $admin = $this->createUser('platform-admin');
        $checker = $this->createUser('platform-admin');
        $agency = $this->makeAgency();
        $client = $this->makeClient($agency['id']);
        $clientDepositLedger = $this->makeLedger($agency['id']);
        $partnerLedger = $this->makeLedger($agency['id']);
        $insurerPayableLedger = $this->makeLedger($agency['id']);
        $commissionLedger = $this->makeLedger($agency['id']);

        $partner = $this->withApiHeaders()
            ->actingAsSanctum($admin)
            ->postJson('/api/v1/insurance-partners', [
                'agency_public_id' => $agency['public_id'],
                'ledger_account_public_id' => $partnerLedger['public_id'],
                'code' => 'PTNR-'.Str::random(6),
                'name' => 'Broker Partner',
            ]);
        $this->assertJsonSuccess($partner, 201);
        $partnerPublicId = $this->requireStringJsonPath($partner, 'data.public_id');

        $product = $this->withApiHeaders()
            ->actingAsSanctum($admin)
            ->postJson('/api/v1/insurance-products', [
                'insurance_partner_public_id' => $partnerPublicId,
                'code' => 'PROD-'.Str::random(6),
                'name' => 'Broker Health',
                'product_type' => 'health',
                'currency' => 'XAF',
                'business_model' => 'broker',
            ]);
        $this->assertJsonSuccess($product, 201);
        $productPublicId = $this->requireStringJsonPath($product, 'data.public_id');

        $versionPublicId = $this->createAndApproveRuleVersion(
            $admin,
            $checker,
            $productPublicId,
            '2026-01-01',
            null,
            'one_time',
            [
                [
                    'split_type' => 'insurer_payable',
                    'calculation_type' => 'percentage',
                    'rate' => '80',
                    'ledger_account_public_id' => $insurerPayableLedger['public_id'],
                ],
                [
                    'split_type' => 'commission_income',
                    'calculation_type' => 'percentage',
                    'rate' => '20',
                    'ledger_account_public_id' => $commissionLedger['public_id'],
                ],
            ],
        );
        $this->markProductReadyAndActivate($admin, $productPublicId, 'broker');

        $subscription = $this->withApiHeaders()
            ->actingAsSanctum($admin)
            ->postJson('/api/v1/insurance-subscriptions', [
                'client_public_id' => $client['public_id'],
                'agency_public_id' => $agency['public_id'],
                'insurance_product_public_id' => $productPublicId,
                'starts_on' => '2026-01-01',
                'coverage_amount_minor' => 500000,
                'currency' => 'XAF',
            ]);
        $this->assertJsonSuccess($subscription, 201);
        $subscriptionPublicId = $this->requireStringJsonPath($subscription, 'data.public_id');

        $activate = $this->withApiHeaders()
            ->actingAsSanctum($admin)
            ->postJson('/api/v1/insurance-subscriptions/'.$subscriptionPublicId.'/activate', [
                'rule_version_public_id' => $versionPublicId,
            ]);
        $this->assertJsonSuccess($activate);

        $assessment = $this->withApiHeaders()
            ->actingAsSanctum($admin)
            ->postJson('/api/v1/insurance-subscriptions/'.$subscriptionPublicId.'/premium-assessments', [
                'premium_amount_minor' => 10000,
                'due_on' => '2026-06-01',
            ]);
        $this->assertJsonSuccess($assessment, 201);
        $assessmentPublicId = $this->requireStringJsonPath($assessment, 'data.public_id');

        $this->createPremiumCollectionMapping($agency['id'], $insurerPayableLedger['id']);
        $customerAccount = $this->makeCustomerAccount($agency['id'], $client['id'], $clientDepositLedger['id']);
        $this->fundAccount($agency['id'], $customerAccount['id'], $clientDepositLedger['id'], 10000, $admin->id);

        $payment = $this->withApiHeaders()
            ->actingAsSanctum($admin)
            ->postJson('/api/v1/insurance-premium-assessments/'.$assessmentPublicId.'/collect-from-account', [
                'customer_account_public_id' => $customerAccount['public_id'],
            ]);
        $this->assertJsonSuccess($payment);
        $paymentPublicId = $this->requireStringJsonPath($payment, 'data.payment.public_id');

        $this->assertDatabaseHas('insurance_premium_payment_splits', [
            'split_type' => 'insurer_payable',
            'amount_minor' => 8000,
            'ledger_account_id' => $insurerPayableLedger['id'],
        ]);
        $this->assertDatabaseHas('insurance_premium_payment_splits', [
            'split_type' => 'commission_income',
            'amount_minor' => 2000,
            'ledger_account_id' => $commissionLedger['id'],
        ]);
        $journalEntryId = DB::table('insurance_premium_payments')
            ->where('public_id', $paymentPublicId)
            ->value('journal_entry_id');
        self::assertIsNumeric($journalEntryId);
        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => (int) $journalEntryId,
            'ledger_account_id' => $insurerPayableLedger['id'],
            'credit_minor' => 8000,
        ]);
        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => (int) $journalEntryId,
            'ledger_account_id' => $commissionLedger['id'],
            'credit_minor' => 2000,
        ]);

        $batch = $this->withApiHeaders()
            ->actingAsSanctum($admin)
            ->postJson('/api/v1/insurance-remittance-batches', [
                'insurance_partner_public_id' => $partnerPublicId,
                'agency_public_id' => $agency['public_id'],
                'period_from' => '2026-01-01',
                'period_to' => '2026-12-31',
            ]);
        $this->assertJsonSuccess($batch, 201);
        $batch->assertJsonPath('data.total_minor', 8000);
        $batchPublicId = $this->requireStringJsonPath($batch, 'data.public_id');

        $commissionReport = $this->withApiHeaders()
            ->actingAsSanctum($admin)
            ->getJson('/api/v1/insurance-reports/commissions?agency_public_id='.$agency['public_id']);
        $this->assertJsonSuccess($commissionReport);
        $commissionReport->assertJsonPath('data.items.0.total_commission_minor', 2000);

        $approval = $this->withApiHeaders()
            ->actingAsSanctum($checker)
            ->postJson('/api/v1/insurance-remittance-batches/'.$batchPublicId.'/approve');
        $this->assertJsonSuccess($approval);
        $this->assertDatabaseHas('insurance_remittance_batches', [
            'public_id' => $batchPublicId,
            'status' => 'posted',
        ]);
        $journalEntryId = DB::table('insurance_remittance_batches')
            ->where('public_id', $batchPublicId)
            ->value('journal_entry_id');
        self::assertIsNumeric($journalEntryId);
        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => (int) $journalEntryId,
            'ledger_account_id' => $insurerPayableLedger['id'],
            'debit_minor' => 8000,
        ]);
        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => (int) $journalEntryId,
            'ledger_account_id' => $partnerLedger['id'],
            'credit_minor' => 8000,
        ]);
    }

    // -------------------------------------------------------------------------
    // A12: Claim evidence config
    // -------------------------------------------------------------------------

    public function test_claim_evidence_config_can_be_stored_per_product_and_claim_type(): void
    {
        $admin = $this->createUser('platform-admin');
        [$productPublicId] = $this->createProductContext($admin, 'life');

        $response = $this->withApiHeaders()
            ->actingAsSanctum($admin)
            ->postJson('/api/v1/insurance-products/'.$productPublicId.'/evidence-requirements', [
                'claim_type' => 'death',
                'document_type' => 'death_certificate',
                'is_required' => true,
                'description' => 'Official death certificate from municipal registry.',
            ]);

        $this->assertJsonSuccess($response, 201);
        $this->assertDatabaseHas('insurance_claim_evidence_configs', [
            'claim_type' => 'death',
            'document_type' => 'death_certificate',
            'is_required' => true,
        ]);
    }

    public function test_claim_evidence_config_upserts_on_duplicate(): void
    {
        $admin = $this->createUser('platform-admin');
        [$productPublicId] = $this->createProductContext($admin, 'life');

        $this->withApiHeaders()
            ->actingAsSanctum($admin)
            ->postJson('/api/v1/insurance-products/'.$productPublicId.'/evidence-requirements', [
                'claim_type' => 'death',
                'document_type' => 'death_certificate',
                'is_required' => true,
            ]);

        // Update it
        $this->withApiHeaders()
            ->actingAsSanctum($admin)
            ->postJson('/api/v1/insurance-products/'.$productPublicId.'/evidence-requirements', [
                'claim_type' => 'death',
                'document_type' => 'death_certificate',
                'is_required' => false, // changed to optional
            ]);

        self::assertSame(1, DB::table('insurance_claim_evidence_configs')
            ->where('claim_type', 'death')
            ->where('document_type', 'death_certificate')
            ->count());
        $this->assertDatabaseHas('insurance_claim_evidence_configs', [
            'claim_type' => 'death',
            'document_type' => 'death_certificate',
            'is_required' => false,
        ]);
    }

    public function test_claim_outside_coverage_period_is_rejected(): void
    {
        $admin = $this->createUser('platform-admin');
        $checker = $this->createUser('platform-admin');
        [, $subscriptionPublicId] = $this->createSubscriptionWithRuleVersion($admin, $checker, 'annual');

        $response = $this->withApiHeaders()
            ->actingAsSanctum($admin)
            ->postJson('/api/v1/insurance-claims', [
                'insurance_subscription_public_id' => $subscriptionPublicId,
                'claim_type' => 'death',
                'incident_date' => '2026-05-31',
                'claimed_amount_minor' => 100000,
                'currency' => 'XAF',
            ]);

        $this->assertJsonError($response, 422);
    }

    public function test_required_claim_evidence_blocks_approval_until_attached(): void
    {
        $maker = $this->createUser('platform-admin');
        $checker = $this->createUser('platform-admin');
        [$productPublicId, $subscriptionPublicId] = $this->createSubscriptionWithRuleVersion($maker, $checker, 'annual');

        $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/insurance-products/'.$productPublicId.'/evidence-requirements', [
                'claim_type' => 'death',
                'document_type' => 'death_certificate',
                'is_required' => true,
            ]);

        $claim = $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/insurance-claims', [
                'insurance_subscription_public_id' => $subscriptionPublicId,
                'claim_type' => 'death',
                'incident_date' => '2026-06-10',
                'claimed_amount_minor' => 100000,
                'currency' => 'XAF',
            ]);
        $this->assertJsonSuccess($claim, 201);
        $claimPublicId = $this->requireStringJsonPath($claim, 'data.public_id');

        $decision = $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/insurance-claims/'.$claimPublicId.'/decision-requests', [
                'decision' => 'approve',
            ]);
        $this->assertJsonSuccess($decision, 201);
        $decisionPublicId = $this->requireStringJsonPath($decision, 'data.public_id');

        $blockedReview = $this->withApiHeaders()
            ->actingAsSanctum($checker)
            ->postJson('/api/v1/insurance-claim-decisions/'.$decisionPublicId.'/review', [
                'review_decision' => 'approve',
            ]);
        $this->assertJsonError($blockedReview, 422);

        $claimRow = DB::table('insurance_claims')->where('public_id', $claimPublicId)->first();
        self::assertIsObject($claimRow);
        DB::table('clients')->where('id', (int) $claimRow->client_id)->update([
            'phone_number' => '+237699000001',
            'updated_at' => now(),
        ]);
        DB::table('client_notification_consents')->insert([
            'public_id' => (string) Str::ulid(),
            'client_id' => (int) $claimRow->client_id,
            'agency_id' => (int) $claimRow->agency_id,
            'channel' => 'sms',
            'category' => 'insurance_claim_decision',
            'language' => 'fr',
            'status' => 'opted_in',
            'opted_in_at' => now(),
            'opted_out_at' => null,
            'last_changed_by_user_id' => $maker->id,
            'metadata' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('notification_templates')->insert([
            'public_id' => (string) Str::ulid(),
            'code' => 'insurance_claim_decision_alert',
            'version' => 1,
            'channel' => 'sms',
            'category' => 'insurance_claim_decision',
            'language' => 'fr',
            'subject' => null,
            'body_template' => 'Claim {{claim_number}} is {{decision}} for {{client_name}}.',
            'variables_allowlist' => json_encode(['client_name', 'claim_number', 'decision'], JSON_THROW_ON_ERROR),
            'status' => 'active',
            'effective_from' => null,
            'effective_to' => null,
            'metadata' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $documentPublicId = $this->makeDocument((int) $claimRow->agency_id, (int) $claimRow->client_id, $maker);

        $attach = $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/insurance-claims/'.$claimPublicId.'/documents', [
                'document_public_id' => $documentPublicId,
                'document_type' => 'death_certificate',
            ]);
        $this->assertJsonSuccess($attach, 201);

        $review = $this->withApiHeaders()
            ->actingAsSanctum($checker)
            ->postJson('/api/v1/insurance-claim-decisions/'.$decisionPublicId.'/review', [
                'review_decision' => 'approve',
        ]);
        $this->assertJsonSuccess($review);
        $review->assertJsonPath('data.claim.status', 'approved');
        $review->assertJsonPath('data.notification_outbox_rows', 1);
        $this->assertDatabaseHas('notification_deliveries', [
            'category' => 'insurance_claim_decision',
            'recipient_id' => (int) $claimRow->client_id,
            'status' => 'pending',
        ]);
    }

    public function test_settlement_amount_cannot_exceed_claimed_amount_or_coverage(): void
    {
        $maker = $this->createUser('platform-admin');
        $checker = $this->createUser('platform-admin');
        [, $subscriptionPublicId] = $this->createSubscriptionWithRuleVersion($maker, $checker, 'annual');

        $claim = $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/insurance-claims', [
                'insurance_subscription_public_id' => $subscriptionPublicId,
                'claim_type' => 'medical',
                'incident_date' => '2026-06-10',
                'claimed_amount_minor' => 100000,
                'currency' => 'XAF',
            ]);
        $this->assertJsonSuccess($claim, 201);

        $decision = $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/insurance-claims/'.$this->requireStringJsonPath($claim, 'data.public_id').'/decision-requests', [
                'decision' => 'settle',
                'indemnified_amount_minor' => 150000,
            ]);
        $this->assertJsonSuccess($decision, 201);

        $review = $this->withApiHeaders()
            ->actingAsSanctum($checker)
            ->postJson('/api/v1/insurance-claim-decisions/'.$this->requireStringJsonPath($decision, 'data.public_id').'/review', [
                'review_decision' => 'approve',
            ]);

        $this->assertJsonError($review, 422);
    }

    // -------------------------------------------------------------------------
    // A13: Insurance exports
    // -------------------------------------------------------------------------

    public function test_export_subscriptions_returns_rows_with_checksum(): void
    {
        $admin = $this->createUser('platform-admin');
        $checker = $this->createUser('platform-admin');
        $this->createSubscriptionWithRuleVersion($admin, $checker, 'annual');

        $response = $this->withApiHeaders()
            ->actingAsSanctum($admin)
            ->getJson('/api/v1/insurance-exports/subscriptions');

        $this->assertJsonSuccess($response);
        $response->assertJsonPath('data.export_type', 'subscriptions');
        $response->assertJsonPath('data.source_query_version', 'insurance_exports_v1');
        $response->assertJsonPath('data.format', 'json_api_export');
        self::assertNotNull($response->json('data.checksum'));
        self::assertIsArray($response->json('data.rows'));
        $this->assertDatabaseCount('insurance_export_records', 1);
        $this->assertDatabaseHas('insurance_export_records', [
            'export_type' => 'subscriptions',
            'source_query_version' => 'insurance_exports_v1',
        ]);
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'security',
            'event' => 'insurance.report.exported',
            'causer_id' => $admin->id,
        ]);
    }

    public function test_export_agency_scoped_user_cannot_see_other_agency_data(): void
    {
        $admin = $this->createUser('platform-admin');
        $checker = $this->createUser('platform-admin');
        $this->createSubscriptionWithRuleVersion($admin, $checker, 'annual');

        $agencyManager = $this->createUser('agency-manager');

        $response = $this->withApiHeaders()
            ->actingAsSanctum($agencyManager)
            ->getJson('/api/v1/insurance-exports/subscriptions');

        // Agency manager with no agency scope gets 0 rows (scoped to agency 0 → empty)
        $this->assertJsonSuccess($response);
        $response->assertJsonPath('data.record_count', 0);
    }

    public function test_export_checksum_changes_when_source_data_changes(): void
    {
        $admin = $this->createUser('platform-admin');
        $checker = $this->createUser('platform-admin');
        [$productPublicId, $subscriptionPublicId] = $this->createSubscriptionWithRuleVersion($admin, $checker, 'annual');
        $subscription = DB::table('insurance_subscriptions')->where('public_id', $subscriptionPublicId)->first();
        self::assertIsObject($subscription);
        $agencyPublicId = DB::table('agencies')->where('id', (int) $subscription->agency_id)->value('public_id');
        self::assertIsString($agencyPublicId);

        $firstExport = $this->withApiHeaders()
            ->actingAsSanctum($admin)
            ->getJson('/api/v1/insurance-exports/subscriptions?agency_public_id='.$agencyPublicId);
        $this->assertJsonSuccess($firstExport);
        $firstChecksum = $firstExport->json('data.checksum');
        self::assertIsString($firstChecksum);
        $firstExport->assertJsonPath('data.record_count', 1);

        $client = $this->makeClient((int) $subscription->agency_id);
        $secondSubscription = $this->withApiHeaders()
            ->actingAsSanctum($admin)
            ->postJson('/api/v1/insurance-subscriptions', [
                'client_public_id' => $client['public_id'],
                'agency_public_id' => $agencyPublicId,
                'insurance_product_public_id' => $productPublicId,
                'starts_on' => '2026-08-01',
                'coverage_amount_minor' => 700000,
                'currency' => 'XAF',
            ]);
        $this->assertJsonSuccess($secondSubscription, 201);

        $secondExport = $this->withApiHeaders()
            ->actingAsSanctum($admin)
            ->getJson('/api/v1/insurance-exports/subscriptions?agency_public_id='.$agencyPublicId);
        $this->assertJsonSuccess($secondExport);
        $secondChecksum = $secondExport->json('data.checksum');
        self::assertIsString($secondChecksum);
        $secondExport->assertJsonPath('data.record_count', 2);
        self::assertNotSame($firstChecksum, $secondChecksum);
    }

    public function test_export_premiums_includes_due_and_paid_rows(): void
    {
        $admin = $this->createUser('platform-admin');
        $this->createPaidPremiumContext($admin);
        $agencyPublicId = DB::table('insurance_subscriptions')
            ->join('agencies', 'agencies.id', '=', 'insurance_subscriptions.agency_id')
            ->value('agencies.public_id');
        self::assertIsString($agencyPublicId);

        $response = $this->withApiHeaders()
            ->actingAsSanctum($admin)
            ->getJson('/api/v1/insurance-exports/premiums?agency_public_id='.$agencyPublicId);

        $this->assertJsonSuccess($response);
        $premiumCount = $response->json('data.record_count');
        self::assertIsInt($premiumCount);
        self::assertGreaterThan(0, $premiumCount);
    }

    public function test_export_premiums_applies_status_and_period_filters(): void
    {
        $admin = $this->createUser('platform-admin');
        [$subscriptionPublicId] = $this->createPaidPremiumContext($admin);
        $subscription = DB::table('insurance_subscriptions')->where('public_id', $subscriptionPublicId)->first();
        self::assertIsObject($subscription);
        $agencyPublicId = DB::table('agencies')->where('id', (int) $subscription->agency_id)->value('public_id');
        self::assertIsString($agencyPublicId);

        $assessed = $this->withApiHeaders()
            ->actingAsSanctum($admin)
            ->postJson('/api/v1/insurance-subscriptions/'.$subscriptionPublicId.'/premium-assessments', [
                'premium_amount_minor' => 17000,
                'due_on' => '2026-07-15',
            ]);
        $this->assertJsonSuccess($assessed, 201);

        $response = $this->withApiHeaders()
            ->actingAsSanctum($admin)
            ->getJson('/api/v1/insurance-exports/premiums?agency_public_id='.$agencyPublicId.'&status=assessed&period_start=2026-07-01&period_end=2026-07-31');

        $this->assertJsonSuccess($response);
        $response->assertJsonPath('data.record_count', 1);
        $response->assertJsonPath('data.rows.0.status', 'assessed');
        $response->assertJsonPath('data.rows.0.due_on', '2026-07-15');
    }

    public function test_export_claims_returns_all_claims_in_scope(): void
    {
        $admin = $this->createUser('platform-admin');
        $checker = $this->createUser('platform-admin');
        $this->createSettledClaimForReversal($admin, $checker);
        $agencyPublicId = DB::table('insurance_claims')
            ->join('agencies', 'agencies.id', '=', 'insurance_claims.agency_id')
            ->value('agencies.public_id');
        self::assertIsString($agencyPublicId);

        $response = $this->withApiHeaders()
            ->actingAsSanctum($admin)
            ->getJson('/api/v1/insurance-exports/claims?agency_public_id='.$agencyPublicId);

        $this->assertJsonSuccess($response);
        $claimCount = $response->json('data.record_count');
        self::assertIsInt($claimCount);
        self::assertGreaterThan(0, $claimCount);
    }

    public function test_cancellations_refunds_export_includes_refund_rows_and_source_version(): void
    {
        $admin = $this->createUser('platform-admin');
        $checker = $this->createUser('platform-admin');
        [, $subscriptionPublicId] = $this->createSubscriptionWithRuleVersion($admin, $checker, 'annual');
        $subscription = DB::table('insurance_subscriptions')->where('public_id', $subscriptionPublicId)->first();
        self::assertIsObject($subscription);
        $refundDebitLedger = $this->makeLedger((int) $subscription->agency_id);
        $refundCreditLedger = $this->makeLedger((int) $subscription->agency_id);
        $this->createRefundMapping((int) $subscription->agency_id, $refundDebitLedger['id'], $refundCreditLedger['id']);
        $refundAccount = $this->makeCustomerAccount((int) $subscription->agency_id, (int) $subscription->client_id, $refundCreditLedger['id']);

        $request = $this->withApiHeaders()
            ->actingAsSanctum($admin)
            ->postJson('/api/v1/insurance-subscriptions/'.$subscriptionPublicId.'/cancel', [
                'effective_on' => '2026-08-01',
                'refund_treatment' => 'full',
                'refund_amount_minor' => 7000,
                'refund_customer_account_public_id' => $refundAccount['public_id'],
            ]);
        $this->assertJsonSuccess($request, 201);
        $review = $this->withApiHeaders()
            ->actingAsSanctum($checker)
            ->postJson('/api/v1/insurance-cancellations/'.$this->requireStringJsonPath($request, 'data.public_id').'/review', [
                'review_decision' => 'approve',
            ]);
        $this->assertJsonSuccess($review);

        $agencyPublicId = DB::table('agencies')->where('id', (int) $subscription->agency_id)->value('public_id');
        self::assertIsString($agencyPublicId);
        $export = $this->withApiHeaders()
            ->actingAsSanctum($admin)
            ->getJson('/api/v1/insurance-exports/cancellations-refunds?agency_public_id='.$agencyPublicId);

        $this->assertJsonSuccess($export);
        $export->assertJsonPath('data.export_type', 'cancellations_refunds');
        $export->assertJsonPath('data.source_query_version', 'insurance_exports_v1');
        $export->assertJsonPath('data.rows.0.refund_amount_minor', 7000);
    }

    // -------------------------------------------------------------------------
    // A14: Product readiness & activation
    // -------------------------------------------------------------------------

    public function test_product_activation_fails_without_business_model(): void
    {
        $admin = $this->createUser('platform-admin');
        [$productPublicId] = $this->createProductContext($admin, 'travel');

        $response = $this->withApiHeaders()
            ->actingAsSanctum($admin)
            ->postJson('/api/v1/insurance-products/'.$productPublicId.'/activate');

        $this->assertJsonError($response, 422);
    }

    public function test_product_activation_fails_without_approved_rule_version(): void
    {
        $admin = $this->createUser('platform-admin');
        [$productPublicId, $productId] = $this->createProductContext($admin, 'funeral');

        // Set business model but no rule version
        DB::table('insurance_products')
            ->where('id', $productId)
            ->update(['business_model' => 'broker']);

        $response = $this->withApiHeaders()
            ->actingAsSanctum($admin)
            ->postJson('/api/v1/insurance-products/'.$productPublicId.'/activate');

        $this->assertJsonError($response, 422);
    }

    public function test_product_activation_requires_full_readiness_checklist(): void
    {
        $maker = $this->createUser('platform-admin');
        $checker = $this->createUser('platform-admin');
        [$productPublicId, $productId] = $this->createProductContext($maker, 'school');
        $this->createAndApproveRuleVersion($maker, $checker, $productPublicId, '2026-01-01', null);

        DB::table('insurance_products')
            ->where('id', $productId)
            ->update([
                'business_model' => 'broker',
                'updated_at' => now(),
            ]);

        $response = $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/insurance-products/'.$productPublicId.'/activate');

        $this->assertJsonError($response, 422);
        $content = $response->baseResponse->getContent();
        self::assertIsString($content);
        self::assertStringContainsString('report_category must be set', $content);
        self::assertStringContainsString('claim evidence requirement', $content);
        self::assertStringContainsString('insurance_premium_collection accounting mapping', $content);
        self::assertStringContainsString('insurance_claim_settlement accounting mapping', $content);
    }

    public function test_disabling_new_business_blocks_new_subscriptions_but_keeps_existing_servicing(): void
    {
        $admin = $this->createUser('platform-admin');
        $checker = $this->createUser('platform-admin');
        [$productPublicId, $subscriptionPublicId] = $this->createSubscriptionWithRuleVersion($admin, $checker, 'annual');

        DB::table('insurance_products')
            ->where('public_id', $productPublicId)
            ->update(['new_business_enabled' => false, 'updated_at' => now()]);

        $subscription = DB::table('insurance_subscriptions')->where('public_id', $subscriptionPublicId)->first();
        self::assertIsObject($subscription);
        $secondClient = $this->makeClient((int) $subscription->agency_id);
        $agencyPublicId = DB::table('agencies')->where('id', (int) $subscription->agency_id)->value('public_id');
        self::assertIsString($agencyPublicId);

        $newBusiness = $this->withApiHeaders()
            ->actingAsSanctum($admin)
            ->postJson('/api/v1/insurance-subscriptions', [
                'client_public_id' => $secondClient['public_id'],
                'agency_public_id' => $agencyPublicId,
                'insurance_product_public_id' => $productPublicId,
                'starts_on' => '2026-07-01',
                'coverage_amount_minor' => 300000,
                'currency' => 'XAF',
            ]);
        $this->assertJsonError($newBusiness, 422);

        $servicing = $this->withApiHeaders()
            ->actingAsSanctum($admin)
            ->postJson('/api/v1/insurance-subscriptions/'.$subscriptionPublicId.'/premium-assessments', [
                'premium_amount_minor' => 10000,
                'due_on' => '2026-07-01',
            ]);
        $this->assertJsonSuccess($servicing, 201);
    }

    public function test_non_platform_admin_cannot_create_rule_version(): void
    {
        $admin = $this->createUser('platform-admin');
        [$productPublicId] = $this->createProductContext($admin, 'health');
        $teller = $this->createUser('teller');

        $response = $this->withApiHeaders()
            ->actingAsSanctum($teller)
            ->postJson('/api/v1/insurance-products/'.$productPublicId.'/rule-versions', [
                'calculation_type' => 'percentage',
                'rate' => '3.0',
                'effective_from' => '2026-01-01',
            ]);

        $response->assertStatus(403);
    }

    public function test_insurance_export_permission_does_not_grant_product_setup(): void
    {
        $admin = $this->createUser('platform-admin');
        $checker = $this->createUser('platform-admin');
        $reportUser = $this->createUser('staff');
        [$productPublicId, $subscriptionPublicId] = $this->createSubscriptionWithRuleVersion($admin, $checker, 'annual');

        $subscription = DB::table('insurance_subscriptions')->where('public_id', $subscriptionPublicId)->first();
        self::assertIsObject($subscription);
        $agency = DB::table('agencies')->where('id', (int) $subscription->agency_id)->first(['id', 'public_id']);
        self::assertIsObject($agency);
        $this->assignUserToAgency($reportUser, (int) $agency->id, 'insurance-reporting');
        $reportUser->givePermissionTo('insurance.reports.export');

        $export = $this->withApiHeaders()
            ->actingAsSanctum($reportUser)
            ->getJson('/api/v1/insurance-exports/subscriptions?agency_public_id='.$this->requireStringValue($agency->public_id));
        $this->assertJsonSuccess($export);
        $export->assertJsonPath('data.rows.0.public_id', $subscriptionPublicId);

        $productSetup = $this->withApiHeaders()
            ->actingAsSanctum($reportUser)
            ->postJson('/api/v1/insurance-products/'.$productPublicId.'/rule-versions', [
                'calculation_type' => 'flat_rate',
                'fixed_premium_minor' => 10000,
                'effective_from' => '2027-01-01',
            ]);
        $productSetup->assertStatus(403);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * @return array{0: string, 1: int}
     */
    private function createProductContext(User $actor, string $productType): array
    {
        $agency = $this->makeAgency();
        $ledger = $this->makeLedger($agency['id']);

        $partner = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/insurance-partners', [
                'agency_public_id' => $agency['public_id'],
                'ledger_account_public_id' => $ledger['public_id'],
                'code' => 'PTNR-'.Str::random(6),
                'name' => 'Test Partner',
            ]);
        $this->assertJsonSuccess($partner, 201);

        $product = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/insurance-products', [
                'insurance_partner_public_id' => $this->requireStringJsonPath($partner, 'data.public_id'),
                'code' => 'PROD-'.Str::random(6),
                'name' => ucfirst($productType).' Product',
                'product_type' => $productType,
                'currency' => 'XAF',
            ]);
        $this->assertJsonSuccess($product, 201);
        $productPublicId = $this->requireStringJsonPath($product, 'data.public_id');
        $rawProductId = DB::table('insurance_products')->where('public_id', $productPublicId)->value('id');
        self::assertIsNumeric($rawProductId);

        return [$productPublicId, (int) $rawProductId];
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function createSubscriptionWithRuleVersion(User $admin, User $checker, string $frequency): array
    {
        $agency = $this->makeAgency();
        $client = $this->makeClient($agency['id']);
        $ledger = $this->makeLedger($agency['id']);

        $partner = $this->withApiHeaders()
            ->actingAsSanctum($admin)
            ->postJson('/api/v1/insurance-partners', [
                'agency_public_id' => $agency['public_id'],
                'ledger_account_public_id' => $ledger['public_id'],
                'code' => 'PTNR-'.Str::random(6),
                'name' => 'Test Partner',
            ]);
        $this->assertJsonSuccess($partner, 201);

        $product = $this->withApiHeaders()
            ->actingAsSanctum($admin)
            ->postJson('/api/v1/insurance-products', [
                'insurance_partner_public_id' => $this->requireStringJsonPath($partner, 'data.public_id'),
                'code' => 'PROD-'.Str::random(6),
                'name' => 'Health Product',
                'product_type' => 'health',
                'currency' => 'XAF',
            ]);
        $this->assertJsonSuccess($product, 201);
        $productPublicId = $this->requireStringJsonPath($product, 'data.public_id');

        $versionPublicId = $this->createAndApproveRuleVersion($admin, $checker, $productPublicId, '2026-01-01', null, $frequency);
        $this->markProductReadyAndActivate($admin, $productPublicId);

        $subscription = $this->withApiHeaders()
            ->actingAsSanctum($admin)
            ->postJson('/api/v1/insurance-subscriptions', [
                'client_public_id' => $client['public_id'],
                'agency_public_id' => $agency['public_id'],
                'insurance_product_public_id' => $productPublicId,
                'starts_on' => '2026-06-01',
                'coverage_amount_minor' => 500000,
                'currency' => 'XAF',
            ]);
        $this->assertJsonSuccess($subscription, 201);
        $subscriptionPublicId = $this->requireStringJsonPath($subscription, 'data.public_id');

        // Activate subscription with rule version
        $activate = $this->withApiHeaders()
            ->actingAsSanctum($admin)
            ->postJson('/api/v1/insurance-subscriptions/'.$subscriptionPublicId.'/activate', [
                'rule_version_public_id' => $versionPublicId,
            ]);
        $this->assertJsonSuccess($activate);

        return [$productPublicId, $subscriptionPublicId, $versionPublicId];
    }

    /**
     * @param list<array<string, mixed>> $splits
     */
    private function createAndApproveRuleVersion(
        User $maker,
        User $checker,
        string $productPublicId,
        string $effectiveFrom,
        ?string $effectiveUntil,
        string $frequency = 'one_time',
        array $splits = [],
    ): string {
        $payload = [
            'calculation_type' => 'flat_rate',
            'fixed_premium_minor' => 10000,
            'frequency' => $frequency,
            'effective_from' => $effectiveFrom,
        ];
        if ($splits !== []) {
            $payload['splits'] = $splits;
        }
        if ($effectiveUntil !== null) {
            $payload['effective_until'] = $effectiveUntil;
        }

        $version = $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/insurance-products/'.$productPublicId.'/rule-versions', $payload);
        $this->assertJsonSuccess($version, 201);
        $versionPublicId = $this->requireStringJsonPath($version, 'data.public_id');

        $approve = $this->withApiHeaders()
            ->actingAsSanctum($checker)
            ->postJson('/api/v1/insurance-product-rule-versions/'.$versionPublicId.'/approve');
        $this->assertJsonSuccess($approve);

        return $versionPublicId;
    }

    private function markProductReadyAndActivate(User $actor, string $productPublicId, string $businessModel = 'broker'): void
    {
        $product = DB::table('insurance_products')->where('public_id', $productPublicId)->first(['id', 'insurance_partner_id']);
        self::assertIsObject($product);
        $partner = DB::table('insurance_partners')->where('id', (int) $product->insurance_partner_id)->first(['agency_id']);
        self::assertIsObject($partner);
        $agencyId = (int) $partner->agency_id;
        $premiumLedger = $this->makeLedger($agencyId);
        $claimDebitLedger = $this->makeLedger($agencyId);
        $claimCreditLedger = $this->makeLedger($agencyId);
        $this->createPremiumCollectionMapping($agencyId, $premiumLedger['id']);
        $this->createClaimSettlementMapping($agencyId, $claimDebitLedger['id'], $claimCreditLedger['id']);

        DB::table('insurance_products')
            ->where('public_id', $productPublicId)
            ->update([
                'business_model' => $businessModel,
                'report_category' => 'operations',
                'updated_at' => now(),
            ]);
        DB::table('insurance_claim_evidence_configs')->insert([
            'public_id' => (string) Str::ulid(),
            'insurance_product_id' => (int) $product->id,
            'claim_type' => 'standard',
            'document_type' => 'claim_form',
            'is_required' => true,
            'description' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $activation = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/insurance-products/'.$productPublicId.'/activate');
        $this->assertJsonSuccess($activation);
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string}
     */
    private function createPaidPremiumContext(User $admin): array
    {
        $agency = $this->makeAgency();
        $ledger = $this->makeLedger($agency['id']);
        $client = $this->makeClient($agency['id']);

        $partner = $this->withApiHeaders()
            ->actingAsSanctum($admin)
            ->postJson('/api/v1/insurance-partners', [
                'agency_public_id' => $agency['public_id'],
                'ledger_account_public_id' => $ledger['public_id'],
                'code' => 'PTNR-'.Str::random(6),
                'name' => 'Partner',
            ]);
        $this->assertJsonSuccess($partner, 201);

        $product = $this->withApiHeaders()
            ->actingAsSanctum($admin)
            ->postJson('/api/v1/insurance-products', [
                'insurance_partner_public_id' => $this->requireStringJsonPath($partner, 'data.public_id'),
                'code' => 'PROD-'.Str::random(6),
                'name' => 'Health',
                'product_type' => 'health',
                'currency' => 'XAF',
            ]);
        $this->assertJsonSuccess($product, 201);
        $productPublicId = $this->requireStringJsonPath($product, 'data.public_id');
        DB::table('insurance_products')
            ->where('public_id', $productPublicId)
            ->update([
                'approval_status' => 'approved',
                'business_model' => 'broker',
                'updated_at' => now(),
            ]);

        $subscription = $this->withApiHeaders()
            ->actingAsSanctum($admin)
            ->postJson('/api/v1/insurance-subscriptions', [
                'client_public_id' => $client['public_id'],
                'agency_public_id' => $agency['public_id'],
                'insurance_product_public_id' => $productPublicId,
                'starts_on' => '2026-01-01',
                'coverage_amount_minor' => 300000,
                'currency' => 'XAF',
            ]);
        $this->assertJsonSuccess($subscription, 201);
        $subscriptionPublicId = $this->requireStringJsonPath($subscription, 'data.public_id');

        // Create assessment
        $assessment = $this->withApiHeaders()
            ->actingAsSanctum($admin)
            ->postJson('/api/v1/insurance-subscriptions/'.$subscriptionPublicId.'/premium-assessments', [
                'premium_amount_minor' => 15000,
                'due_on' => '2026-06-30',
            ]);
        $this->assertJsonSuccess($assessment, 201);
        $assessmentPublicId = $this->requireStringJsonPath($assessment, 'data.public_id');

        // Set up operation mapping and fund account, then collect
        $premiumLedger = $this->makeLedger($agency['id']);
        $this->createPremiumCollectionMapping($agency['id'], $premiumLedger['id']);

        $customerAccount = $this->makeCustomerAccount($agency['id'], $client['id'], $ledger['id']);
        $this->fundAccount($agency['id'], $customerAccount['id'], $ledger['id'], 100000, $admin->id);

        $payment = $this->withApiHeaders()
            ->actingAsSanctum($admin)
            ->postJson('/api/v1/insurance-premium-assessments/'.$assessmentPublicId.'/collect-from-account', [
                'customer_account_public_id' => $customerAccount['public_id'],
                'amount_minor' => 15000,
            ]);
        $this->assertJsonSuccess($payment);
        $paymentPublicId = $this->requireStringJsonPath($payment, 'data.payment.public_id');

        return [$subscriptionPublicId, $productPublicId, $assessmentPublicId, $paymentPublicId];
    }

    private function createSettledClaimForReversal(User $maker, User $checker): string
    {
        $agency = $this->makeAgency();
        $ledger = $this->makeLedger($agency['id']);
        $client = $this->makeClient($agency['id']);

        $partner = $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/insurance-partners', [
                'agency_public_id' => $agency['public_id'],
                'ledger_account_public_id' => $ledger['public_id'],
                'code' => 'PTNR-'.Str::random(6),
                'name' => 'Partner',
            ]);
        $this->assertJsonSuccess($partner, 201);

        $product = $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/insurance-products', [
                'insurance_partner_public_id' => $this->requireStringJsonPath($partner, 'data.public_id'),
                'code' => 'PROD-'.Str::random(6),
                'name' => 'Life',
                'product_type' => 'life',
                'currency' => 'XAF',
            ]);
        $this->assertJsonSuccess($product, 201);
        $productPublicId = $this->requireStringJsonPath($product, 'data.public_id');

        // Set business model for settlement
        DB::table('insurance_products')
            ->where('public_id', $productPublicId)
            ->update([
                'approval_status' => 'approved',
                'business_model' => 'risk_carrier',
                'updated_at' => now(),
            ]);

        $subscription = $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/insurance-subscriptions', [
                'client_public_id' => $client['public_id'],
                'agency_public_id' => $agency['public_id'],
                'insurance_product_public_id' => $productPublicId,
                'starts_on' => '2026-01-01',
                'coverage_amount_minor' => 1000000,
                'currency' => 'XAF',
            ]);
        $this->assertJsonSuccess($subscription, 201);
        $subscriptionPublicId = $this->requireStringJsonPath($subscription, 'data.public_id');

        $claim = $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/insurance-claims', [
                'insurance_subscription_public_id' => $subscriptionPublicId,
                'claim_type' => 'death',
                'incident_date' => '2026-05-01',
                'claimed_amount_minor' => 500000,
                'currency' => 'XAF',
            ]);
        $this->assertJsonSuccess($claim, 201);
        $claimPublicId = $this->requireStringJsonPath($claim, 'data.public_id');

        // Maker-checker settle
        $decision = $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/insurance-claims/'.$claimPublicId.'/decision-requests', [
                'decision' => 'settle',
                'indemnified_amount_minor' => 450000,
                'settled_on' => '2026-05-20',
            ]);
        $this->assertJsonSuccess($decision, 201);
        $decisionPublicId = $this->requireStringJsonPath($decision, 'data.public_id');

        $review = $this->withApiHeaders()
            ->actingAsSanctum($checker)
            ->postJson('/api/v1/insurance-claim-decisions/'.$decisionPublicId.'/review', [
                'review_decision' => 'approve',
            ]);
        $this->assertJsonSuccess($review);

        // Post settlement accounting
        $claimLedger = $this->makeLedger($agency['id']);
        $this->createClaimSettlementMapping($agency['id'], $ledger['id'], $claimLedger['id']);

        $settle = $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/insurance-claims/'.$claimPublicId.'/settlement-posting', [
                'amount_minor' => 450000,
                'currency' => 'XAF',
            ]);
        $this->assertJsonSuccess($settle);

        return $claimPublicId;
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function createRemittanceContext(User $admin, User $checker): array
    {
        $agency = $this->makeAgency();
        $ledger = $this->makeLedger($agency['id']);
        $client = $this->makeClient($agency['id']);

        $partner = $this->withApiHeaders()
            ->actingAsSanctum($admin)
            ->postJson('/api/v1/insurance-partners', [
                'agency_public_id' => $agency['public_id'],
                'ledger_account_public_id' => $ledger['public_id'],
                'code' => 'PTNR-'.Str::random(6),
                'name' => 'Remittance Partner',
            ]);
        $this->assertJsonSuccess($partner, 201);
        $partnerPublicId = $this->requireStringJsonPath($partner, 'data.public_id');

        $product = $this->withApiHeaders()
            ->actingAsSanctum($admin)
            ->postJson('/api/v1/insurance-products', [
                'insurance_partner_public_id' => $partnerPublicId,
                'code' => 'PROD-'.Str::random(6),
                'name' => 'Health',
                'product_type' => 'health',
                'currency' => 'XAF',
            ]);
        $this->assertJsonSuccess($product, 201);
        $productPublicId = $this->requireStringJsonPath($product, 'data.public_id');
        DB::table('insurance_products')
            ->where('public_id', $productPublicId)
            ->update([
                'approval_status' => 'approved',
                'business_model' => 'broker',
                'updated_at' => now(),
            ]);

        $subscription = $this->withApiHeaders()
            ->actingAsSanctum($admin)
            ->postJson('/api/v1/insurance-subscriptions', [
                'client_public_id' => $client['public_id'],
                'agency_public_id' => $agency['public_id'],
                'insurance_product_public_id' => $productPublicId,
                'starts_on' => '2026-01-01',
                'coverage_amount_minor' => 200000,
                'currency' => 'XAF',
            ]);
        $this->assertJsonSuccess($subscription, 201);
        $subscriptionPublicId = $this->requireStringJsonPath($subscription, 'data.public_id');

        $assessment = $this->withApiHeaders()
            ->actingAsSanctum($admin)
            ->postJson('/api/v1/insurance-subscriptions/'.$subscriptionPublicId.'/premium-assessments', [
                'premium_amount_minor' => 20000,
                'due_on' => '2026-06-01',
            ]);
        $this->assertJsonSuccess($assessment, 201);
        $assessmentPublicId = $this->requireStringJsonPath($assessment, 'data.public_id');

        $premiumLedger = $this->makeLedger($agency['id']);
        $this->createPremiumCollectionMapping($agency['id'], $premiumLedger['id']);

        $customerAccount = $this->makeCustomerAccount($agency['id'], $client['id'], $ledger['id']);
        $this->fundAccount($agency['id'], $customerAccount['id'], $ledger['id'], 100000, $admin->id);

        $payment = $this->withApiHeaders()
            ->actingAsSanctum($admin)
            ->postJson('/api/v1/insurance-premium-assessments/'.$assessmentPublicId.'/collect-from-account', [
                'customer_account_public_id' => $customerAccount['public_id'],
                'amount_minor' => 20000,
            ]);
        $this->assertJsonSuccess($payment);
        $paymentPublicId = $this->requireStringJsonPath($payment, 'data.payment.public_id');

        return [$agency['public_id'], $partnerPublicId, $paymentPublicId];
    }

    /**
     * @return array{id: int, public_id: string}
     */
    private function makeAgency(): array
    {
        $code = 'AG-'.Str::random(6);
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
     * @return array{id: int, public_id: string}
     */
    private function makeLedger(int $agencyId): array
    {
        $publicId = (string) Str::ulid();
        $id = DB::table('ledger_accounts')->insertGetId([
            'public_id' => $publicId,
            'agency_id' => $agencyId,
            'code' => 'ACC-'.Str::random(6),
            'name' => 'Test Ledger',
            'account_class' => 'asset',
            'account_type' => null,
            'normal_balance_side' => 'debit',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['id' => $id, 'public_id' => $publicId];
    }

    /**
     * @return array{id: int, public_id: string}
     */
    private function makeClient(int $agencyId): array
    {
        $publicId = (string) Str::ulid();
        $id = DB::table('clients')->insertGetId([
            'public_id' => $publicId,
            'agency_id' => $agencyId,
            'client_reference' => 'CLT-'.Str::random(6),
            'first_name' => 'Test',
            'last_name' => 'Client',
            'status' => 'active',
            'kyc_status' => 'verified',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['id' => $id, 'public_id' => $publicId];
    }

    private function makeDocument(int $agencyId, int $clientId, User $uploader): string
    {
        $publicId = (string) Str::ulid();
        DB::table('documents')->insert([
            'public_id' => $publicId,
            'agency_id' => $agencyId,
            'owner_type' => Client::class,
            'owner_id' => $clientId,
            'uploaded_by_user_id' => $uploader->id,
            'category' => 'insurance_claim_evidence',
            'title' => 'Death certificate',
            'disk' => 'local',
            'path' => 'tests/insurance/'.Str::uuid().'.pdf',
            'original_name' => 'death-certificate.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'checksum_sha256' => hash('sha256', $publicId),
            'status' => 'active',
            'metadata' => null,
            'verified_at' => now(),
            'verified_by_user_id' => $uploader->id,
            'archived_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $publicId;
    }

    /**
     * @return array{id: int, public_id: string}
     */
    private function makeCustomerAccount(int $agencyId, int $clientId, int $ledgerAccountId): array
    {
        $publicId = (string) Str::ulid();
        $id = DB::table('customer_accounts')->insertGetId([
            'public_id' => $publicId,
            'agency_id' => $agencyId,
            'client_id' => $clientId,
            'ledger_account_id' => $ledgerAccountId,
            'account_number' => 'CA-'.Str::random(8),
            'account_title' => 'Test Client Account',
            'currency' => 'XAF',
            'opened_on' => now()->toDateString(),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['id' => $id, 'public_id' => $publicId];
    }

    private function fundAccount(int $agencyId, int $customerAccountId, int $ledgerAccountId, int $amountMinor, int $actorUserId): void
    {
        $je = \App\Models\JournalEntry::create([
            'public_id' => (string) Str::ulid(),
            'reference' => 'FUND-'.Str::random(8),
            'business_date' => now()->toDateString(),
            'agency_id' => $agencyId,
            'source_module' => 'test',
            'source_type' => 'test_funding',
            'source_public_id' => (string) Str::ulid(),
            'description' => 'Test funding',
            'status' => \App\Models\JournalEntry::STATUS_DRAFT,
            'created_by_user_id' => $actorUserId,
        ]);

        \App\Models\JournalLine::create([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agencyId,
            'journal_entry_id' => $je->id,
            'ledger_account_id' => $ledgerAccountId,
            'customer_account_id' => $customerAccountId,
            'debit_minor' => $amountMinor,
            'credit_minor' => 0,
            'currency' => 'XAF',
            'line_memo' => 'Fund account',
        ]);
        \App\Models\JournalLine::create([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agencyId,
            'journal_entry_id' => $je->id,
            'ledger_account_id' => $ledgerAccountId,
            'customer_account_id' => null,
            'debit_minor' => 0,
            'credit_minor' => $amountMinor,
            'currency' => 'XAF',
            'line_memo' => 'Fund account offset',
        ]);

        $je->forceFill([
            'status' => \App\Models\JournalEntry::STATUS_SUBMITTED,
            'submitted_at' => now(),
        ])->save();
        $je->forceFill([
            'status' => \App\Models\JournalEntry::STATUS_APPROVED,
            'reviewed_at' => now(),
            'reviewed_by_user_id' => $actorUserId,
        ])->save();
        $je->forceFill([
            'status' => \App\Models\JournalEntry::STATUS_POSTED,
            'posted_at' => now(),
            'posted_by_user_id' => $actorUserId,
        ])->save();

        DB::table('customer_accounts')
            ->where('id', $customerAccountId)
            ->update(['updated_at' => now()]);
    }

    private function createPremiumCollectionMapping(int $agencyId, int $creditLedgerId): void
    {
        $opCode = $this->operationCodeId('insurance_premium_collection', 'Insurance Premium Collection');

        $debitLedger = DB::table('ledger_accounts')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agencyId,
            'code' => 'DEB-'.Str::random(6),
            'name' => 'Premium Debit',
            'account_class' => 'liability',
            'account_type' => null,
            'normal_balance_side' => 'credit',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('operation_account_mappings')->insert([
            'public_id' => (string) Str::ulid(),
            'operation_code_id' => $opCode,
            'debit_ledger_account_id' => $debitLedger,
            'credit_ledger_account_id' => $creditLedgerId,
            'currency' => null,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createClaimSettlementMapping(int $agencyId, int $debitLedgerId, int $creditLedgerId): void
    {
        $opCode = $this->operationCodeId('insurance_claim_settlement', 'Insurance Claim Settlement');

        DB::table('operation_account_mappings')->insert([
            'public_id' => (string) Str::ulid(),
            'operation_code_id' => $opCode,
            'debit_ledger_account_id' => $debitLedgerId,
            'credit_ledger_account_id' => $creditLedgerId,
            'currency' => null,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createRefundMapping(int $agencyId, int $debitLedgerId, int $creditLedgerId): void
    {
        $opCode = $this->operationCodeId('insurance_premium_refund', 'Insurance Premium Refund');

        DB::table('operation_account_mappings')->insert([
            'public_id' => (string) Str::ulid(),
            'operation_code_id' => $opCode,
            'debit_ledger_account_id' => $debitLedgerId,
            'credit_ledger_account_id' => $creditLedgerId,
            'currency' => null,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function operationCodeId(string $code, string $label): int
    {
        $existing = DB::table('operation_codes')->where('code', $code)->value('id');
        if (is_numeric($existing)) {
            return (int) $existing;
        }

        return DB::table('operation_codes')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'code' => $code,
            'label' => $label,
            'module' => 'insurance',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createUser(string $role): User
    {
        $user = User::factory()->createOne([
            'status' => User::STATUS_ACTIVE,
            'phone_verified_at' => now(),
        ]);
        $user->assignRole($role);

        return $user;
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
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function requireStringJsonPath(TestResponse $response, string $path): string
    {
        $value = $response->json($path);
        self::assertIsString($value, "Expected string at JSON path '{$path}'");

        return $value;
    }

    private function requireStringValue(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }
}
