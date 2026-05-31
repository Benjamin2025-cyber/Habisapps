<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Application\IslamicFinance\IslamicApprovalWorkflowService;
use App\Application\IslamicFinance\IslamicComplianceCaseService;
use App\Application\IslamicFinance\IslamicFinancedAssetStateMachine;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class IslamicFinanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_product_creation_defaults_to_draft(): void
    {
        $actor = $this->createUserWithRole('platform-admin');

        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-products', [
                'code' => 'MUR-001',
                'name' => 'Murabaha Standard',
                'contract_type' => 'murabaha',
                'rules' => $this->defaultGovernanceRulesFor('murabaha'),
            ]);
        $this->assertJsonSuccess($response, 201);
        $response->assertJsonPath('data.status', 'draft');
        $response->assertJsonPath('data.code', 'MUR-001');
    }

    public function test_draft_product_cannot_be_used_for_financing(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $productPublicId = $this->createProduct($actor, 'MUR-DRAFT', 'murabaha');
        $agency = $this->createAgency('IF-DRAFT');
        $clientPublicId = $this->createClient($agency['id']);

        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings', [
                'client_public_id' => $clientPublicId,
                'agency_public_id' => $agency['public_id'],
                'product_public_id' => $productPublicId,
                'contract_type' => 'murabaha',
                'purchase_cost_minor' => 800000,
                'markup_minor' => 200000,
            ]);
        $this->assertJsonError($response, 422);
    }

    public function test_sharia_compliance_review_maker_checker(): void
    {
        $maker = $this->createUserWithRole('platform-admin');
        $checker = $this->createUserWithRole('platform-admin');
        $this->ensureMourabahaBaseline($maker);
        $this->ensureShariaApprover($checker);
        $productPublicId = $this->createProduct($maker, 'MUR-SHARIA', 'murabaha');

        // Maker requests compliance review
        $review = $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/islamic-products/'.$productPublicId.'/compliance-reviews', [
                'comments' => 'Please review for Sharia compliance',
            ]);
        $this->assertJsonSuccess($review, 201);
        $reviewPublicId = $this->requireStringJsonPath($review, 'data.public_id');
        $review->assertJsonPath('data.status', 'pending');

        // Maker cannot self-approve
        $selfReview = $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/islamic-compliance-reviews/'.$reviewPublicId.'/review', [
                'decision' => 'approve',
            ]);
        $this->assertJsonError($selfReview, 422);

        // Checker approves
        $approve = $this->withApiHeaders()
            ->actingAsSanctum($checker)
            ->postJson('/api/v1/islamic-compliance-reviews/'.$reviewPublicId.'/review', [
                'decision' => 'approve',
            ]);
        $this->assertJsonSuccess($approve);
        $approve->assertJsonPath('data.status', 'approved');

        // Product is now approved
        $this->assertDatabaseHas('islamic_products', [
            'public_id' => $productPublicId,
            'status' => 'approved',
        ]);
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'security',
            'event' => 'islamic.compliance.reviewed',
        ]);
    }

    public function test_compliance_review_can_be_rejected(): void
    {
        $maker = $this->createUserWithRole('platform-admin');
        $checker = $this->createUserWithRole('platform-admin');
        $productPublicId = $this->createProduct($maker, 'MUR-REJ', 'murabaha');

        $review = $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/islamic-products/'.$productPublicId.'/compliance-reviews');
        $this->assertJsonSuccess($review, 201);
        $reviewPublicId = $this->requireStringJsonPath($review, 'data.public_id');

        $reject = $this->withApiHeaders()
            ->actingAsSanctum($checker)
            ->postJson('/api/v1/islamic-compliance-reviews/'.$reviewPublicId.'/review', [
                'decision' => 'reject',
            ]);
        $this->assertJsonSuccess($reject);
        $reject->assertJsonPath('data.status', 'rejected');

        // Product remains draft
        $this->assertDatabaseHas('islamic_products', [
            'public_id' => $productPublicId,
            'status' => 'draft',
        ]);
    }

    public function test_murabaha_financing_creation_with_correct_pricing(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $productPublicId = $this->createApprovedProduct($actor, 'MUR-PRIC', 'murabaha');
        $agency = $this->createAgency('IF-PRIC');
        $clientPublicId = $this->createClient($agency['id']);

        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings', [
                'client_public_id' => $clientPublicId,
                'agency_public_id' => $agency['public_id'],
                'product_public_id' => $productPublicId,
                'contract_type' => 'murabaha',
                'purchase_cost_minor' => 800000,
                'allowed_costs_minor' => 50000,
                'markup_minor' => 150000,
                'supplier_name' => 'Supplier SARL',
            ]);
        $this->assertJsonSuccess($response, 201);
        $response->assertJsonPath('data.status', 'draft');
        $response->assertJsonPath('data.purchase_cost_minor', 800000);
        $response->assertJsonPath('data.allowed_costs_minor', 50000);
        $response->assertJsonPath('data.markup_minor', 150000);
        $response->assertJsonPath('data.sale_price_minor', 1000000);
    }

    public function test_murabaha_with_nonexistent_product_rejected(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('IF-UAP');
        $clientPublicId = $this->createClient($agency['id']);

        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings', [
                'client_public_id' => $clientPublicId,
                'agency_public_id' => $agency['public_id'],
                'product_public_id' => 'nonexistent',
                'contract_type' => 'murabaha',
                'purchase_cost_minor' => 800000,
                'markup_minor' => 200000,
            ]);
        $this->assertJsonError($response, 422);
    }

    public function test_murabaha_with_unapproved_product_rejected(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('IF-UAP2');
        $clientPublicId = $this->createClient($agency['id']);
        $productPublicId = $this->createProduct($actor, 'MUR-UNAPP', 'murabaha');

        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings', [
                'client_public_id' => $clientPublicId,
                'agency_public_id' => $agency['public_id'],
                'product_public_id' => $productPublicId,
                'contract_type' => 'murabaha',
                'purchase_cost_minor' => 800000,
                'markup_minor' => 200000,
            ]);
        $this->assertJsonError($response, 422);
    }

    public function test_unresolved_blocking_review_prevents_activation(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $productPublicId = $this->createApprovedProduct($actor, 'MUR-BLOCK', 'murabaha');
        $agency = $this->createAgency('IF-BLOCK');
        $clientPublicId = $this->createClient($agency['id']);

        $this->insertComplianceCaseWithBlocker(
            $actor->id,
            $productPublicId,
            'product_activation',
            'open',
            null,
            null,
        );

        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings', [
                'client_public_id' => $clientPublicId,
                'agency_public_id' => $agency['public_id'],
                'product_public_id' => $productPublicId,
                'contract_type' => 'murabaha',
                'purchase_cost_minor' => 800000,
                'markup_minor' => 200000,
            ]);
        $this->assertJsonError($response, 422);
        self::assertNotEmpty($response->json('errors.compliance_blockers'));
        $this->assertDatabaseHas('activity_log', [
            'event' => 'islamic.compliance_case.use_blocked',
            'log_name' => 'security',
        ]);
    }

    public function test_conditional_approval_expires_and_blocks_future_action(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $productPublicId = $this->createApprovedProduct($actor, 'MUR-COND', 'murabaha');
        $agency = $this->createAgency('IF-COND');
        $clientPublicId = $this->createClient($agency['id']);

        $this->insertComplianceCaseWithBlocker(
            $actor->id,
            $productPublicId,
            'product_activation',
            'resolved',
            'conditionally_approved',
            CarbonImmutable::now()->subDay()->toDateTimeString(),
        );

        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings', [
                'client_public_id' => $clientPublicId,
                'agency_public_id' => $agency['public_id'],
                'product_public_id' => $productPublicId,
                'contract_type' => 'murabaha',
                'purchase_cost_minor' => 800000,
                'markup_minor' => 200000,
            ]);
        $this->assertJsonError($response, 422);
    }

    public function test_corrective_action_closure_is_audited(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $productPublicId = $this->createApprovedProduct($actor, 'MUR-CORR', 'murabaha');

        $casePublicId = $this->insertComplianceCaseWithBlocker(
            $actor->id,
            $productPublicId,
            'product_activation',
            'blocked',
            'corrective_action_required',
            null,
        );

        app(IslamicComplianceCaseService::class)->recordDecision(
            $casePublicId,
            'corrective_action_closed',
            $actor,
            ['decision_comments' => 'Corrective action evidence validated.'],
        );

        $this->assertDatabaseHas('activity_log', [
            'event' => 'islamic.compliance_case.corrective_action.closed',
        ]);
        $this->assertDatabaseHas('islamic_compliance_case_blockers', [
            'case_id' => DB::table('islamic_compliance_cases')->where('public_id', $casePublicId)->value('id'),
            'is_active' => false,
        ]);
    }

    public function test_invalid_decision_transition_is_rejected(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $productPublicId = $this->createApprovedProduct($actor, 'MUR-INV-DEC', 'murabaha');
        $casePublicId = $this->insertComplianceCaseWithBlocker(
            $actor->id,
            $productPublicId,
            'product_activation',
            'open',
            null,
            null,
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('corrective_action_closed requires prior corrective_action_required.');
        app(IslamicComplianceCaseService::class)->recordDecision(
            $casePublicId,
            'corrective_action_closed',
            $actor,
            ['decision_comments' => 'invalid transition'],
        );
    }

    public function test_review_decision_updates_linked_case_not_unrelated_newer_case(): void
    {
        $maker = $this->createUserWithRole('platform-admin');
        $checker = $this->createUserWithRole('platform-admin');
        $this->ensureMourabahaBaseline($maker);
        $this->ensureShariaApprover($checker);
        $productPublicId = $this->createProduct($maker, 'MUR-LINK', 'murabaha');

        $review = $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/islamic-products/'.$productPublicId.'/compliance-reviews', [
                'comments' => 'linked case',
            ]);
        $this->assertJsonSuccess($review, 201);
        $reviewPublicId = $this->requireStringJsonPath($review, 'data.public_id');

        $linkedCaseId = DB::table('islamic_compliance_cases')
            ->where('subject_type', 'islamic_product')
            ->where('subject_public_id', $productPublicId)
            ->whereRaw("metadata->>'legacy_review_public_id' = ?", [$reviewPublicId])
            ->value('id');
        self::assertNotNull($linkedCaseId);

        DB::table('islamic_compliance_cases')->insert([
            'public_id' => (string) Str::ulid(),
            'subject_type' => 'islamic_product',
            'subject_public_id' => $productPublicId,
            'reason_code' => 'unrelated_newer_case',
            'risk_level' => 'high',
            'checklist_version' => 'v2',
            'status' => 'open',
            'blocking_mode' => 'hard',
            'created_by_user_id' => $maker->id,
            'metadata' => json_encode(['legacy_review_public_id' => 'other-review'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $approve = $this->withApiHeaders()
            ->actingAsSanctum($checker)
            ->postJson('/api/v1/islamic-compliance-reviews/'.$reviewPublicId.'/review', [
                'decision' => 'approve',
            ]);
        $this->assertJsonSuccess($approve);

        $linkedLatest = DB::table('islamic_compliance_cases')->where('id', $linkedCaseId)->value('latest_decision');
        self::assertSame('approved', $linkedLatest);
        $unrelatedLatest = DB::table('islamic_compliance_cases')
            ->where('reason_code', 'unrelated_newer_case')
            ->value('latest_decision');
        self::assertNull($unrelatedLatest);
    }

    public function test_report_endpoint_exposes_active_blocker_and_overdue_case(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $productPublicId = $this->createApprovedProduct($actor, 'MUR-RPT', 'murabaha');
        $casePublicId = $this->insertComplianceCaseWithBlocker(
            $actor->id,
            $productPublicId,
            'product_activation',
            'open',
            null,
            null,
            now()->subDay()->toDateTimeString(),
        );

        $list = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->getJson('/api/v1/islamic-compliance-cases?subject_public_id='.$productPublicId.'&overdue=true&blocker_active=true');
        $this->assertJsonSuccess($list);
        self::assertSame($casePublicId, $list->json('data.0.public_id'));
        self::assertTrue((bool) $list->json('data.0.overdue'));
        self::assertTrue((bool) $list->json('data.0.active_blocker'));

        $summary = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->getJson('/api/v1/islamic-compliance-cases/report/summary');
        $this->assertJsonSuccess($summary);
        self::assertGreaterThanOrEqual(1, $this->asInt($summary->json('data.active_blockers')));

        $timeline = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->getJson('/api/v1/islamic-compliance-cases/'.$casePublicId.'/timeline');
        $this->assertJsonSuccess($timeline);
        self::assertIsArray($timeline->json('data'));
    }

    public function test_compliance_case_assignment_and_decision_evidence_are_persisted_and_audited(): void
    {
        $maker = $this->createUserWithRole('platform-admin');
        $checker = $this->createUserWithRole('platform-admin');
        $this->ensureMourabahaBaseline($maker);
        $this->ensureShariaApprover($checker);
        $productPublicId = $this->createProduct($maker, 'MUR-CASE-AUD', 'murabaha');

        $review = $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/islamic-products/'.$productPublicId.'/compliance-reviews', [
                'assigned_reviewer_user_id' => $checker->id,
                'due_at' => CarbonImmutable::now()->addDays(5)->toDateString(),
                'comments' => 'Reviewer assignment and due date evidence.',
            ]);
        $this->assertJsonSuccess($review, 201);
        $reviewPublicId = $this->requireStringJsonPath($review, 'data.public_id');

        $case = DB::table('islamic_compliance_cases')
            ->where('subject_type', 'islamic_product')
            ->where('subject_public_id', $productPublicId)
            ->whereRaw("metadata->>'legacy_review_public_id' = ?", [$reviewPublicId])
            ->latest('id')
            ->first(['id', 'public_id']);
        self::assertIsObject($case);
        self::assertIsString($case->public_id);
        self::assertTrue(is_numeric($case->id));

        $this->assertDatabaseHas('activity_log', [
            'event' => 'islamic.compliance_case.assigned',
            'log_name' => 'security',
        ]);

        $evidenceDocumentPublicId = $this->createEvidenceDocument($checker);
        $approve = $this->withApiHeaders()
            ->actingAsSanctum($checker)
            ->postJson('/api/v1/islamic-compliance-reviews/'.$reviewPublicId.'/review', [
                'decision' => 'approve',
                'evidence_document_public_id' => $evidenceDocumentPublicId,
            ]);
        $this->assertJsonSuccess($approve);

        $evidenceDocumentId = DB::table('documents')->where('public_id', $evidenceDocumentPublicId)->value('id');
        self::assertIsInt($evidenceDocumentId);
        $this->assertDatabaseHas('islamic_compliance_case_decisions', [
            'case_id' => (int) $case->id,
            'decision' => 'approved',
            'evidence_document_id' => $evidenceDocumentId,
        ]);
    }

    public function test_prohibited_sector_blocks_product_approval(): void
    {
        $maker = $this->createUserWithRole('platform-admin');
        $checker = $this->createUserWithRole('platform-admin');
        $this->ensureMourabahaBaseline($maker);
        $this->ensureShariaApprover($checker);
        $this->seedActiveScreeningPolicyRule('prohibited_sector', 'gambling', 'block');

        $product = $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/islamic-products', [
                'code' => 'MUR-BLOCK-SECTOR',
                'name' => 'Murabaha Blocked Sector',
                'contract_type' => 'murabaha',
                'rules' => array_merge($this->defaultGovernanceRulesFor('murabaha'), [
                    'sector_codes' => ['gambling'],
                ]),
            ]);
        $this->assertJsonSuccess($product, 201);
        $productPublicId = $this->requireStringJsonPath($product, 'data.public_id');

        $review = $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/islamic-products/'.$productPublicId.'/compliance-reviews');
        $this->assertJsonSuccess($review, 201);
        $reviewPublicId = $this->requireStringJsonPath($review, 'data.public_id');

        $approve = $this->withApiHeaders()
            ->actingAsSanctum($checker)
            ->postJson('/api/v1/islamic-compliance-reviews/'.$reviewPublicId.'/review', [
                'decision' => 'approve',
            ]);
        $this->assertJsonError($approve, 422);
        self::assertNotEmpty($approve->json('errors.islamic_screening_policy'));
    }

    public function test_restricted_sector_creates_compliance_review(): void
    {
        $maker = $this->createUserWithRole('platform-admin');
        $checker = $this->createUserWithRole('platform-admin');
        $this->ensureMourabahaBaseline($maker);
        $this->ensureShariaApprover($checker);
        $this->seedActiveScreeningPolicyRule('restricted_sector', 'oil', 'manual_review');

        $product = $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/islamic-products', [
                'code' => 'MUR-REST-SECTOR',
                'name' => 'Murabaha Restricted Sector',
                'contract_type' => 'murabaha',
                'rules' => array_merge($this->defaultGovernanceRulesFor('murabaha'), [
                    'sector_codes' => ['oil'],
                ]),
            ]);
        $this->assertJsonSuccess($product, 201);
        $productPublicId = $this->requireStringJsonPath($product, 'data.public_id');

        $review = $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/islamic-products/'.$productPublicId.'/compliance-reviews');
        $this->assertJsonSuccess($review, 201);
        $reviewPublicId = $this->requireStringJsonPath($review, 'data.public_id');

        $evaluate = $this->withApiHeaders()
            ->actingAsSanctum($checker)
            ->postJson('/api/v1/islamic-screening/evaluate', [
                'subject_type' => 'islamic_product',
                'subject_public_id' => $productPublicId,
                'context_type' => 'product_approval',
                'facts' => [
                    'scope_type' => 'product_family',
                    'scope_value' => 'mourabaha',
                    'sector_codes' => ['oil'],
                ],
                'strict_policy' => true,
            ]);
        $this->assertJsonSuccess($evaluate);
        self::assertSame('manual_review', $evaluate->json('data.result'));
        self::assertIsString($evaluate->json('data.review_case_public_id'));

        $approve = $this->withApiHeaders()
            ->actingAsSanctum($checker)
            ->postJson('/api/v1/islamic-compliance-reviews/'.$reviewPublicId.'/review', [
                'decision' => 'approve',
            ]);
        $this->assertJsonError($approve, 422);
        $this->assertDatabaseHas('islamic_compliance_cases', [
            'subject_type' => 'islamic_product',
            'subject_public_id' => $productPublicId,
            'reason_code' => 'screening_restricted_match',
        ]);
    }

    public function test_policy_version_is_snapshotted_on_result(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $policy = $this->seedActiveScreeningPolicyRule('prohibited_goods', 'alcohol', 'block', 7);

        $eval = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-screening/evaluate', [
                'subject_type' => 'islamic_product',
                'subject_public_id' => (string) Str::ulid(),
                'context_type' => 'product_approval',
                'facts' => [
                    'scope_type' => 'product_family',
                    'scope_value' => 'mourabaha',
                    'goods_codes' => ['alcohol'],
                ],
                'strict_policy' => true,
            ]);
        $this->assertJsonSuccess($eval);
        $resultPublicId = $this->requireStringJsonPath($eval, 'data.public_id');

        $result = DB::table('islamic_screening_results')->where('public_id', $resultPublicId)->first();
        self::assertIsObject($result);
        self::assertSame(7, (int) $result->policy_version);
        self::assertSame($policy['public_id'], $result->policy_public_id);
        self::assertIsString($result->policy_snapshot);
        self::assertStringContainsString('"version":7', $result->policy_snapshot);
    }

    public function test_policy_activation_fails_unless_approval_workflow_is_approved(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $create = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-screening-policies', [
                'code' => 'SP-UNAPP',
                'name' => 'Unapproved Screening Policy',
                'scope_type' => 'product_family',
                'scope_value' => 'mourabaha',
                'effective_from' => now()->subDay()->toDateString(),
            ]);
        $this->assertJsonSuccess($create, 201);
        $policyPublicId = $this->requireStringJsonPath($create, 'data.public_id');

        $rule = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-screening-policies/'.$policyPublicId.'/rules', [
                'rule_type' => 'prohibited_sector',
                'match_key' => 'gambling',
                'action' => 'block',
                'match_operator' => 'equals',
            ]);
        $this->assertJsonSuccess($rule);

        $activate = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-screening-policies/'.$policyPublicId.'/activate');
        $this->assertJsonError($activate, 422);
    }

    public function test_evaluate_endpoint_rejects_unknown_context_type(): void
    {
        $actor = $this->createUserWithRole('platform-admin');

        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-screening/evaluate', [
                'subject_type' => 'islamic_product',
                'subject_public_id' => (string) Str::ulid(),
                'context_type' => 'unknown_context',
                'facts' => ['scope_type' => 'institution'],
            ]);
        $this->assertJsonError($response, 422);
    }

    public function test_strict_policy_missing_active_policy_persists_fail_result(): void
    {
        $actor = $this->createUserWithRole('platform-admin');

        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-screening/evaluate', [
                'subject_type' => 'islamic_financing',
                'subject_public_id' => (string) Str::ulid(),
                'context_type' => 'contract_approval',
                'facts' => ['scope_type' => 'product_family', 'scope_value' => 'mourabaha'],
                'strict_policy' => true,
            ]);
        $this->assertJsonSuccess($response);
        self::assertSame('fail', $response->json('data.result'));
        self::assertStringContainsString('No active screening policy', $this->asString($response->json('data.block_reason')));
    }

    public function test_missing_screening_blocks_contract_activation(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $financingPublicId = $this->createDraftFinancing($actor);
        $agencyId = $this->getFinancingAgencyId($financingPublicId);
        $this->seedMurabahaMappings($actor, $agencyId);
        $this->seedActiveScreeningPolicyRule('restricted_sector', 'retail', 'allow_with_note', 1, 'product_family', 'mourabaha');

        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/assets', [
                'asset_type' => 'vehicle',
                'description' => 'Asset',
            ]);
        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/installments', [
                'installments' => [
                    ['due_on' => '2026-06-01', 'amount_minor' => 500000],
                    ['due_on' => '2026-07-01', 'amount_minor' => 500000],
                ],
            ]);

        DB::table('islamic_screening_policies')->update(['status' => 'suspended']);

        $approve = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/approve');
        $this->assertJsonError($approve, 422);
    }

    public function test_islamic_product_rejects_interest_formula_binding(): void
    {
        $actor = $this->createUserWithRole('platform-admin');

        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-products', [
                'code' => 'MUR-INT-BIND',
                'name' => 'Murabaha Interest Formula',
                'contract_type' => 'murabaha',
                'rules' => array_replace_recursive(
                    $this->defaultGovernanceRulesFor('murabaha'),
                    ['mourabaha_configuration' => ['margin_rule' => ['calculus_class' => 'interest_compounding']]],
                ),
            ]);
        $this->assertJsonError($response, 422);
        self::assertNotEmpty($response->json('errors.islamic_interest_guardrails'));
    }

    public function test_islamic_product_rejects_forbidden_statement_terminology_configuration(): void
    {
        $actor = $this->createUserWithRole('platform-admin');

        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-products', [
                'code' => 'MUR-INT-LABEL',
                'name' => 'Murabaha Forbidden Label',
                'contract_type' => 'murabaha',
                'rules' => array_replace_recursive(
                    $this->defaultGovernanceRulesFor('murabaha'),
                    ['statement_labels' => ['interest_income']],
                ),
            ]);
        $this->assertJsonError($response, 422);
        self::assertNotEmpty($response->json('errors.islamic_interest_guardrails'));
    }

    public function test_islamic_product_allows_approved_statement_terminology_configuration(): void
    {
        $actor = $this->createUserWithRole('platform-admin');

        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-products', [
                'code' => 'MUR-PROFIT-LABEL',
                'name' => 'Murabaha Approved Labels',
                'contract_type' => 'murabaha',
                'rules' => array_replace_recursive(
                    $this->defaultGovernanceRulesFor('murabaha'),
                    ['statement_labels' => ['profit', 'fees', 'rent', 'sale_receivable']],
                ),
            ]);
        $this->assertJsonSuccess($response, 201);
    }

    public function test_missing_allowed_cost_policy_blocks_activation(): void
    {
        $maker = $this->createUserWithRole('platform-admin');
        $checker = $this->createUserWithRole('platform-admin');
        $this->ensureShariaApprover($checker);
        $this->seedMourabahaBaselineWithoutTemplate($maker, withAccountingMapping: true, withContractTemplate: true);
        $this->ensureMourabahaSignoff($maker);

        $productPublicId = $this->createProduct($maker, 'MUR-NO-COST-POL-'.Str::ulid(), 'murabaha');

        $productRow = DB::table('islamic_products')->where('public_id', $productPublicId)->first(['rules']);
        self::assertIsObject($productRow);
        $rules = json_decode((string) ($productRow->rules ?? ''), true);
        self::assertIsArray($rules);
        $mourabahaConfiguration = $rules['mourabaha_configuration'] ?? [];
        self::assertIsArray($mourabahaConfiguration);
        unset($mourabahaConfiguration['allowed_costs_policy']);
        $rules['mourabaha_configuration'] = $mourabahaConfiguration;
        DB::table('islamic_products')->where('public_id', $productPublicId)->update([
            'rules' => json_encode($rules, JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);

        $reviewPublicId = $this->requestProductReview($maker, $productPublicId);
        $approve = $this->withApiHeaders()
            ->actingAsSanctum($checker)
            ->postJson('/api/v1/islamic-compliance-reviews/'.$reviewPublicId.'/review', [
                'decision' => 'approve',
            ]);

        $this->assertJsonError($approve, 422);
        $errors = $approve->json('errors');
        self::assertIsArray($errors);
        self::assertArrayHasKey('islamic_product_mourabaha_configuration.allowed_costs_policy', $errors);
        self::assertNotEmpty($errors['islamic_product_mourabaha_configuration.allowed_costs_policy']);
    }

    public function test_islamic_late_payment_treatment_rejects_interest_penalty_mode(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $productPublicId = $this->createApprovedProduct($actor, 'MUR-LATE-GUARD', 'murabaha');
        $agency = $this->createAgency('IF-LATE-GUARD');
        $clientPublicId = $this->createClient($agency['id']);

        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings', [
                'client_public_id' => $clientPublicId,
                'agency_public_id' => $agency['public_id'],
                'product_public_id' => $productPublicId,
                'contract_type' => 'murabaha',
                'purchase_cost_minor' => 800000,
                'markup_minor' => 200000,
                'late_payment_treatment' => 'interest_penalty',
            ]);
        $this->assertJsonError($response, 422);
        self::assertNotEmpty($response->json('errors.islamic_interest_guardrails'));
    }

    public function test_manual_review_on_contract_approval_creates_contract_blocker_case(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $financingPublicId = $this->createDraftFinancing($actor);
        $agencyId = $this->getFinancingAgencyId($financingPublicId);
        $this->seedMurabahaMappings($actor, $agencyId);
        $this->seedActiveScreeningPolicyRule('restricted_sector', 'retail', 'manual_review', 1, 'product_family', 'mourabaha');

        $financing = DB::table('islamic_financings')->where('public_id', $financingPublicId)->first();
        self::assertIsObject($financing);
        DB::table('islamic_products')->where('id', (int) $financing->islamic_product_id)->update([
            'rules' => json_encode(['sector_codes' => ['retail']], JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);

        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/assets', [
                'asset_type' => 'vehicle',
                'description' => 'Asset',
            ]);
        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/installments', [
                'installments' => [
                    ['due_on' => '2026-06-01', 'amount_minor' => 500000],
                    ['due_on' => '2026-07-01', 'amount_minor' => 500000],
                ],
            ]);

        $approve = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/approve');
        $this->assertJsonError($approve, 422);
        self::assertStringContainsString('Screening result must be pass', $this->asString($approve->json('errors.islamic_financing.0')));

        $caseId = DB::table('islamic_compliance_cases')
            ->where('subject_type', 'islamic_financing')
            ->where('subject_public_id', $financingPublicId)
            ->where('reason_code', 'screening_restricted_match')
            ->value('id');
        self::assertNotNull($caseId);
        $this->assertDatabaseHas('islamic_compliance_case_blockers', [
            'case_id' => $caseId,
            'blocker_type' => 'contract_activation',
            'target_subject_type' => 'islamic_financing',
            'target_subject_public_id' => $financingPublicId,
            'is_active' => true,
        ]);
    }

    public function test_manual_override_without_approved_exception_workflow_is_rejected(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $this->seedActiveScreeningPolicyRule('prohibited_goods', 'alcohol', 'block');
        $exceptionSubjectPublicId = (string) Str::ulid();

        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-screening/evaluate', [
                'subject_type' => 'islamic_product',
                'subject_public_id' => (string) Str::ulid(),
                'context_type' => 'product_approval',
                'facts' => [
                    'scope_type' => 'product_family',
                    'scope_value' => 'mourabaha',
                    'goods_codes' => ['alcohol'],
                ],
                'strict_policy' => true,
                'override_exception_subject_public_id' => $exceptionSubjectPublicId,
            ]);
        $this->assertJsonError($response, 422);
    }

    public function test_approved_exception_workflow_allows_override_with_audit(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $this->seedActiveScreeningPolicyRule('prohibited_goods', 'alcohol', 'block');
        $exceptionSubjectPublicId = (string) Str::ulid();
        $workflow = app(IslamicApprovalWorkflowService::class);
        $workflow->ensureWorkflow('islamic_exception', $exceptionSubjectPublicId, $actor);
        $workflow->submit('islamic_exception', $exceptionSubjectPublicId, $actor);
        $workflow->approve('islamic_exception', $exceptionSubjectPublicId, $actor, ['skip_authority_check' => true]);

        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-screening/evaluate', [
                'subject_type' => 'islamic_product',
                'subject_public_id' => (string) Str::ulid(),
                'context_type' => 'product_approval',
                'facts' => [
                    'scope_type' => 'product_family',
                    'scope_value' => 'mourabaha',
                    'goods_codes' => ['alcohol'],
                ],
                'strict_policy' => true,
                'override_exception_subject_public_id' => $exceptionSubjectPublicId,
            ]);
        $this->assertJsonSuccess($response);
        self::assertSame('pass', $response->json('data.result'));
        $this->assertDatabaseHas('activity_log', [
            'event' => 'islamic.screening.override_approved',
        ]);
    }

    public function test_manual_review_routes_context_specific_blocker_types_for_if021_contexts(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $this->seedActiveScreeningPolicyRule('restricted_sector', 'retail', 'manual_review', 1, 'product_family', 'mourabaha');

        $cases = [
            ['subject_type' => 'islamic_product', 'context_type' => 'product_approval', 'blocker_type' => 'product_activation'],
            ['subject_type' => 'islamic_financing', 'context_type' => 'contract_approval', 'blocker_type' => 'contract_activation'],
            ['subject_type' => 'islamic_supplier', 'context_type' => 'supplier_use', 'blocker_type' => 'supplier_use'],
            ['subject_type' => 'islamic_asset', 'context_type' => 'asset_acceptance', 'blocker_type' => 'asset_acceptance'],
            ['subject_type' => 'islamic_goods', 'context_type' => 'goods_acceptance', 'blocker_type' => 'goods_acceptance'],
            ['subject_type' => 'islamic_project', 'context_type' => 'project_approval', 'blocker_type' => 'project_approval'],
            ['subject_type' => 'investment_account', 'context_type' => 'account_pool_assignment', 'blocker_type' => 'account_pool_assignment'],
        ];

        foreach ($cases as $case) {
            $subjectPublicId = (string) Str::ulid();
            $response = $this->withApiHeaders()
                ->actingAsSanctum($actor)
                ->postJson('/api/v1/islamic-screening/evaluate', [
                    'subject_type' => $case['subject_type'],
                    'subject_public_id' => $subjectPublicId,
                    'context_type' => $case['context_type'],
                    'facts' => [
                        'scope_type' => 'product_family',
                        'scope_value' => 'mourabaha',
                        'sector_codes' => ['retail'],
                    ],
                    'strict_policy' => true,
                ]);
            $this->assertJsonSuccess($response);
            self::assertSame('manual_review', $response->json('data.result'));
            $reviewCasePublicId = $this->requireStringJsonPath($response, 'data.review_case_public_id');
            $caseId = $this->asInt(DB::table('islamic_compliance_cases')->where('public_id', $reviewCasePublicId)->value('id'));
            self::assertGreaterThan(0, $caseId);

            $this->assertDatabaseHas('islamic_compliance_case_blockers', [
                'case_id' => $caseId,
                'blocker_type' => $case['blocker_type'],
                'target_subject_type' => $case['subject_type'],
                'target_subject_public_id' => $subjectPublicId,
                'is_active' => true,
            ]);
        }
    }

    public function test_scoped_policy_resolution_prefers_product_family_over_institution(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $institutionPolicyPublicId = $this->seedActiveScreeningPolicyRule(
            'prohibited_sector',
            'mining',
            'block',
            1,
            'institution',
            null
        )['public_id'];
        $familyPolicyPublicId = $this->seedActiveScreeningPolicyRule(
            'restricted_sector',
            'mining',
            'manual_review',
            1,
            'product_family',
            'mourabaha'
        )['public_id'];

        $eval = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-screening/evaluate', [
                'subject_type' => 'islamic_product',
                'subject_public_id' => (string) Str::ulid(),
                'context_type' => 'product_approval',
                'facts' => [
                    'scope_type' => 'product_family',
                    'scope_value' => 'mourabaha',
                    'sector_codes' => ['mining'],
                ],
                'strict_policy' => true,
            ]);
        $this->assertJsonSuccess($eval);
        self::assertSame('manual_review', $eval->json('data.result'));
        self::assertSame($familyPolicyPublicId, $eval->json('data.policy_public_id'));
        self::assertNotSame($institutionPolicyPublicId, $eval->json('data.policy_public_id'));
    }

    public function test_financing_requires_xaf_client_agency_and_product_agency_alignment(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('IF-AGN1');
        $otherAgency = $this->createAgency('IF-AGN2');
        $clientPublicId = $this->createClient($otherAgency['id']);
        $globalProductPublicId = $this->createApprovedProduct($actor, 'MUR-AGN-G', 'murabaha');

        $wrongClientAgency = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings', [
                'client_public_id' => $clientPublicId,
                'agency_public_id' => $agency['public_id'],
                'product_public_id' => $globalProductPublicId,
                'contract_type' => 'murabaha',
                'purchase_cost_minor' => 800000,
                'markup_minor' => 200000,
            ]);
        $this->assertJsonError($wrongClientAgency, 422);

        $productPublicId = $this->createApprovedProduct($actor, 'MUR-AGN-P', 'murabaha', $otherAgency['public_id']);
        $alignedClientPublicId = $this->createClient($agency['id']);

        $wrongProductAgency = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings', [
                'client_public_id' => $alignedClientPublicId,
                'agency_public_id' => $agency['public_id'],
                'product_public_id' => $productPublicId,
                'contract_type' => 'murabaha',
                'purchase_cost_minor' => 800000,
                'markup_minor' => 200000,
            ]);
        $this->assertJsonError($wrongProductAgency, 422);

        $foreignCurrency = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings', [
                'client_public_id' => $alignedClientPublicId,
                'agency_public_id' => $agency['public_id'],
                'product_public_id' => $globalProductPublicId,
                'contract_type' => 'murabaha',
                'purchase_cost_minor' => 800000,
                'markup_minor' => 200000,
                'currency' => 'USD',
            ]);
        $this->assertJsonError($foreignCurrency, 422);
    }

    public function test_asset_registration_for_financing(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $financingPublicId = $this->createDraftFinancing($actor);

        $asset = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/assets', [
                'asset_type' => 'vehicle',
                'description' => 'Toyota Hilux 2026',
                'purchase_amount_minor' => 800000,
            ]);
        $this->assertJsonSuccess($asset, 201);
        $asset->assertJsonPath('data.asset_type', 'vehicle');
        $asset->assertJsonPath('data.ownership_status', 'pending');

        // Can also register another asset
        $asset2 = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/assets', [
                'asset_type' => 'equipment',
                'description' => 'Office equipment',
            ]);
        $this->assertJsonSuccess($asset2, 201);
    }

    public function test_financing_approval_requires_asset_and_installments_and_mapping(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $financingPublicId = $this->createDraftFinancing($actor);

        // Approve without asset - rejected
        $noAsset = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/approve');
        $this->assertJsonError($noAsset, 422);

        // Add asset
        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/assets', [
                'asset_type' => 'vehicle',
                'description' => 'Asset for test',
            ]);

        // Approve without installments - rejected
        $noInstallments = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/approve');
        $this->assertJsonError($noInstallments, 422);

        // Add installments (sale_price = 1000000)
        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/installments', [
                'installments' => [
                    ['due_on' => '2026-06-01', 'amount_minor' => 500000],
                    ['due_on' => '2026-07-01', 'amount_minor' => 500000],
                ],
            ]);

        // Approve without mapping - rejected
        $noMapping = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/approve');
        $this->assertJsonError($noMapping, 422);
    }

    public function test_purchase_approval_required_before_purchase_evidence_acceptance(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $financingPublicId = $this->createDraftFinancing($actor);

        $attachEvidence = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/purchase-evidence', [
                'evidence_type' => 'supplier_invoice',
                'institution_control_status' => 'owned_by_institution',
            ]);
        $this->assertJsonError($attachEvidence, 422);
        self::assertStringContainsString(
            'Purchase approval is required before purchase evidence can be attached.',
            $this->asString($attachEvidence->json('errors.islamic_mourabaha_purchase_evidence.0'))
        );
    }

    public function test_missing_purchase_evidence_rejected_before_sale_contract_approval(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $financingPublicId = $this->createDraftFinancing($actor);
        $agencyId = $this->getFinancingAgencyId($financingPublicId);
        $this->seedMurabahaMappings($actor, $agencyId);

        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/assets', [
                'asset_type' => 'vehicle',
                'description' => 'Asset',
            ]);
        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/installments', [
                'installments' => [
                    ['due_on' => now()->addMonth()->toDateString(), 'amount_minor' => 500000],
                    ['due_on' => now()->addMonths(2)->toDateString(), 'amount_minor' => 500000],
                ],
            ]);

        $requestPublicId = $this->createMourabahaRequestForFinancing($actor, $financingPublicId);
        $quotePublicId = $this->createMourabahaQuoteForRequest($actor, $requestPublicId);
        $this->approveMourabahaRequestQuote($actor, $requestPublicId, $quotePublicId);
        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/cost-evidence', [
                'cost_type' => 'purchase_cost',
                'amount_minor' => 800000,
            ])
            ->assertCreated();

        $approve = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/approve');

        $this->assertJsonError($approve, 422);
        self::assertStringContainsString(
            'requires purchase/control evidence',
            $this->asString($approve->json('errors.islamic_financing.0'))
        );
    }

    public function test_sale_price_mismatch_rejected(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $productPublicId = $this->createApprovedProduct($actor, 'MUR-MISMATCH', 'murabaha');
        $agency = $this->createAgency('IF-MISMATCH');
        $clientPublicId = $this->createClient($agency['id']);

        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings', [
                'client_public_id' => $clientPublicId,
                'agency_public_id' => $agency['public_id'],
                'product_public_id' => $productPublicId,
                'contract_type' => 'murabaha',
                'purchase_cost_minor' => 800000,
                'allowed_costs_minor' => 50000,
                'markup_minor' => 150000,
                'declared_sale_price_minor' => 999999,
            ]);
        $this->assertJsonError($response, 422);
        self::assertStringContainsString(
            'Declared sale price must equal purchase_cost + allowed_costs + markup.',
            $this->asString($response->json('errors.islamic_financing.0'))
        );
    }

    public function test_origination_snapshot_stores_disclosed_cost_margin_sale_price_terms(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $financingPublicId = $this->createDraftFinancing($actor);
        $agencyId = $this->getFinancingAgencyId($financingPublicId);
        $this->seedMurabahaMappings($actor, $agencyId);

        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/assets', [
                'asset_type' => 'vehicle',
                'description' => 'Asset',
            ]);
        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/installments', [
                'installments' => [
                    ['due_on' => now()->addMonth()->toDateString(), 'amount_minor' => 500000],
                    ['due_on' => now()->addMonths(2)->toDateString(), 'amount_minor' => 500000],
                ],
            ]);
        $this->setupMourabahaOriginationChain($actor, $financingPublicId);

        $approve = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/approve');
        $this->assertJsonSuccess($approve);

        $snapshot = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->getJson('/api/v1/islamic-financings/'.$financingPublicId.'/origination-snapshot');
        $this->assertJsonSuccess($snapshot);
        $snapshot->assertJsonPath('data.snapshot_payload.purchase_cost_minor', 800000);
        $snapshot->assertJsonPath('data.snapshot_payload.allowed_costs_minor', 0);
        $snapshot->assertJsonPath('data.snapshot_payload.markup_minor', 200000);
        $snapshot->assertJsonPath('data.snapshot_payload.sale_price_minor', 1000000);
        self::assertCount(2, (array) $snapshot->json('data.snapshot_payload.schedule_terms'));
    }

    public function test_draft_mapping_blocks_posting(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $financingPublicId = $this->createDraftFinancing($actor);
        $agencyId = $this->getFinancingAgencyId($financingPublicId);
        $this->seedMurabahaMappings($actor, $agencyId);

        $mappingPublicIds = DB::table('operation_account_mappings as map')
            ->join('operation_codes as op', 'op.id', '=', 'map.operation_code_id')
            ->where('op.module', 'islamic_finance')
            ->whereIn('op.code', ['murabaha_receivable', 'murabaha_payable', 'murabaha_profit'])
            ->pluck('map.public_id')
            ->all();

        DB::table('operation_account_mappings')->whereIn('public_id', $mappingPublicIds)->update([
            'approval_status' => 'draft',
            'updated_at' => now(),
        ]);
        DB::table('islamic_approval_workflows')
            ->where('subject_type', 'islamic_mapping')
            ->whereIn('subject_public_id', $mappingPublicIds)
            ->update([
                'current_state' => 'draft',
                'updated_at' => now(),
            ]);

        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/assets', [
                'asset_type' => 'vehicle',
                'description' => 'Asset',
            ]);
        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/installments', [
                'installments' => [
                    ['due_on' => now()->addMonth()->toDateString(), 'amount_minor' => 500000],
                    ['due_on' => now()->addMonths(2)->toDateString(), 'amount_minor' => 500000],
                ],
            ]);
        $this->setupMourabahaOriginationChain($actor, $financingPublicId);

        $approve = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/approve');

        $this->assertJsonError($approve, 422);
        self::assertStringContainsString('Approved Islamic mapping is required', $this->asString($approve->json('errors.islamic_financing.0')));
    }

    public function test_expired_mapping_blocks_posting(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $financingPublicId = $this->createDraftFinancing($actor);
        $agencyId = $this->getFinancingAgencyId($financingPublicId);
        $this->seedMurabahaMappings($actor, $agencyId);

        $profitMappingPublicId = DB::table('operation_account_mappings as map')
            ->join('operation_codes as op', 'op.id', '=', 'map.operation_code_id')
            ->where('op.code', 'murabaha_profit')
            ->where('op.module', 'islamic_finance')
            ->value('map.public_id');
        self::assertIsString($profitMappingPublicId);

        DB::table('operation_account_mappings')
            ->where('public_id', $profitMappingPublicId)
            ->update([
                'effective_from' => now()->subDays(2)->toDateString(),
                'effective_to' => now()->subDay()->toDateString(),
                'updated_at' => now(),
            ]);

        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/assets', [
                'asset_type' => 'vehicle',
                'description' => 'Asset',
            ]);
        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/installments', [
                'installments' => [
                    ['due_on' => now()->addMonth()->toDateString(), 'amount_minor' => 500000],
                    ['due_on' => now()->addMonths(2)->toDateString(), 'amount_minor' => 500000],
                ],
            ]);
        $this->setupMourabahaOriginationChain($actor, $financingPublicId);

        $approve = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/approve');

        $this->assertJsonError($approve, 422);
        self::assertStringContainsString('murabaha_profit', $this->asString($approve->json('errors.islamic_financing.0')));
    }

    public function test_sharia_required_mapping_blocks_until_sharia_approved(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $financingPublicId = $this->createDraftFinancing($actor);
        $agencyId = $this->getFinancingAgencyId($financingPublicId);
        $this->seedMurabahaMappings($actor, $agencyId);

        $payableMappingPublicId = DB::table('operation_account_mappings as map')
            ->join('operation_codes as op', 'op.id', '=', 'map.operation_code_id')
            ->where('op.code', 'murabaha_payable')
            ->where('op.module', 'islamic_finance')
            ->value('map.public_id');
        self::assertIsString($payableMappingPublicId);

        DB::table('operation_account_mappings')
            ->where('public_id', $payableMappingPublicId)
            ->update([
                'sharia_approval_required' => true,
                'sharia_approval_status' => 'pending',
                'updated_at' => now(),
            ]);

        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/assets', [
                'asset_type' => 'vehicle',
                'description' => 'Asset',
            ]);
        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/installments', [
                'installments' => [
                    ['due_on' => now()->addMonth()->toDateString(), 'amount_minor' => 500000],
                    ['due_on' => now()->addMonths(2)->toDateString(), 'amount_minor' => 500000],
                ],
            ]);
        $this->setupMourabahaOriginationChain($actor, $financingPublicId);

        $blocked = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/approve');
        $this->assertJsonError($blocked, 422);
        self::assertStringContainsString('requires Sharia approval', $this->asString($blocked->json('errors.islamic_financing.0')));

        DB::table('operation_account_mappings')
            ->where('public_id', $payableMappingPublicId)
            ->update([
                'sharia_approval_status' => 'approved',
                'updated_at' => now(),
            ]);

        $approved = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/approve');
        $this->assertJsonSuccess($approved);
    }

    public function test_mapping_use_blocked_event_is_audited(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $financingPublicId = $this->createDraftFinancing($actor);
        $agencyId = $this->getFinancingAgencyId($financingPublicId);
        $this->seedMurabahaMappings($actor, $agencyId);

        $mappingPublicIds = DB::table('operation_account_mappings as map')
            ->join('operation_codes as op', 'op.id', '=', 'map.operation_code_id')
            ->where('op.module', 'islamic_finance')
            ->whereIn('op.code', ['murabaha_receivable', 'murabaha_payable', 'murabaha_profit'])
            ->pluck('map.public_id')
            ->all();
        DB::table('operation_account_mappings')
            ->whereIn('public_id', $mappingPublicIds)
            ->update([
                'approval_status' => 'draft',
                'updated_at' => now(),
            ]);

        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/assets', [
                'asset_type' => 'vehicle',
                'description' => 'Asset',
            ]);
        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/installments', [
                'installments' => [
                    ['due_on' => now()->addMonth()->toDateString(), 'amount_minor' => 500000],
                    ['due_on' => now()->addMonths(2)->toDateString(), 'amount_minor' => 500000],
                ],
            ]);
        $this->setupMourabahaOriginationChain($actor, $financingPublicId);

        $blocked = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/approve');
        $this->assertJsonError($blocked, 422);
        self::assertStringContainsString('Approved Islamic mapping is required', $this->asString($blocked->json('errors.islamic_financing.0')));

        $this->assertDatabaseHas('activity_log', [
            'event' => 'islamic.mapping.use_blocked',
            'log_name' => 'security',
        ]);
    }

    public function test_islamic_mapping_workflow_endpoints_enforce_sharia_and_lifecycle(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('IF-MAP-API');
        $ledgerId = $this->seedLedger($agency['id']);
        $ledger = DB::table('ledger_accounts')->where('id', $ledgerId)->first(['public_id']);
        self::assertIsObject($ledger);
        self::assertIsString($ledger->public_id);

        $operationCodePublicId = (string) Str::ulid();
        DB::table('operation_codes')->insert([
            'public_id' => $operationCodePublicId,
            'code' => 'if_map_api_'.Str::ulid(),
            'label' => 'Islamic mapping API operation',
            'module' => 'islamic_finance',
            'operation_type' => null,
            'direction' => null,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $create = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-mappings', [
                'operation_code_public_id' => $operationCodePublicId,
                'agency_public_id' => $agency['public_id'],
                'debit_ledger_account_public_id' => $ledger->public_id,
                'currency' => 'XAF',
                'effective_from' => now()->subDay()->toDateString(),
                'sharia_approval_required' => true,
            ]);
        $this->assertJsonSuccess($create, 201);
        $mappingPublicId = $this->requireStringJsonPath($create, 'data.public_id');
        $create->assertJsonPath('data.approval_status', 'draft');
        $create->assertJsonPath('data.status', 'inactive');

        $submit = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-mappings/'.$mappingPublicId.'/submit');
        $this->assertJsonSuccess($submit);
        $submit->assertJsonPath('data.approval_status', 'submitted');
        $submit->assertJsonPath('data.status', 'inactive');

        $approveBlocked = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-mappings/'.$mappingPublicId.'/approve');
        $this->assertJsonError($approveBlocked, 422);
        self::assertStringContainsString('Sharia-required mapping cannot be approved', $this->asString($approveBlocked->json('errors.islamic_mapping.0')));

        $approve = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-mappings/'.$mappingPublicId.'/approve', [
                'sharia_approval_status' => 'approved',
            ]);
        $this->assertJsonSuccess($approve);
        $approve->assertJsonPath('data.approval_status', 'approved');
        $approve->assertJsonPath('data.status', 'active');
        $approve->assertJsonPath('data.sharia_approval_status', 'approved');

        $suspend = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-mappings/'.$mappingPublicId.'/suspend');
        $this->assertJsonSuccess($suspend);
        $suspend->assertJsonPath('data.approval_status', 'suspended');
        $suspend->assertJsonPath('data.status', 'inactive');
    }

    public function test_missing_charity_treatment_blocks_late_fee_event(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('IF-TP-CHARITY-BLOCK');
        $nonCompliantCode = 'if_non_compliant_'.Str::lower(Str::random(6));
        $this->createApprovedTreatmentMapping($actor, $agency['id'], $nonCompliantCode);

        $policyPublicId = $this->createApprovedTreatmentPolicy(
            actor: $actor,
            agencyPublicId: $agency['public_id'],
            overrides: [
                'zakat_enabled' => false,
                'charity_treatment_enabled' => false,
                'non_compliant_income_treatment_enabled' => true,
                'required_operation_codes' => [
                    'non_compliant_income_detected' => $nonCompliantCode,
                ],
            ],
        );

        $createEvent = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-treatment-events', [
                'event_type' => 'late_payment_fee',
                'amount_minor' => 2000,
                'currency' => 'XAF',
                'policy_public_id' => $policyPublicId,
                'agency_public_id' => $agency['public_id'],
                'occurred_on' => now()->toDateString(),
            ]);

        $this->assertJsonError($createEvent, 422);
        self::assertStringContainsString('Late-payment treatment is not enabled', $this->asString($createEvent->json('errors.islamic_treatment_event.0')));
    }

    public function test_zakat_mapping_required_when_zakat_policy_enabled(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('IF-TP-ZAKAT-BLOCK');
        $zakatCode = 'if_zakat_post_'.Str::lower(Str::random(6));

        $policyPublicId = $this->createApprovedTreatmentPolicy(
            actor: $actor,
            agencyPublicId: $agency['public_id'],
            overrides: [
                'zakat_enabled' => true,
                'charity_treatment_enabled' => false,
                'non_compliant_income_treatment_enabled' => false,
                'required_operation_codes' => [
                    'zakat_posting' => $zakatCode,
                ],
            ],
        );

        $createEvent = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-treatment-events', [
                'event_type' => 'zakat_posting',
                'amount_minor' => 5000,
                'currency' => 'XAF',
                'policy_public_id' => $policyPublicId,
                'agency_public_id' => $agency['public_id'],
                'occurred_on' => now()->toDateString(),
            ]);
        $this->assertJsonSuccess($createEvent, 201);
        $eventPublicId = $this->requireStringJsonPath($createEvent, 'data.public_id');

        $post = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-treatment-events/'.$eventPublicId.'/post');
        $this->assertJsonError($post, 422);
        self::assertStringContainsString('Approved Islamic mapping is required', $this->asString($post->json('errors.islamic_treatment_event.0')));
    }

    public function test_non_compliant_income_cannot_post_to_ordinary_profit_mapping(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('IF-TP-NC-BLOCK');
        $this->createApprovedTreatmentMapping($actor, $agency['id'], 'murabaha_profit');

        $policyPublicId = $this->createApprovedTreatmentPolicy(
            actor: $actor,
            agencyPublicId: $agency['public_id'],
            overrides: [
                'zakat_enabled' => false,
                'charity_treatment_enabled' => false,
                'non_compliant_income_treatment_enabled' => true,
                'required_operation_codes' => [
                    'non_compliant_income_detected' => 'murabaha_profit',
                ],
            ],
        );

        $createEvent = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-treatment-events', [
                'event_type' => 'non_compliant_income_detected',
                'amount_minor' => 3200,
                'currency' => 'XAF',
                'policy_public_id' => $policyPublicId,
                'agency_public_id' => $agency['public_id'],
                'occurred_on' => now()->toDateString(),
            ]);
        $this->assertJsonSuccess($createEvent, 201);
        $eventPublicId = $this->requireStringJsonPath($createEvent, 'data.public_id');

        $post = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-treatment-events/'.$eventPublicId.'/post');
        $this->assertJsonError($post, 422);
        self::assertStringContainsString('cannot use ordinary profit', $this->asString($post->json('errors.islamic_treatment_event.0')));
    }

    public function test_expired_treatment_policy_blocks_new_event_routing(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('IF-TP-EXP');
        $lateFeeCode = 'if_late_fee_exp_'.Str::lower(Str::random(6));
        $this->createApprovedTreatmentMapping($actor, $agency['id'], $lateFeeCode);

        $policyPublicId = $this->createApprovedTreatmentPolicy(
            actor: $actor,
            agencyPublicId: $agency['public_id'],
            overrides: [
                'zakat_enabled' => false,
                'charity_treatment_enabled' => true,
                'non_compliant_income_treatment_enabled' => false,
                'required_operation_codes' => [
                    'late_payment_fee' => $lateFeeCode,
                ],
                'effective_from' => now()->subDays(3)->toDateString(),
                'effective_to' => now()->subDay()->toDateString(),
            ],
        );

        $createEvent = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-treatment-events', [
                'event_type' => 'late_payment_fee',
                'amount_minor' => 2100,
                'currency' => 'XAF',
                'policy_public_id' => $policyPublicId,
                'agency_public_id' => $agency['public_id'],
                'occurred_on' => now()->toDateString(),
            ]);
        $this->assertJsonError($createEvent, 422);
        self::assertStringContainsString('not effective', $this->asString($createEvent->json('errors.islamic_treatment_event.0')));
    }

    public function test_reconciliation_report_matches_source_events_to_posted_journals(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('IF-TP-RECON');
        $lateFeeCode = 'if_late_fee_charity_'.Str::lower(Str::random(6));
        $this->createApprovedTreatmentMapping($actor, $agency['id'], $lateFeeCode);

        $policyPublicId = $this->createApprovedTreatmentPolicy(
            actor: $actor,
            agencyPublicId: $agency['public_id'],
            overrides: [
                'zakat_enabled' => false,
                'charity_treatment_enabled' => true,
                'non_compliant_income_treatment_enabled' => false,
                'required_operation_codes' => [
                    'late_payment_fee' => $lateFeeCode,
                ],
            ],
        );

        $event = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-treatment-events', [
                'event_type' => 'late_payment_fee',
                'amount_minor' => 7500,
                'currency' => 'XAF',
                'policy_public_id' => $policyPublicId,
                'agency_public_id' => $agency['public_id'],
                'occurred_on' => now()->toDateString(),
                'event_reference' => 'LATE-FEE-001',
            ]);
        $this->assertJsonSuccess($event, 201);
        $eventPublicId = $this->requireStringJsonPath($event, 'data.public_id');

        $posted = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-treatment-events/'.$eventPublicId.'/post');
        $this->assertJsonSuccess($posted);
        $posted->assertJsonPath('data.status', 'posted');

        $report = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->getJson('/api/v1/islamic-treatment-reports/reconciliation?policy_public_id='.$policyPublicId);
        $this->assertJsonSuccess($report);
        $report->assertJsonPath('data.source_total_minor', 7500);
        $report->assertJsonPath('data.posted_total_minor', 7500);
        $report->assertJsonPath('data.reconciled', true);
    }

    public function test_financing_approval_posts_journal_with_correct_lines(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $financingPublicId = $this->createDraftFinancing($actor);
        $agencyId = $this->getFinancingAgencyId($financingPublicId);
        $otherAgency = $this->createAgency('IF-MAP2');

        $this->seedMurabahaMappings($actor, $agencyId, wrongAgencyId: $otherAgency['id']);

        // Add asset
        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/assets', [
                'asset_type' => 'vehicle',
                'description' => 'Asset',
            ]);

        // Add installments
        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/installments', [
                'installments' => [
                    ['due_on' => '2026-06-01', 'amount_minor' => 500000],
                    ['due_on' => '2026-07-01', 'amount_minor' => 500000],
                ],
            ]);
        $this->setupMourabahaOriginationChain($actor, $financingPublicId);

        // Approve
        $approve = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/approve');
        $this->assertJsonSuccess($approve);
        $approve->assertJsonPath('data.status', 'approved');

        // Journal entry exists
        $this->assertDatabaseHas('journal_entries', [
            'source_module' => 'islamic_finance',
            'source_public_id' => $financingPublicId,
            'status' => JournalEntry::STATUS_POSTED,
        ]);

        // Journal lines exist for receivable, payable, and profit
        $journalEntry = JournalEntry::query()
            ->where('source_module', 'islamic_finance')
            ->where('source_public_id', $financingPublicId)
            ->first();
        self::assertNotNull($journalEntry);
        self::assertCount(3, $journalEntry->lines);

        $saleLine = $journalEntry->lines->firstWhere('debit_minor', 1000000);
        self::assertNotNull($saleLine);
        self::assertSame(0, $saleLine->credit_minor);

        $costLine = $journalEntry->lines->firstWhere('credit_minor', 800000);
        self::assertNotNull($costLine);
        self::assertSame(0, $costLine->debit_minor);

        $profitLine = $journalEntry->lines->firstWhere('credit_minor', 200000);
        self::assertNotNull($profitLine);
        self::assertSame(0, $profitLine->debit_minor);

        // Asset ownership updated
        $this->assertDatabaseHas('islamic_financed_assets', [
            'ownership_status' => 'owned_by_institution',
        ]);
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'security',
            'event' => 'islamic.asset.ownership_transferred_to_institution',
        ]);
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'security',
            'event' => 'islamic.financing.approved',
        ]);

        // Interest fields remain null/unused
        $this->assertDatabaseMissing('journal_entries', [
            'source_module' => 'loan',
            'source_public_id' => $financingPublicId,
        ]);
    }

    public function test_financing_approval_posts_allowed_costs_to_payable(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $financingPublicId = $this->createDraftFinancing($actor, allowedCosts: 50000, markup: 150000);
        $agencyId = $this->getFinancingAgencyId($financingPublicId);
        $this->seedMurabahaMappings($actor, $agencyId);

        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/assets', [
                'asset_type' => 'vehicle',
                'description' => 'Asset',
            ]);

        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/installments', [
                'installments' => [
                    ['due_on' => '2026-06-01', 'amount_minor' => 500000],
                    ['due_on' => '2026-07-01', 'amount_minor' => 500000],
                ],
            ]);
        $this->setupMourabahaOriginationChain($actor, $financingPublicId);

        $approve = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/approve');
        $this->assertJsonSuccess($approve);

        $journalEntry = JournalEntry::query()
            ->where('source_module', 'islamic_finance')
            ->where('source_public_id', $financingPublicId)
            ->first();
        self::assertNotNull($journalEntry);

        self::assertNotNull($journalEntry->lines->firstWhere('debit_minor', 1000000));
        self::assertNotNull($journalEntry->lines->firstWhere('credit_minor', 850000));
        self::assertNotNull($journalEntry->lines->firstWhere('credit_minor', 150000));
    }

    public function test_installment_total_must_equal_sale_price(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $financingPublicId = $this->createDraftFinancing($actor);

        $wrongTotal = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/installments', [
                'installments' => [
                    ['due_on' => '2026-06-01', 'amount_minor' => 300000],
                ],
            ]);
        $this->assertJsonError($wrongTotal, 422);
    }

    public function test_collection_allocates_against_installments_and_updates_outstanding(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $financingPublicId = $this->createDraftFinancing($actor);
        $agencyId = $this->getFinancingAgencyId($financingPublicId);
        $this->seedMurabahaMappings($actor, $agencyId);
        $this->seedMurabahaCollectionMapping($actor, $agencyId, 'murabaha_collection');
        $this->setupMourabahaOriginationChain($actor, $financingPublicId);

        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/assets', [
                'asset_type' => 'vehicle',
                'description' => 'Asset',
            ])->assertCreated();
        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/installments', [
                'installments' => [
                    ['due_on' => now()->addMonth()->toDateString(), 'amount_minor' => 500000],
                    ['due_on' => now()->addMonths(2)->toDateString(), 'amount_minor' => 500000],
                ],
            ])->assertCreated();
        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/approve')
            ->assertOk();

        $collect = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/collections', [
                'amount_minor' => 300000,
            ]);
        $this->assertJsonSuccess($collect, 201);
        $collect->assertJsonPath('data.allocations.0.allocated_minor', 300000);

        $ledger = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->getJson('/api/v1/islamic-financings/'.$financingPublicId.'/receivable-ledger');
        $this->assertJsonSuccess($ledger);
        $ledger->assertJsonPath('data.outstanding_minor', 700000);
        self::assertNotEmpty($ledger->json('data.ledger_items'));
        $ledger->assertJsonPath('meta.pagination.current_page', 1);
    }

    public function test_collection_requires_approved_financing_activation(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $financingPublicId = $this->createDraftFinancing($actor);
        $agencyId = $this->getFinancingAgencyId($financingPublicId);
        $this->seedMurabahaCollectionMapping($actor, $agencyId, 'murabaha_collection');

        $collect = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/collections', [
                'amount_minor' => 100000,
            ]);

        $this->assertJsonError($collect, 422);
        self::assertStringContainsString('approved financings', $this->asString($collect->json('errors.islamic_mourabaha_collection.0')));
    }

    public function test_interest_revenue_mapping_rejected_for_collection(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $financingPublicId = $this->createDraftFinancing($actor);
        $agencyId = $this->getFinancingAgencyId($financingPublicId);
        $this->seedMurabahaMappings($actor, $agencyId);
        $this->seedMurabahaCollectionMapping($actor, $agencyId, 'interest_revenue_collection');
        $this->setupMourabahaOriginationChain($actor, $financingPublicId);

        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/assets', [
                'asset_type' => 'vehicle',
                'description' => 'Asset',
            ])->assertCreated();
        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/installments', [
                'installments' => [
                    ['due_on' => now()->addMonth()->toDateString(), 'amount_minor' => 500000],
                    ['due_on' => now()->addMonths(2)->toDateString(), 'amount_minor' => 500000],
                ],
            ])->assertCreated();
        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/approve')
            ->assertOk();

        $collect = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/collections', [
                'amount_minor' => 100000,
                'operation_code' => 'interest_revenue_collection',
            ]);
        $this->assertJsonError($collect, 422);
        self::assertStringContainsString('conventional interest mapping', $this->asString($collect->json('errors.islamic_mourabaha_collection.0')));
    }

    public function test_over_collection_is_rejected(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $financingPublicId = $this->createDraftFinancing($actor);
        $agencyId = $this->getFinancingAgencyId($financingPublicId);
        $this->seedMurabahaMappings($actor, $agencyId);
        $this->seedMurabahaCollectionMapping($actor, $agencyId, 'murabaha_collection');
        $this->setupMourabahaOriginationChain($actor, $financingPublicId);

        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/assets', [
                'asset_type' => 'vehicle',
                'description' => 'Asset',
            ])->assertCreated();
        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/installments', [
                'installments' => [
                    ['due_on' => now()->addMonth()->toDateString(), 'amount_minor' => 500000],
                    ['due_on' => now()->addMonths(2)->toDateString(), 'amount_minor' => 500000],
                ],
            ])->assertCreated();
        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/approve')
            ->assertOk();

        $collect = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/collections', [
                'amount_minor' => 1500000,
            ]);
        $this->assertJsonError($collect, 422);
        self::assertStringContainsString('cannot exceed outstanding', $this->asString($collect->json('errors.islamic_mourabaha_collection.0')));
    }

    public function test_reversal_offsets_original_journal_effect(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $financingPublicId = $this->createDraftFinancing($actor);
        $agencyId = $this->getFinancingAgencyId($financingPublicId);
        $this->seedMurabahaMappings($actor, $agencyId);
        $this->seedMurabahaCollectionMapping($actor, $agencyId, 'murabaha_collection');
        $this->setupMourabahaOriginationChain($actor, $financingPublicId);

        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/assets', [
                'asset_type' => 'vehicle',
                'description' => 'Asset',
            ])->assertCreated();
        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/installments', [
                'installments' => [
                    ['due_on' => now()->addMonth()->toDateString(), 'amount_minor' => 500000],
                    ['due_on' => now()->addMonths(2)->toDateString(), 'amount_minor' => 500000],
                ],
            ])->assertCreated();
        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/approve')
            ->assertOk();

        $collect = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/collections', [
                'amount_minor' => 200000,
            ]);
        $this->assertJsonSuccess($collect, 201);
        $eventPublicId = $this->requireStringJsonPath($collect, 'data.public_id');

        $sourceEvent = DB::table('islamic_mourabaha_receivable_events')->where('public_id', $eventPublicId)->first();
        self::assertIsObject($sourceEvent);
        $sourceJournal = JournalEntry::query()->find((int) $sourceEvent->journal_entry_id);
        self::assertInstanceOf(JournalEntry::class, $sourceJournal);

        $reverse = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/reversals', [
                'source_event_public_id' => $eventPublicId,
            ]);
        $this->assertJsonSuccess($reverse, 201);

        $reversalEventId = $this->requireStringJsonPath($reverse, 'data.public_id');
        $reversalEvent = DB::table('islamic_mourabaha_receivable_events')->where('public_id', $reversalEventId)->first();
        self::assertIsObject($reversalEvent);
        $reversalJournal = JournalEntry::query()->find((int) $reversalEvent->journal_entry_id);
        self::assertInstanceOf(JournalEntry::class, $reversalJournal);

        self::assertSame($sourceJournal->id, (int) $reversalJournal->reversal_of_journal_entry_id);
        self::assertSame(JournalEntry::STATUS_REVERSED, (string) JournalEntry::query()->find($sourceJournal->id)?->status);
    }

    public function test_reversal_uses_configured_reversal_operation_code(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $financingPublicId = $this->createDraftFinancing($actor);
        $agencyId = $this->getFinancingAgencyId($financingPublicId);
        $this->seedMurabahaMappings($actor, $agencyId);
        $this->seedMurabahaCollectionMapping($actor, $agencyId, 'murabaha_collection');
        $this->seedMurabahaCollectionMapping($actor, $agencyId, 'murabaha_collection_reversal');
        $this->setupMourabahaOriginationChain($actor, $financingPublicId);

        DB::table('operation_codes')
            ->where('module', 'islamic_finance')
            ->where('code', 'murabaha_collection')
            ->update([
                'metadata' => json_encode([
                    'islamic_profile' => [
                        'reversal_mode' => 'auto_reverse',
                        'reversal_operation_code' => 'murabaha_collection_reversal',
                    ],
                ], JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);

        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/assets', [
                'asset_type' => 'vehicle',
                'description' => 'Asset',
            ])->assertCreated();
        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/installments', [
                'installments' => [
                    ['due_on' => now()->addMonth()->toDateString(), 'amount_minor' => 500000],
                    ['due_on' => now()->addMonths(2)->toDateString(), 'amount_minor' => 500000],
                ],
            ])->assertCreated();
        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/approve')
            ->assertOk();

        $collect = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/collections', [
                'amount_minor' => 200000,
            ]);
        $this->assertJsonSuccess($collect, 201);
        $eventPublicId = $this->requireStringJsonPath($collect, 'data.public_id');

        $reverse = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/reversals', [
                'source_event_public_id' => $eventPublicId,
                'reason' => 'configured reversal path',
            ]);
        $this->assertJsonSuccess($reverse, 201);
        $reverse->assertJsonPath('data.operation_code', 'murabaha_collection_reversal');

        $this->assertDatabaseHas('activity_log', [
            'event' => 'islamic.operation_code.reversal_validated',
            'log_name' => 'security',
        ]);
    }

    public function test_reversal_blocks_when_configured_reversal_operation_code_has_no_approved_mapping(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $financingPublicId = $this->createDraftFinancing($actor);
        $agencyId = $this->getFinancingAgencyId($financingPublicId);
        $this->seedMurabahaMappings($actor, $agencyId);
        $this->seedMurabahaCollectionMapping($actor, $agencyId, 'murabaha_collection');
        $this->setupMourabahaOriginationChain($actor, $financingPublicId);

        DB::table('operation_codes')
            ->where('module', 'islamic_finance')
            ->where('code', 'murabaha_collection')
            ->update([
                'metadata' => json_encode([
                    'islamic_profile' => [
                        'reversal_mode' => 'auto_reverse',
                        'reversal_operation_code' => 'murabaha_collection_reversal_missing',
                    ],
                ], JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);

        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/assets', [
                'asset_type' => 'vehicle',
                'description' => 'Asset',
            ])->assertCreated();
        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/installments', [
                'installments' => [
                    ['due_on' => now()->addMonth()->toDateString(), 'amount_minor' => 500000],
                    ['due_on' => now()->addMonths(2)->toDateString(), 'amount_minor' => 500000],
                ],
            ])->assertCreated();
        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/approve')
            ->assertOk();

        $collect = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/collections', [
                'amount_minor' => 200000,
            ]);
        $this->assertJsonSuccess($collect, 201);
        $eventPublicId = $this->requireStringJsonPath($collect, 'data.public_id');

        $reverse = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/reversals', [
                'source_event_public_id' => $eventPublicId,
                'reason' => 'reversal mapping missing',
            ]);
        $this->assertJsonError($reverse, 422);
        self::assertStringContainsString('Approved Islamic mapping is required', $this->asString($reverse->json('errors.islamic_mourabaha_reversal.0')));
    }

    public function test_rebate_applies_approved_policy_and_adjusts_outstanding(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $financingPublicId = $this->createDraftFinancing($actor);
        $agencyId = $this->getFinancingAgencyId($financingPublicId);
        $this->seedMurabahaMappings($actor, $agencyId);
        $this->seedMurabahaCollectionMapping($actor, $agencyId, 'murabaha_rebate');
        $this->setupMourabahaOriginationChain($actor, $financingPublicId);

        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/assets', [
                'asset_type' => 'vehicle',
                'description' => 'Asset',
            ])->assertCreated();
        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/installments', [
                'installments' => [
                    ['due_on' => now()->addMonth()->toDateString(), 'amount_minor' => 500000],
                    ['due_on' => now()->addMonths(2)->toDateString(), 'amount_minor' => 500000],
                ],
            ])->assertCreated();
        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/approve')
            ->assertOk();

        $policyPublicId = $this->createApprovedTreatmentPolicy($actor, $this->getFinancingAgencyPublicId($financingPublicId));
        $rebate = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/rebates', [
                'policy_public_id' => $policyPublicId,
                'amount_minor' => 120000,
            ]);
        $this->assertJsonSuccess($rebate, 201);
        $rebate->assertJsonPath('data.event_type', 'rebate');

        $ledger = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->getJson('/api/v1/islamic-financings/'.$financingPublicId.'/receivable-ledger');
        $this->assertJsonSuccess($ledger);
        $ledger->assertJsonPath('data.outstanding_minor', 880000);
    }

    public function test_cancellation_applies_approved_policy_and_adjusts_receivable(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $financingPublicId = $this->createDraftFinancing($actor);
        $agencyId = $this->getFinancingAgencyId($financingPublicId);
        $this->seedMurabahaMappings($actor, $agencyId);
        $this->seedMurabahaCollectionMapping($actor, $agencyId, 'murabaha_cancellation');
        $this->setupMourabahaOriginationChain($actor, $financingPublicId);

        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/assets', [
                'asset_type' => 'vehicle',
                'description' => 'Asset',
            ])->assertCreated();
        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/installments', [
                'installments' => [
                    ['due_on' => now()->addMonth()->toDateString(), 'amount_minor' => 500000],
                    ['due_on' => now()->addMonths(2)->toDateString(), 'amount_minor' => 500000],
                ],
            ])->assertCreated();
        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/approve')
            ->assertOk();

        $policyPublicId = $this->createApprovedTreatmentPolicy($actor, $this->getFinancingAgencyPublicId($financingPublicId));
        $cancel = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/cancellations', [
                'policy_public_id' => $policyPublicId,
                'amount_minor' => 50000,
            ]);
        $this->assertJsonSuccess($cancel, 201);
        $cancel->assertJsonPath('data.event_type', 'cancellation');

        $ledger = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->getJson('/api/v1/islamic-financings/'.$financingPublicId.'/receivable-ledger');
        $this->assertJsonSuccess($ledger);
        $ledger->assertJsonPath('data.outstanding_minor', 950000);
    }

    public function test_default_treatment_requires_approved_policy_route(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $financingPublicId = $this->createDraftFinancing($actor);
        $agencyId = $this->getFinancingAgencyId($financingPublicId);
        $this->seedMurabahaMappings($actor, $agencyId);
        $this->setupMourabahaOriginationChain($actor, $financingPublicId);

        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/assets', [
                'asset_type' => 'vehicle',
                'description' => 'Asset',
            ])->assertCreated();
        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/installments', [
                'installments' => [
                    ['due_on' => now()->addMonth()->toDateString(), 'amount_minor' => 500000],
                    ['due_on' => now()->addMonths(2)->toDateString(), 'amount_minor' => 500000],
                ],
            ])->assertCreated();
        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/approve')
            ->assertOk();

        $policy = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-treatment-policies', [
                'policy_code' => 'TP-'.Str::upper(Str::random(6)),
                'version' => 1,
                'scope_type' => 'agency',
                'agency_public_id' => $this->getFinancingAgencyPublicId($financingPublicId),
                'zakat_enabled' => false,
                'charity_treatment_enabled' => false,
                'non_compliant_income_treatment_enabled' => false,
                'required_operation_codes' => [],
                'effective_from' => now()->subDay()->toDateString(),
            ]);
        $this->assertJsonSuccess($policy, 201);
        $draftPolicyPublicId = $this->requireStringJsonPath($policy, 'data.public_id');

        $default = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/default-treatments', [
                'policy_public_id' => $draftPolicyPublicId,
                'amount_minor' => 10000,
            ]);
        $this->assertJsonError($default, 422);
        self::assertStringContainsString('approved treatment policy route', $this->asString($default->json('errors.islamic_mourabaha_default_treatment.0')));
    }

    public function test_default_treatment_applies_approved_policy_and_adjusts_receivable(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $financingPublicId = $this->createDraftFinancing($actor);
        $agencyId = $this->getFinancingAgencyId($financingPublicId);
        $this->seedMurabahaMappings($actor, $agencyId);
        $this->seedMurabahaCollectionMapping($actor, $agencyId, 'murabaha_default_treatment');
        $this->setupMourabahaOriginationChain($actor, $financingPublicId);

        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/assets', [
                'asset_type' => 'vehicle',
                'description' => 'Asset',
            ])->assertCreated();
        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/installments', [
                'installments' => [
                    ['due_on' => now()->addMonth()->toDateString(), 'amount_minor' => 500000],
                    ['due_on' => now()->addMonths(2)->toDateString(), 'amount_minor' => 500000],
                ],
            ])->assertCreated();
        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/approve')
            ->assertOk();

        $policyPublicId = $this->createApprovedTreatmentPolicy($actor, $this->getFinancingAgencyPublicId($financingPublicId));
        $default = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/default-treatments', [
                'policy_public_id' => $policyPublicId,
                'amount_minor' => 40000,
            ]);
        $this->assertJsonSuccess($default, 201);
        $default->assertJsonPath('data.event_type', 'default_treatment');
        $default->assertJsonPath('data.policy_public_id', $policyPublicId);

        $ledger = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->getJson('/api/v1/islamic-financings/'.$financingPublicId.'/receivable-ledger');
        $this->assertJsonSuccess($ledger);
        $ledger->assertJsonPath('data.outstanding_minor', 960000);
    }

    public function test_correction_requires_source_event_link_and_is_auditable(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $financingPublicId = $this->createDraftFinancing($actor);
        $agencyId = $this->getFinancingAgencyId($financingPublicId);
        $this->seedMurabahaMappings($actor, $agencyId);
        $this->seedMurabahaCollectionMapping($actor, $agencyId, 'murabaha_collection');
        $this->seedMurabahaCollectionMapping($actor, $agencyId, 'murabaha_correction');
        $this->setupMourabahaOriginationChain($actor, $financingPublicId);

        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/assets', [
                'asset_type' => 'vehicle',
                'description' => 'Asset',
            ])->assertCreated();
        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/installments', [
                'installments' => [
                    ['due_on' => now()->addMonth()->toDateString(), 'amount_minor' => 500000],
                    ['due_on' => now()->addMonths(2)->toDateString(), 'amount_minor' => 500000],
                ],
            ])->assertCreated();
        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/approve')
            ->assertOk();

        $policyPublicId = $this->createApprovedTreatmentPolicy($actor, $this->getFinancingAgencyPublicId($financingPublicId));
        $collect = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/collections', [
                'amount_minor' => 100000,
            ]);
        $this->assertJsonSuccess($collect, 201);
        $sourceEventPublicId = $this->requireStringJsonPath($collect, 'data.public_id');

        $missingSource = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/corrections', [
                'policy_public_id' => $policyPublicId,
                'amount_minor' => 50000,
            ]);
        $this->assertJsonError($missingSource, 422);
        self::assertStringContainsString('requires source event reference', $this->asString($missingSource->json('errors.islamic_mourabaha_correction.0')));

        $correction = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/corrections', [
                'policy_public_id' => $policyPublicId,
                'amount_minor' => 50000,
                'source_event_public_id' => $sourceEventPublicId,
            ]);
        $this->assertJsonSuccess($correction, 201);
        $correction->assertJsonPath('data.event_type', 'correction');
        $correction->assertJsonPath('data.source_event_public_id', $sourceEventPublicId);
        $correction->assertJsonPath('data.allocations.0.allocated_minor', 50000);

        $ledger = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->getJson('/api/v1/islamic-financings/'.$financingPublicId.'/receivable-ledger');
        $this->assertJsonSuccess($ledger);
        $ledger->assertJsonPath('data.outstanding_minor', 850000);

        $sourceEventId = DB::table('islamic_mourabaha_receivable_events')->where('public_id', $sourceEventPublicId)->value('id');
        self::assertIsNumeric($sourceEventId);
        $this->assertDatabaseHas('islamic_mourabaha_receivable_events', [
            'event_type' => 'correction',
            'source_event_id' => (int) $sourceEventId,
        ]);
        $this->assertDatabaseHas('activity_log', [
            'event' => 'islamic.mourabaha.correction.applied',
            'log_name' => 'security',
        ]);
    }

    public function test_duplicate_installments_rejected(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $financingPublicId = $this->createDraftFinancing($actor);

        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/installments', [
                'installments' => [
                    ['due_on' => '2026-06-01', 'amount_minor' => 500000],
                    ['due_on' => '2026-07-01', 'amount_minor' => 500000],
                ],
            ]);

        $duplicate = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/installments', [
                'installments' => [
                    ['due_on' => '2026-06-01', 'amount_minor' => 500000],
                ],
            ]);
        $this->assertJsonError($duplicate, 422);
    }

    public function test_supported_islamic_product_families_can_be_created_as_drafts(): void
    {
        $actor = $this->createUserWithRole('platform-admin');

        foreach ([
            'ijara',
            'ijara_wa_iqtina',
            'salam',
            'istisnaa',
            'moudaraba',
            'moucharaka',
            'islamic_current_account',
            'islamic_savings_account',
            'islamic_investment_account',
        ] as $contractType) {
            $productPublicId = $this->createProduct($actor, Str::upper($contractType).'-'.Str::ulid(), $contractType);

            $this->assertDatabaseHas('islamic_products', [
                'public_id' => $productPublicId,
                'contract_type' => $contractType,
                'status' => 'draft',
            ]);
        }
    }

    public function test_unknown_product_family_is_rejected_on_product_creation(): void
    {
        $actor = $this->createUserWithRole('platform-admin');

        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-products', [
                'code' => 'UNKNOWN-'.Str::ulid(),
                'name' => 'Unknown Product',
                'contract_type' => 'generic_islamic',
            ]);

        $this->assertJsonError($response, 422);
        self::assertNotEmpty($response->json('errors.contract_type'));
    }

    public function test_product_family_metadata_is_exposed_via_catalog_api(): void
    {
        $actor = $this->createUserWithRole('platform-admin');

        $list = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->getJson('/api/v1/islamic-product-families');
        $this->assertJsonSuccess($list);

        $listData = $list->json('data');
        self::assertIsArray($listData);
        $codes = collect($listData)->pluck('code')->all();
        foreach ([
            'mourabaha',
            'ijara',
            'ijara_wa_iqtina',
            'salam',
            'istisnaa',
            'moudaraba',
            'moucharaka',
            'islamic_current_account',
            'islamic_savings_account',
            'islamic_investment_account',
        ] as $expectedCode) {
            self::assertContains($expectedCode, $codes);
        }

        $show = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->getJson('/api/v1/islamic-product-families/murabaha');
        $this->assertJsonSuccess($show);
        $show->assertJsonPath('data.code', 'mourabaha');
        $show->assertJsonPath('data.family_kind', 'financing');
        self::assertNotEmpty($show->json('data.required_fields_schema.required'));
        self::assertNotEmpty($show->json('data.workflow_states'));
        self::assertNotEmpty($show->json('data.evidence_rules'));
        self::assertNotEmpty($show->json('data.accounting_events'));
        self::assertNotEmpty($show->json('data.screening_rules'));
        self::assertNotEmpty($show->json('data.readiness_checklist_template'));
    }

    public function test_required_fields_differ_by_family_metadata(): void
    {
        $actor = $this->createUserWithRole('platform-admin');

        $ijara = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->getJson('/api/v1/islamic-product-families/ijara');
        $salam = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->getJson('/api/v1/islamic-product-families/salam');
        $this->assertJsonSuccess($ijara);
        $this->assertJsonSuccess($salam);

        $ijaraRequiredFields = $ijara->json('data.required_fields_schema.required');
        $salamRequiredFields = $salam->json('data.required_fields_schema.required');
        self::assertIsArray($ijaraRequiredFields);
        self::assertIsArray($salamRequiredFields);
        self::assertContains('maintenance_policy', $ijaraRequiredFields);
        self::assertNotContains('maintenance_policy', $salamRequiredFields);
        self::assertContains('allowed_goods_policy', $salamRequiredFields);
    }

    public function test_financing_creation_rejects_account_family_kind(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('IF-'.Str::upper(Str::random(4)));
        $clientPublicId = $this->createClient($agency['id']);
        $this->ensureMourabahaBaseline($actor);
        $this->ensureMourabahaSignoff($actor);
        $accountProductPublicId = $this->createProduct($actor, 'ACCT-'.Str::ulid(), 'islamic_current_account', $agency['public_id']);

        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings', [
                'client_public_id' => $clientPublicId,
                'agency_public_id' => $agency['public_id'],
                'product_public_id' => $accountProductPublicId,
                'contract_type' => 'islamic_current_account',
                'purchase_cost_minor' => 800000,
                'markup_minor' => 200000,
            ]);

        $this->assertJsonError($response, 422);
        self::assertNotEmpty($response->json('errors.islamic_financing'));
    }

    public function test_family_registry_prevents_product_family_account_type_drift_in_links(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('IF-LINK-DRIFT');

        $standardDocumentPublicId = (string) Str::ulid();
        DB::table('documents')->insert([
            'public_id' => $standardDocumentPublicId,
            'agency_id' => $agency['id'],
            'uploaded_by_user_id' => $actor->id,
            'category' => 'islamic_standard',
            'title' => 'Link drift standard evidence',
            'disk' => 'local',
            'path' => 'documents/'.$standardDocumentPublicId,
            'original_name' => 'standard.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'checksum_sha256' => str_repeat('f', 64),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $standard = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-standards', [
                'source' => 'AAOIFI',
                'reference' => 'AAOIFI-DRIFT-'.Str::random(4),
                'title' => 'Link drift standard',
                'scope_summary' => 'Validates family/account link taxonomy.',
                'owner_type' => 'committee',
                'owner_committee' => 'Sharia Board',
                'effective_date' => CarbonImmutable::now()->subDay()->toDateString(),
                'document_public_id' => $standardDocumentPublicId,
            ]);
        $this->assertJsonSuccess($standard, 201);
        $standardPublicId = $this->requireStringJsonPath($standard, 'data.public_id');

        $invalidStandardLink = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-standards/'.$standardPublicId.'/links', [
                'linkable_type' => 'product_family',
                'linkable_code' => 'islamic_current_account',
            ]);
        $this->assertJsonError($invalidStandardLink, 422);
        self::assertStringContainsString('Unknown product family code', $this->asString($invalidStandardLink->json('errors.islamic_standard_link.0')));

        $validStandardAccountLink = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-standards/'.$standardPublicId.'/links', [
                'linkable_type' => 'account_type',
                'linkable_code' => 'islamic_current_account',
            ]);
        $this->assertJsonSuccess($validStandardAccountLink, 201);

        $signoffDocumentPublicId = (string) Str::ulid();
        DB::table('documents')->insert([
            'public_id' => $signoffDocumentPublicId,
            'agency_id' => $agency['id'],
            'uploaded_by_user_id' => $actor->id,
            'category' => 'regulatory_signoff',
            'title' => 'Link drift signoff evidence',
            'disk' => 'local',
            'path' => 'documents/'.$signoffDocumentPublicId,
            'original_name' => 'signoff.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'checksum_sha256' => str_repeat('d', 64),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $signoff = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-regulatory-signoffs', [
                'jurisdiction' => 'cameroon',
                'regulator' => 'cobac',
                'opinion_reference' => 'COBAC-DRIFT-'.Str::random(4),
                'opinion_summary' => 'Validates family/account link taxonomy.',
                'approval_type' => 'allow',
                'owner_type' => 'committee',
                'owner_committee' => 'Compliance Board',
                'approved_on' => CarbonImmutable::now()->subDays(2)->toDateString(),
                'effective_date' => CarbonImmutable::now()->subDay()->toDateString(),
                'document_public_id' => $signoffDocumentPublicId,
            ]);
        $this->assertJsonSuccess($signoff, 201);
        $signoffPublicId = $this->requireStringJsonPath($signoff, 'data.public_id');

        $invalidSignoffLink = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-regulatory-signoffs/'.$signoffPublicId.'/links', [
                'linkable_type' => 'product_family',
                'linkable_code' => 'islamic_current_account',
                'restriction_mode' => 'allow',
            ]);
        $this->assertJsonError($invalidSignoffLink, 422);
        self::assertStringContainsString('Unknown product family code', $this->asString($invalidSignoffLink->json('errors.islamic_regulatory_signoff_link.0')));

        $validSignoffAccountLink = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-regulatory-signoffs/'.$signoffPublicId.'/links', [
                'linkable_type' => 'account_type',
                'linkable_code' => 'islamic_current_account',
                'restriction_mode' => 'allow',
            ]);
        $this->assertJsonSuccess($validSignoffAccountLink, 201);
    }

    public function test_draft_template_cannot_originate_contract(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('IF-TPL-DRAFT');
        $clientPublicId = $this->createClient($agency['id']);
        $this->ensureMourabahaBaseline($actor);
        $productPublicId = $this->createApprovedProduct($actor, 'MUR-TPL-DRAFT-'.Str::ulid(), 'murabaha', $agency['public_id']);

        $documentPublicId = (string) Str::ulid();
        DB::table('documents')->insert([
            'public_id' => $documentPublicId,
            'agency_id' => $agency['id'],
            'uploaded_by_user_id' => $actor->id,
            'category' => 'contract_template',
            'title' => 'Draft template',
            'disk' => 'local',
            'path' => 'documents/'.$documentPublicId,
            'original_name' => 'template.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 2048,
            'checksum_sha256' => str_repeat('e', 64),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $draftTemplate = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-contract-templates', [
                'family_code' => 'mourabaha',
                'language_code' => 'fr',
                'template_code' => 'mourabaha_contract_template_draft',
                'version' => 1,
                'effective_from' => now()->subDay()->toDateString(),
                'document_public_id' => $documentPublicId,
                'legal_signoff_ref' => 'LEGAL-DRAFT',
                'sharia_signoff_ref' => 'SHARIA-DRAFT',
            ]);
        $this->assertJsonSuccess($draftTemplate, 201);
        $templatePublicId = $this->requireStringJsonPath($draftTemplate, 'data.public_id');

        $create = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings', [
                'client_public_id' => $clientPublicId,
                'agency_public_id' => $agency['public_id'],
                'product_public_id' => $productPublicId,
                'contract_type' => 'murabaha',
                'contract_template_public_id' => $templatePublicId,
                'purchase_cost_minor' => 800000,
                'markup_minor' => 200000,
            ]);

        $this->assertJsonError($create, 422);
        self::assertNotEmpty($create->json('errors.islamic_financing'));
    }

    public function test_expired_template_cannot_originate_new_contract(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('IF-TPL-EXP');
        $clientPublicId = $this->createClient($agency['id']);
        $this->ensureMourabahaBaseline($actor);
        $productPublicId = $this->createApprovedProduct($actor, 'MUR-TPL-EXP-'.Str::ulid(), 'murabaha', $agency['public_id']);

        $templatePublicId = $this->createApprovedTemplate($actor, 'mourabaha', 'mourabaha_contract_template_expired', now()->subDays(3)->toDateString(), now()->subDay()->toDateString(), $agency['id']);

        $create = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings', [
                'client_public_id' => $clientPublicId,
                'agency_public_id' => $agency['public_id'],
                'product_public_id' => $productPublicId,
                'contract_type' => 'murabaha',
                'contract_template_public_id' => $templatePublicId,
                'purchase_cost_minor' => 800000,
                'markup_minor' => 200000,
            ]);

        $this->assertJsonError($create, 422);
        self::assertNotEmpty($create->json('errors.islamic_financing'));
    }

    public function test_template_language_fallback_policy_is_enforced(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('IF-TPL-LANG');
        $clientPublicId = $this->createClient($agency['id']);
        $this->ensureMourabahaBaseline($actor);
        $productPublicId = $this->createApprovedProduct($actor, 'MUR-TPL-LANG-'.Str::ulid(), 'murabaha', $agency['public_id']);

        $blocked = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings', [
                'client_public_id' => $clientPublicId,
                'agency_public_id' => $agency['public_id'],
                'product_public_id' => $productPublicId,
                'contract_type' => 'murabaha',
                'template_language_code' => 'ar',
                'purchase_cost_minor' => 800000,
                'markup_minor' => 200000,
            ]);
        $this->assertJsonError($blocked, 422);
        self::assertStringContainsString('allow_template_language_fallback', $this->asString($blocked->json('errors.islamic_financing.0')));

        $allowed = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings', [
                'client_public_id' => $clientPublicId,
                'agency_public_id' => $agency['public_id'],
                'product_public_id' => $productPublicId,
                'contract_type' => 'murabaha',
                'template_language_code' => 'ar',
                'allow_template_language_fallback' => true,
                'purchase_cost_minor' => 810000,
                'markup_minor' => 190000,
            ]);
        $this->assertJsonSuccess($allowed, 201);
        $this->assertDatabaseHas('activity_log', [
            'event' => 'islamic.contract_template.language_fallback_used',
            'log_name' => 'security',
        ]);
    }

    public function test_template_resolution_rejects_conflicting_candidates(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('IF-TPL-CONFLICT');
        $clientPublicId = $this->createClient($agency['id']);
        $this->ensureMourabahaBaseline($actor);
        $productPublicId = $this->createApprovedProduct($actor, 'MUR-TPL-CONFLICT-'.Str::ulid(), 'murabaha', $agency['public_id']);

        $this->createApprovedTemplate(
            actor: $actor,
            familyCode: 'mourabaha',
            templateCode: 'mourabaha_contract_template_conflict_a',
            effectiveFrom: now()->subDay()->toDateString(),
            effectiveTo: null,
            agencyId: $agency['id'],
            version: 2,
        );
        $this->createApprovedTemplate(
            actor: $actor,
            familyCode: 'mourabaha',
            templateCode: 'mourabaha_contract_template_conflict_b',
            effectiveFrom: now()->subDay()->toDateString(),
            effectiveTo: null,
            agencyId: $agency['id'],
            version: 2,
        );

        $blocked = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings', [
                'client_public_id' => $clientPublicId,
                'agency_public_id' => $agency['public_id'],
                'product_public_id' => $productPublicId,
                'contract_type' => 'murabaha',
                'template_language_code' => 'fr',
                'purchase_cost_minor' => 800000,
                'markup_minor' => 200000,
            ]);
        $this->assertJsonError($blocked, 422);
        self::assertStringContainsString('Multiple approved/effective Islamic contract templates are eligible', $this->asString($blocked->json('errors.islamic_financing.0')));
    }

    public function test_existing_contract_keeps_old_template_snapshot_after_retirement(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('IF-TPL-HISTORY');
        $clientPublicId = $this->createClient($agency['id']);
        $this->ensureMourabahaBaseline($actor);
        $productPublicId = $this->createApprovedProduct($actor, 'MUR-TPL-HISTORY-'.Str::ulid(), 'murabaha', $agency['public_id']);

        $templatePublicId = $this->createApprovedTemplate(
            actor: $actor,
            familyCode: 'mourabaha',
            templateCode: 'mourabaha_contract_template_history',
            effectiveFrom: now()->subDay()->toDateString(),
            effectiveTo: null,
            agencyId: $agency['id'],
            version: 3,
        );

        $firstFinancing = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings', [
                'client_public_id' => $clientPublicId,
                'agency_public_id' => $agency['public_id'],
                'product_public_id' => $productPublicId,
                'contract_type' => 'murabaha',
                'contract_template_public_id' => $templatePublicId,
                'purchase_cost_minor' => 800000,
                'markup_minor' => 200000,
            ]);
        $this->assertJsonSuccess($firstFinancing, 201);
        $financingPublicId = $this->requireStringJsonPath($firstFinancing, 'data.public_id');

        $snapshot = DB::table('islamic_contract_template_snapshots')
            ->where('contract_subject_type', 'islamic_financing')
            ->where('contract_subject_public_id', $financingPublicId)
            ->first();
        self::assertIsObject($snapshot);
        self::assertSame($templatePublicId, $snapshot->template_public_id ?? null);

        $retire = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-contract-templates/'.$templatePublicId.'/retire');
        $this->assertJsonSuccess($retire);

        $snapshotAfterRetire = DB::table('islamic_contract_template_snapshots')
            ->where('contract_subject_type', 'islamic_financing')
            ->where('contract_subject_public_id', $financingPublicId)
            ->first();
        self::assertIsObject($snapshotAfterRetire);
        self::assertSame($templatePublicId, $snapshotAfterRetire->template_public_id ?? null);

        $blocked = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings', [
                'client_public_id' => $clientPublicId,
                'agency_public_id' => $agency['public_id'],
                'product_public_id' => $productPublicId,
                'contract_type' => 'murabaha',
                'contract_template_public_id' => $templatePublicId,
                'purchase_cost_minor' => 800500,
                'markup_minor' => 199500,
            ]);
        $this->assertJsonError($blocked, 422);
        $this->assertDatabaseHas('activity_log', [
            'event' => 'islamic.contract_template.use_blocked',
            'log_name' => 'security',
        ]);
    }

    public function test_origination_persists_template_snapshot(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $financingPublicId = $this->createDraftFinancing($actor);

        $snapshot = DB::table('islamic_contract_template_snapshots')
            ->where('contract_subject_type', 'islamic_financing')
            ->where('contract_subject_public_id', $financingPublicId)
            ->first();
        self::assertIsObject($snapshot);
        self::assertNotEmpty($snapshot->template_public_id ?? null);
        self::assertNotEmpty($snapshot->snapshot_hash ?? null);
    }

    public function test_missing_contract_template_blocks_product_activation(): void
    {
        $maker = $this->createUserWithRole('platform-admin');
        $checker = $this->createUserWithRole('platform-admin');
        $this->ensureShariaApprover($checker);
        $this->seedMourabahaBaselineWithoutTemplate($maker, withAccountingMapping: true);
        $this->ensureMourabahaSignoff($maker);

        $productPublicId = $this->createProduct($maker, 'MUR-NO-TPL-'.Str::ulid(), 'murabaha');
        $reviewPublicId = $this->requestProductReview($maker, $productPublicId);

        $approve = $this->withApiHeaders()
            ->actingAsSanctum($checker)
            ->postJson('/api/v1/islamic-compliance-reviews/'.$reviewPublicId.'/review', [
                'decision' => 'approve',
            ]);

        $this->assertJsonError($approve, 422);
        self::assertNotEmpty($approve->json('errors.islamic_contract_template'));
        $this->assertDatabaseHas('activity_log', [
            'event' => 'islamic.product.readiness.blocked',
            'log_name' => 'security',
        ]);
    }

    public function test_missing_accounting_mapping_blocks_product_activation(): void
    {
        $maker = $this->createUserWithRole('platform-admin');
        $checker = $this->createUserWithRole('platform-admin');
        $this->ensureShariaApprover($checker);
        $this->seedMourabahaBaselineWithoutTemplate($maker, withAccountingMapping: false, withContractTemplate: true);
        $this->ensureMourabahaSignoff($maker);

        $productPublicId = $this->createProduct($maker, 'MUR-NO-MAP-'.Str::ulid(), 'murabaha');
        $reviewPublicId = $this->requestProductReview($maker, $productPublicId);

        $approve = $this->withApiHeaders()
            ->actingAsSanctum($checker)
            ->postJson('/api/v1/islamic-compliance-reviews/'.$reviewPublicId.'/review', [
                'decision' => 'approve',
            ]);

        $this->assertJsonError($approve, 422);
        self::assertNotEmpty($approve->json('errors.islamic_accounting_mappings'));
    }

    public function test_missing_document_requirements_blocks_product_activation(): void
    {
        $maker = $this->createUserWithRole('platform-admin');
        $checker = $this->createUserWithRole('platform-admin');
        $this->ensureShariaApprover($checker);
        $this->seedMourabahaBaselineWithoutTemplate($maker, withAccountingMapping: true, withContractTemplate: true);
        $this->ensureMourabahaSignoff($maker);

        $product = $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/islamic-products', [
                'code' => 'MUR-NO-DOC-'.Str::ulid(),
                'name' => 'Murabaha Missing Doc Req',
                'contract_type' => 'murabaha',
                'rules' => array_merge($this->defaultGovernanceRulesFor('murabaha'), [
                    'authorization_rules' => ['maker_checker' => true],
                    'operational_procedure' => ['reference' => 'if-op-v1', 'version' => '2026.01'],
                    'reporting_category' => 'mourabaha_receivables',
                    'document_requirements' => [],
                ]),
            ]);
        $this->assertJsonSuccess($product, 201);
        $productPublicId = $this->requireStringJsonPath($product, 'data.public_id');

        $reviewPublicId = $this->requestProductReview($maker, $productPublicId);
        $approve = $this->withApiHeaders()
            ->actingAsSanctum($checker)
            ->postJson('/api/v1/islamic-compliance-reviews/'.$reviewPublicId.'/review', [
                'decision' => 'approve',
            ]);

        $this->assertJsonError($approve, 422);
        self::assertNotEmpty($approve->json('errors.islamic_document_requirements'));
    }

    public function test_missing_operational_procedure_blocks_product_activation(): void
    {
        $maker = $this->createUserWithRole('platform-admin');
        $checker = $this->createUserWithRole('platform-admin');
        $this->ensureShariaApprover($checker);
        $this->seedMourabahaBaselineWithoutTemplate($maker, withAccountingMapping: true, withContractTemplate: true);
        $this->ensureMourabahaSignoff($maker);

        $product = $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/islamic-products', [
                'code' => 'MUR-NO-OPS-'.Str::ulid(),
                'name' => 'Murabaha Missing Ops Procedure',
                'contract_type' => 'murabaha',
                'rules' => array_merge($this->defaultGovernanceRulesFor('murabaha'), [
                    'document_requirements' => ['evidence_pack' => 'baseline'],
                    'authorization_rules' => ['maker_checker' => true],
                    'reporting_category' => 'mourabaha_receivables',
                    'operational_procedure' => [],
                ]),
            ]);
        $this->assertJsonSuccess($product, 201);
        $productPublicId = $this->requireStringJsonPath($product, 'data.public_id');

        $reviewPublicId = $this->requestProductReview($maker, $productPublicId);
        $approve = $this->withApiHeaders()
            ->actingAsSanctum($checker)
            ->postJson('/api/v1/islamic-compliance-reviews/'.$reviewPublicId.'/review', [
                'decision' => 'approve',
            ]);

        $this->assertJsonError($approve, 422);
        self::assertNotEmpty($approve->json('errors.islamic_operational_procedure'));
    }

    public function test_missing_authorization_rules_blocks_product_activation(): void
    {
        $maker = $this->createUserWithRole('platform-admin');
        $checker = $this->createUserWithRole('platform-admin');
        $this->ensureShariaApprover($checker);
        $this->seedMourabahaBaselineWithoutTemplate($maker, withAccountingMapping: true, withContractTemplate: true);
        $this->ensureMourabahaSignoff($maker);

        $product = $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/islamic-products', [
                'code' => 'MUR-NO-AUTH-'.Str::ulid(),
                'name' => 'Murabaha Missing Authorization Rules',
                'contract_type' => 'murabaha',
                'rules' => array_merge($this->defaultGovernanceRulesFor('murabaha'), [
                    'document_requirements' => ['evidence_pack' => 'baseline'],
                    'operational_procedure' => ['reference' => 'if-op-v1', 'version' => '2026.01'],
                    'reporting_category' => 'mourabaha_receivables',
                    'authorization_rules' => [],
                ]),
            ]);
        $this->assertJsonSuccess($product, 201);
        $productPublicId = $this->requireStringJsonPath($product, 'data.public_id');

        $reviewPublicId = $this->requestProductReview($maker, $productPublicId);
        $approve = $this->withApiHeaders()
            ->actingAsSanctum($checker)
            ->postJson('/api/v1/islamic-compliance-reviews/'.$reviewPublicId.'/review', [
                'decision' => 'approve',
            ]);

        $this->assertJsonError($approve, 422);
        self::assertNotEmpty($approve->json('errors.islamic_authorization_rules'));
    }

    public function test_reporting_category_mismatch_blocks_product_activation(): void
    {
        $maker = $this->createUserWithRole('platform-admin');
        $checker = $this->createUserWithRole('platform-admin');
        $this->ensureShariaApprover($checker);
        $this->seedMourabahaBaselineWithoutTemplate($maker, withAccountingMapping: true, withContractTemplate: true);
        $this->ensureMourabahaSignoff($maker);

        $product = $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/islamic-products', [
                'code' => 'MUR-BAD-RPT-'.Str::ulid(),
                'name' => 'Murabaha Bad Reporting Category',
                'contract_type' => 'murabaha',
                'rules' => array_merge($this->defaultGovernanceRulesFor('murabaha'), [
                    'document_requirements' => ['evidence_pack' => 'baseline'],
                    'authorization_rules' => ['maker_checker' => true],
                    'operational_procedure' => ['reference' => 'if-op-v1', 'version' => '2026.01'],
                    'reporting_category' => 'ijara_rentals',
                ]),
            ]);
        $this->assertJsonSuccess($product, 201);
        $productPublicId = $this->requireStringJsonPath($product, 'data.public_id');

        $reviewPublicId = $this->requestProductReview($maker, $productPublicId);
        $approve = $this->withApiHeaders()
            ->actingAsSanctum($checker)
            ->postJson('/api/v1/islamic-compliance-reviews/'.$reviewPublicId.'/review', [
                'decision' => 'approve',
            ]);

        $this->assertJsonError($approve, 422);
        self::assertNotEmpty($approve->json('errors.islamic_report_category'));
    }

    public function test_ijara_missing_maintenance_policy_blocks_activation(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $checker = $this->createUserWithRole('platform-admin');
        $this->ensureProductFamilyBaseline($actor, 'ijara');
        $this->ensureShariaApprover($checker);
        $productPublicId = $this->createProduct($actor, 'IJARA-'.Str::ulid(), 'ijara', null, [
            'maintenance_policy' => null,
        ]);
        $reviewPublicId = $this->requestProductReview($actor, $productPublicId);

        $approve = $this->withApiHeaders()
            ->actingAsSanctum($checker)
            ->postJson('/api/v1/islamic-compliance-reviews/'.$reviewPublicId.'/review', [
                'decision' => 'approve',
            ]);

        $this->assertJsonError($approve, 422);
        self::assertNotEmpty($approve->json('errors.islamic_product_maintenance_policy'));
    }

    public function test_ijara_wa_iqtina_missing_residual_policy_blocks_activation(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $checker = $this->createUserWithRole('platform-admin');
        $this->ensureProductFamilyBaseline($actor, 'ijara_wa_iqtina');
        $this->ensureShariaApprover($checker);
        $productPublicId = $this->createProduct($actor, 'IJARAWI-'.Str::ulid(), 'ijara_wa_iqtina', null, [
            'maintenance_policy' => ['institution_responsibility' => true],
            'residual_value_policy' => null,
        ]);
        $reviewPublicId = $this->requestProductReview($actor, $productPublicId);

        $approve = $this->withApiHeaders()
            ->actingAsSanctum($checker)
            ->postJson('/api/v1/islamic-compliance-reviews/'.$reviewPublicId.'/review', [
                'decision' => 'approve',
            ]);

        $this->assertJsonError($approve, 422);
        self::assertNotEmpty($approve->json('errors.islamic_product_residual_value_policy'));
    }

    public function test_if070_transfer_option_unavailable_on_ordinary_ijara_without_configuration(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $create = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-products', [
                'code' => 'IJ-TRF-BLOCK-'.Str::ulid(),
                'name' => 'Ordinary Ijara transfer option blocked',
                'contract_type' => 'ijara',
                'rules' => array_merge($this->defaultGovernanceRulesFor('ijara'), [
                    'transfer_option' => true,
                ]),
            ]);
        $this->assertJsonError($create, 422);
        self::assertStringContainsString('cannot enable transfer_option', strtolower($this->asString($create->json('errors.islamic_interest_guardrails.0'))));
    }

    public function test_if070_ijara_payload_exposes_variant_and_transfer_flags(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $create = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-products', [
                'code' => 'IJ-PAYLOAD-'.Str::ulid(),
                'name' => 'Ijara variant payload',
                'contract_type' => 'ijara_wa_iqtina',
                'rules' => $this->defaultGovernanceRulesFor('ijara_wa_iqtina'),
            ]);
        $this->assertJsonSuccess($create, 201);
        $create->assertJsonPath('data.variant_classification', 'ijara_wa_iqtina');
        $create->assertJsonPath('data.transfer_capability.enabled', true);
        $create->assertJsonPath('data.transfer_capability.requires_transfer_workflow', true);
    }

    public function test_if070_wrong_template_variant_binding_blocks_activation(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $checker = $this->createUserWithRole('platform-admin');
        $this->ensureProductFamilyBaseline($actor, 'ijara_wa_iqtina');
        $this->ensureShariaApprover($checker);

        $productPublicId = $this->createProduct($actor, 'IJ-TP-BAD-'.Str::ulid(), 'ijara_wa_iqtina', null, [
            'contract_template_reference' => ['template_code' => 'ijara_contract_template'],
        ]);
        $reviewPublicId = $this->requestProductReview($actor, $productPublicId);
        $approve = $this->withApiHeaders()
            ->actingAsSanctum($checker)
            ->postJson('/api/v1/islamic-compliance-reviews/'.$reviewPublicId.'/review', [
                'decision' => 'approve',
            ]);
        $this->assertJsonError($approve, 422);
        self::assertNotEmpty($approve->json('errors.islamic_product_contract_template_reference'));
    }

    public function test_salam_missing_goods_policy_and_cash_only_request_are_rejected(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $checker = $this->createUserWithRole('platform-admin');
        $this->ensureProductFamilyBaseline($actor, 'salam');
        $this->ensureShariaApprover($checker);
        $productPublicId = $this->createProduct($actor, 'SALAM-'.Str::ulid(), 'salam', null, [
            'allowed_goods_policy' => null,
            'cash_only' => true,
            'upfront_payment_mapping' => ['profile' => 'salam_upfront'],
        ]);
        $reviewPublicId = $this->requestProductReview($actor, $productPublicId);

        $approve = $this->withApiHeaders()
            ->actingAsSanctum($checker)
            ->postJson('/api/v1/islamic-compliance-reviews/'.$reviewPublicId.'/review', [
                'decision' => 'approve',
            ]);

        $this->assertJsonError($approve, 422);
        self::assertNotEmpty($approve->json('errors.islamic_product_allowed_goods_policy'));
        self::assertNotEmpty($approve->json('errors.islamic_product_cash_only'));
    }

    public function test_if080_salam_rule_schema_rejects_unknown_or_unsafe_keys(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $unknownKey = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-products', [
                'code' => 'SAL-SCHEMA-UNK-'.Str::ulid(),
                'name' => 'Salam schema unknown key',
                'contract_type' => 'salam',
                'rules' => array_merge($this->defaultGovernanceRulesFor('salam'), [
                    'unexpected_policy' => ['enabled' => true],
                ]),
            ]);
        $this->assertJsonError($unknownKey, 422);
        self::assertStringContainsString('unknown salam rule key', strtolower($this->asString($unknownKey->json('errors.islamic_interest_guardrails.0'))));

        $wildcardGoods = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-products', [
                'code' => 'SAL-SCHEMA-WILDCARD-'.Str::ulid(),
                'name' => 'Salam wildcard goods',
                'contract_type' => 'salam',
                'rules' => array_merge($this->defaultGovernanceRulesFor('salam'), [
                    'allowed_goods_policy' => ['categories' => ['*']],
                ]),
            ]);
        $this->assertJsonError($wildcardGoods, 422);
        self::assertStringContainsString('wildcard', strtolower($this->asString($wildcardGoods->json('errors.islamic_interest_guardrails.0'))));
    }

    public function test_if080_missing_upfront_payment_mapping_blocks_activation(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $checker = $this->createUserWithRole('platform-admin');
        $this->ensureProductFamilyBaseline($actor, 'salam');
        $this->ensureShariaApprover($checker);
        $productPublicId = $this->createProduct($actor, 'SAL-NO-UPFRONT-'.Str::ulid(), 'salam', null, [
            'upfront_payment_mapping' => null,
        ]);
        $reviewPublicId = $this->requestProductReview($actor, $productPublicId);

        $approve = $this->withApiHeaders()
            ->actingAsSanctum($checker)
            ->postJson('/api/v1/islamic-compliance-reviews/'.$reviewPublicId.'/review', [
                'decision' => 'approve',
            ]);

        $this->assertJsonError($approve, 422);
        self::assertNotEmpty($approve->json('errors.islamic_product_upfront_payment_mapping'));
    }

    public function test_if080_salam_payload_exposes_policy_bindings_and_mapping_profile(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $create = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-products', [
                'code' => 'SAL-PAYLOAD-'.Str::ulid(),
                'name' => 'Salam payload',
                'contract_type' => 'salam',
                'rules' => $this->defaultGovernanceRulesFor('salam'),
            ]);
        $this->assertJsonSuccess($create, 201);
        $create->assertJsonPath('data.rules.upfront_payment_mapping.operation_code', 'salam_upfront_payment');
        $create->assertJsonPath('data.rules.accounting_mapping_profile.profile_code', 'salam_profile_v1');
        $create->assertJsonPath('data.rules.contract_template_reference.template_code', 'salam_contract_template');
    }

    public function test_istisnaa_missing_milestone_variation_and_project_mapping_blocks_activation(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $checker = $this->createUserWithRole('platform-admin');
        $this->ensureProductFamilyBaseline($actor, 'istisnaa');
        $this->ensureShariaApprover($checker);
        $productPublicId = $this->createProduct($actor, 'IST-'.Str::ulid(), 'istisnaa', null, [
            'milestone_rules' => null,
            'variation_rules' => null,
            'payment_rules' => null,
            'project_accounting_mapping_profile' => null,
        ]);
        $reviewPublicId = $this->requestProductReview($actor, $productPublicId);

        $approve = $this->withApiHeaders()
            ->actingAsSanctum($checker)
            ->postJson('/api/v1/islamic-compliance-reviews/'.$reviewPublicId.'/review', [
                'decision' => 'approve',
            ]);

        $this->assertJsonError($approve, 422);
        self::assertNotEmpty($approve->json('errors.islamic_product_milestone_rules'));
        self::assertNotEmpty($approve->json('errors.islamic_product_variation_rules'));
        self::assertNotEmpty($approve->json('errors.islamic_product_project_accounting_mapping_profile'));
    }

    public function test_if090_istisnaa_rule_schema_rejects_unknown_keys_and_staged_payment_without_milestones(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $unknownKey = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-products', [
                'code' => 'IST-SCHEMA-UNK-'.Str::ulid(),
                'name' => 'Istisnaa schema unknown key',
                'contract_type' => 'istisnaa',
                'rules' => array_merge($this->defaultGovernanceRulesFor('istisnaa'), [
                    'unexpected_policy' => ['enabled' => true],
                ]),
            ]);
        $this->assertJsonError($unknownKey, 422);
        self::assertStringContainsString("unknown istisna'a rule key", strtolower($this->asString($unknownKey->json('errors.islamic_interest_guardrails.0'))));

        $stagedWithoutMilestones = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-products', [
                'code' => 'IST-SCHEMA-STAGED-'.Str::ulid(),
                'name' => 'Istisnaa staged payment without milestones',
                'contract_type' => 'istisnaa',
                'rules' => array_merge($this->defaultGovernanceRulesFor('istisnaa'), [
                    'milestone_rules' => ['milestones' => []],
                    'payment_rules' => ['mode' => 'staged'],
                ]),
            ]);
        $this->assertJsonError($stagedWithoutMilestones, 422);
        self::assertStringContainsString('requires explicit milestone', strtolower($this->asString($stagedWithoutMilestones->json('errors.islamic_interest_guardrails.0'))));
    }

    public function test_if090_missing_milestone_variation_and_project_mapping_each_block_activation(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $checker = $this->createUserWithRole('platform-admin');
        $this->ensureProductFamilyBaseline($actor, 'istisnaa');
        $this->ensureShariaApprover($checker);
        $productPublicId = $this->createProduct($actor, 'IST-BLOCK-'.Str::ulid(), 'istisnaa', null, [
            'milestone_rules' => null,
            'variation_rules' => null,
            'payment_rules' => null,
            'project_accounting_mapping_profile' => null,
        ]);
        $reviewPublicId = $this->requestProductReview($actor, $productPublicId);

        $approve = $this->withApiHeaders()
            ->actingAsSanctum($checker)
            ->postJson('/api/v1/islamic-compliance-reviews/'.$reviewPublicId.'/review', [
                'decision' => 'approve',
            ]);

        $this->assertJsonError($approve, 422);
        self::assertNotEmpty($approve->json('errors.islamic_product_milestone_rules'));
        self::assertNotEmpty($approve->json('errors.islamic_product_variation_rules'));
        self::assertNotEmpty($approve->json('errors.islamic_product_project_accounting_mapping_profile'));
    }

    public function test_if090_istisnaa_payload_exposes_policy_and_mapping_bindings(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $create = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-products', [
                'code' => 'IST-PAYLOAD-'.Str::ulid(),
                'name' => 'Istisnaa payload',
                'contract_type' => 'istisnaa',
                'rules' => $this->defaultGovernanceRulesFor('istisnaa'),
            ]);
        $this->assertJsonSuccess($create, 201);
        $create->assertJsonPath('data.rules.payment_rules.mode', 'staged');
        $create->assertJsonPath('data.rules.milestone_rules.approval_required', true);
        $create->assertJsonPath('data.rules.project_accounting_mapping_profile.profile_code', 'istisnaa_project_v1');
    }

    public function test_moudaraba_guaranteed_return_rejected_and_missing_reporting_loss_rules_block_activation(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $guaranteed = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-products', [
                'code' => 'MDR-GUAR-'.Str::ulid(),
                'name' => 'Moudaraba Guaranteed',
                'contract_type' => 'moudaraba',
                'rules' => ['guaranteed_return' => true],
            ]);
        $this->assertJsonError($guaranteed, 422);

        $checker = $this->createUserWithRole('platform-admin');
        $this->ensureProductFamilyBaseline($actor, 'moudaraba');
        $this->ensureShariaApprover($checker);
        $productPublicId = $this->createProduct($actor, 'MDR-'.Str::ulid(), 'moudaraba', null, [
            'reporting_cadence_policy' => null,
            'loss_rules' => null,
        ]);
        $reviewPublicId = $this->requestProductReview($actor, $productPublicId);

        $approve = $this->withApiHeaders()
            ->actingAsSanctum($checker)
            ->postJson('/api/v1/islamic-compliance-reviews/'.$reviewPublicId.'/review', [
                'decision' => 'approve',
            ]);

        $this->assertJsonError($approve, 422);
        self::assertNotEmpty($approve->json('errors.islamic_product_reporting_cadence_policy'));
        self::assertNotEmpty($approve->json('errors.islamic_product_loss_rules'));
    }

    public function test_if100_moudaraba_rule_schema_rejects_unknown_keys(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-products', [
                'code' => 'MDR-SCHEMA-UNK-'.Str::ulid(),
                'name' => 'Moudaraba unknown key',
                'contract_type' => 'moudaraba',
                'rules' => array_merge($this->defaultGovernanceRulesFor('moudaraba'), [
                    'unexpected_rule' => ['enabled' => true],
                ]),
            ]);
        $this->assertJsonError($response, 422);
        self::assertStringContainsString('unknown moudaraba rule key', strtolower($this->asString($response->json('errors.islamic_interest_guardrails.0'))));
    }

    public function test_if100_fixed_institution_profit_entitlement_is_rejected(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-products', [
                'code' => 'MDR-FIXED-'.Str::ulid(),
                'name' => 'Moudaraba fixed institution entitlement',
                'contract_type' => 'moudaraba',
                'rules' => array_merge($this->defaultGovernanceRulesFor('moudaraba'), [
                    'profit_sharing_ratio_rules' => [
                        'institution_ratio' => 0.5,
                        'entrepreneur_ratio' => 0.5,
                        'fixed_institution_profit_amount_minor' => 1000,
                    ],
                ]),
            ]);
        $this->assertJsonError($response, 422);
        self::assertStringContainsString('fixed institution profit entitlement is forbidden', strtolower($this->asString($response->json('errors.islamic_interest_guardrails.0'))));
    }

    public function test_if100_missing_reporting_cadence_and_loss_rules_block_activation(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $checker = $this->createUserWithRole('platform-admin');
        $this->ensureProductFamilyBaseline($actor, 'moudaraba');
        $this->ensureShariaApprover($checker);
        $productPublicId = $this->createProduct($actor, 'MDR-BLOCK-'.Str::ulid(), 'moudaraba', null, [
            'reporting_cadence_policy' => null,
            'loss_rules' => null,
        ]);
        $reviewPublicId = $this->requestProductReview($actor, $productPublicId);

        $approve = $this->withApiHeaders()
            ->actingAsSanctum($checker)
            ->postJson('/api/v1/islamic-compliance-reviews/'.$reviewPublicId.'/review', [
                'decision' => 'approve',
            ]);
        $this->assertJsonError($approve, 422);
        self::assertNotEmpty($approve->json('errors.islamic_product_reporting_cadence_policy'));
        self::assertNotEmpty($approve->json('errors.islamic_product_loss_rules'));
    }

    public function test_if100_moudaraba_payload_exposes_liability_and_liquidation_policies(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $create = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-products', [
                'code' => 'MDR-PAYLOAD-'.Str::ulid(),
                'name' => 'Moudaraba payload',
                'contract_type' => 'moudaraba',
                'rules' => $this->defaultGovernanceRulesFor('moudaraba'),
            ]);
        $this->assertJsonSuccess($create, 201);
        $create->assertJsonPath('data.rules.misconduct_negligence_breach_rules.entrepreneur_liability_requires_evidence', true);
        $create->assertJsonPath('data.rules.liquidation_rules.requires_final_report', true);
    }

    public function test_moucharaka_ratio_valuation_and_contribution_policy_gates(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $ratioRejected = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-products', [
                'code' => 'MCH-RATIO-'.Str::ulid(),
                'name' => 'Moucharaka Bad Ratio',
                'contract_type' => 'moucharaka',
                'rules' => [
                    'loss_ratio_rules' => ['allocation_basis' => 'profit_ratio'],
                ],
            ]);
        $this->assertJsonError($ratioRejected, 422);

        $checker = $this->createUserWithRole('platform-admin');
        $this->ensureProductFamilyBaseline($actor, 'moucharaka');
        $this->ensureShariaApprover($checker);
        $productPublicId = $this->createProduct($actor, 'MCH-'.Str::ulid(), 'moucharaka', null, [
            'buyout_policy' => ['enabled' => true],
        ]);
        $reviewPublicId = $this->requestProductReview($actor, $productPublicId);

        $approve = $this->withApiHeaders()
            ->actingAsSanctum($checker)
            ->postJson('/api/v1/islamic-compliance-reviews/'.$reviewPublicId.'/review', [
                'decision' => 'approve',
            ]);

        $this->assertJsonError($approve, 422);
        self::assertNotEmpty($approve->json('errors.islamic_product_contribution_evidence_policy'));
        self::assertNotEmpty($approve->json('errors.islamic_product_valuation_policy'));
    }

    public function test_product_readiness_endpoint_exposes_gate_level_failures(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $this->ensureProductFamilyBaseline($actor, 'ijara');
        $this->ensureProductFamilySignoff($actor, 'ijara');
        $productPublicId = $this->createProduct($actor, 'READ-IJ-'.Str::ulid(), 'ijara', null, [
            'maintenance_policy' => null,
        ]);

        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->getJson('/api/v1/islamic-products/'.$productPublicId.'/readiness');
        $this->assertJsonSuccess($response);

        $response->assertJsonPath('data.status', 'fail');
        self::assertNotEmpty($response->json('data.failures_by_gate.islamic_product_maintenance_policy'));
        $missingItems = $response->json('data.missing_items');
        self::assertIsArray($missingItems);
        self::assertContains('islamic_product:maintenance_policy', $missingItems);
    }

    public function test_product_approval_persists_readiness_snapshot_and_exposes_it(): void
    {
        $maker = $this->createUserWithRole('platform-admin');
        $checker = $this->createUserWithRole('platform-admin');
        $this->ensureProductFamilyBaseline($maker, 'mourabaha');
        $this->ensureProductFamilySignoff($maker, 'mourabaha');
        $this->ensureShariaApprover($checker);

        $productPublicId = $this->createProduct($maker, 'READ-SNAP-'.Str::ulid(), 'murabaha');
        $reviewPublicId = $this->requestProductReview($maker, $productPublicId);

        $approve = $this->withApiHeaders()
            ->actingAsSanctum($checker)
            ->postJson('/api/v1/islamic-compliance-reviews/'.$reviewPublicId.'/review', [
                'decision' => 'approve',
            ]);
        $this->assertJsonSuccess($approve);

        $productId = DB::table('islamic_products')->where('public_id', $productPublicId)->value('id');
        self::assertIsNumeric($productId);
        $snapshot = DB::table('islamic_product_readiness_snapshots')
            ->where('islamic_product_id', (int) $productId)
            ->orderByDesc('id')
            ->first();
        self::assertTrue(is_object($snapshot));
        self::assertSame($reviewPublicId, $snapshot->review_public_id ?? null);
        self::assertNotEmpty($snapshot->snapshot_hash ?? null);

        $show = $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->getJson('/api/v1/islamic-products/'.$productPublicId.'/readiness');
        $this->assertJsonSuccess($show);
        $show->assertJsonPath('data.latest_snapshot.review_public_id', $reviewPublicId);
        $show->assertJsonPath('data.latest_snapshot.family_code', 'mourabaha');

        $history = $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->getJson('/api/v1/islamic-products/'.$productPublicId.'/readiness-snapshots');
        $this->assertJsonSuccess($history);
        $snapshots = $history->json('data.readiness_snapshots');
        self::assertIsArray($snapshots);
        self::assertNotEmpty($snapshots);
        self::assertContains($reviewPublicId, array_column($snapshots, 'review_public_id'));

        $this->assertDatabaseHas('activity_log', [
            'event' => 'islamic.product.readiness.evaluated',
            'log_name' => 'security',
        ]);
        $this->assertDatabaseHas('activity_log', [
            'event' => 'islamic.product.readiness.snapshot_stored',
            'log_name' => 'security',
        ]);
        $this->assertDatabaseHas('activity_log', [
            'event' => 'islamic.product.readiness.approved',
            'log_name' => 'security',
        ]);
    }

    public function test_if040_asset_registry_captures_extended_fields_and_starts_at_requested(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $financingPublicId = $this->createDraftFinancing($actor);

        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/assets', [
                'asset_type' => 'vehicle',
                'asset_category' => 'commercial_vehicle',
                'description' => 'Toyota Hilux 2026',
                'supplier_name' => 'Acme Motors SARL',
                'supplier_reference' => 'PO-2026-0042',
                'acquisition_cost_minor' => 800000,
                'currency' => 'XAF',
                'location' => 'Douala showroom A',
                'condition_status' => 'new',
                'document_bundle' => ['quote' => 'doc-1', 'photo' => 'doc-2'],
                'customer_request_ref' => 'REQ-001',
            ]);
        $this->assertJsonSuccess($response, 201);
        $response->assertJsonPath('data.lifecycle_status', 'requested');
        $response->assertJsonPath('data.asset_category', 'commercial_vehicle');
        $response->assertJsonPath('data.supplier_name', 'Acme Motors SARL');
        $response->assertJsonPath('data.supplier_reference', 'PO-2026-0042');
        $response->assertJsonPath('data.acquisition_cost_minor', 800000);
        $response->assertJsonPath('data.location', 'Douala showroom A');
        $response->assertJsonPath('data.condition_status', 'new');
        $response->assertJsonPath('data.document_bundle.quote', 'doc-1');
        $response->assertJsonPath('data.customer_request_ref', 'REQ-001');
    }

    public function test_if040_mourabaha_approval_requires_asset_purchased_or_controlled(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $financingPublicId = $this->createDraftFinancing($actor);
        $agencyId = $this->getFinancingAgencyId($financingPublicId);
        $this->seedMurabahaMappings($actor, $agencyId);

        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/assets', [
                'asset_type' => 'vehicle',
                'description' => 'Asset still in requested state',
            ])->assertCreated();
        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/installments', [
                'installments' => [
                    ['due_on' => now()->addMonth()->toDateString(), 'amount_minor' => 500000],
                    ['due_on' => now()->addMonths(2)->toDateString(), 'amount_minor' => 500000],
                ],
            ])->assertCreated();

        $approve = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/approve');

        $this->assertJsonError($approve, 422);
        self::assertStringContainsString(
            'IF-040 activation gate',
            $this->asString($approve->json('errors.islamic_financing.0'))
        );
    }

    public function test_if040_invalid_asset_transition_is_rejected_by_state_machine(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $financingPublicId = $this->createDraftFinancing($actor);

        $created = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/assets', [
                'asset_type' => 'vehicle',
                'description' => 'For invalid transition test',
            ]);
        $this->assertJsonSuccess($created, 201);
        $assetPublicId = $this->requireStringJsonPath($created, 'data.public_id');

        // requested -> leased is not a valid direct transition per IF-040 state machine.
        $jump = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financed-assets/'.$assetPublicId.'/transition', [
                'to_status' => 'leased',
                'evidence' => ['lease_commencement_evidence' => 'doc-x'],
            ]);
        $this->assertJsonError($jump, 422);
        self::assertStringContainsString(
            'not allowed',
            $this->asString($jump->json('errors.islamic_financed_asset.0'))
        );
    }

    public function test_if040_asset_transition_without_required_evidence_is_rejected(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $financingPublicId = $this->createDraftFinancing($actor);

        $created = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/assets', [
                'asset_type' => 'vehicle',
                'description' => 'Missing evidence test',
            ]);
        $this->assertJsonSuccess($created, 201);
        $assetPublicId = $this->requireStringJsonPath($created, 'data.public_id');

        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financed-assets/'.$assetPublicId.'/transition', [
                'to_status' => 'purchased',
                'evidence' => [],
            ]);
        $this->assertJsonError($response, 422);
        self::assertStringContainsString(
            'requires evidence key',
            $this->asString($response->json('errors.islamic_financed_asset.0'))
        );
    }

    public function test_if040_valid_asset_transition_persists_audit_timeline(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $financingPublicId = $this->createDraftFinancing($actor);

        $created = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/assets', [
                'asset_type' => 'vehicle',
                'description' => 'Valid transition test',
            ]);
        $this->assertJsonSuccess($created, 201);
        $assetPublicId = $this->requireStringJsonPath($created, 'data.public_id');

        $transition = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financed-assets/'.$assetPublicId.'/transition', [
                'to_status' => 'quoted',
                'evidence' => ['supplier_pricing_ref' => 'PRICE-LIST-001'],
                'reason_code' => 'supplier_quoted',
            ]);
        $this->assertJsonSuccess($transition, 200);
        $transition->assertJsonPath('data.transition.from_status', 'requested');
        $transition->assertJsonPath('data.transition.to_status', 'quoted');
        $transition->assertJsonPath('data.transition.evidence_refs.supplier_pricing_ref', 'PRICE-LIST-001');
        $transition->assertJsonPath('data.asset.lifecycle_status', 'quoted');

        $timeline = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->getJson('/api/v1/islamic-financed-assets/'.$assetPublicId.'/timeline');
        $this->assertJsonSuccess($timeline);
        $timeline->assertJsonPath('data.current_status', 'quoted');
        self::assertNotEmpty($timeline->json('data.timeline_events'));
        $timeline->assertJsonPath('meta.pagination.current_page', 1);

        $this->assertDatabaseHas('activity_log', [
            'event' => 'islamic.asset.transitioned',
            'log_name' => 'security',
        ]);
    }

    public function test_if040_terminal_asset_cannot_transition(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $financingPublicId = $this->createDraftFinancing($actor);

        $created = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/assets', [
                'asset_type' => 'vehicle',
                'description' => 'Cancellation terminal test',
            ]);
        $this->assertJsonSuccess($created, 201);
        $assetPublicId = $this->requireStringJsonPath($created, 'data.public_id');

        $cancel = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financed-assets/'.$assetPublicId.'/transition', [
                'to_status' => 'cancelled',
                'evidence' => ['cancellation_reason' => 'customer_withdrew'],
            ]);
        $this->assertJsonSuccess($cancel, 200);

        $follow = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financed-assets/'.$assetPublicId.'/transition', [
                'to_status' => 'purchased',
                'evidence' => ['purchase_evidence' => 'invoice-x'],
            ]);
        $this->assertJsonError($follow, 422);
        self::assertStringContainsString(
            'terminal',
            $this->asString($follow->json('errors.islamic_financed_asset.0'))
        );
    }

    public function test_if040_document_backed_evidence_rejected_when_doc_id_does_not_exist(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $financingPublicId = $this->createDraftFinancing($actor);

        $created = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/assets', [
                'asset_type' => 'vehicle',
                'description' => 'Forged evidence test',
            ]);
        $this->assertJsonSuccess($created, 201);
        $assetPublicId = $this->requireStringJsonPath($created, 'data.public_id');

        // First transition to quoted with a non-document-backed key (allowed).
        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financed-assets/'.$assetPublicId.'/transition', [
                'to_status' => 'quoted',
                'evidence' => ['supplier_pricing_ref' => 'PRICE-LIST-001'],
            ])->assertOk();

        // Now attempt to transition to purchased with a FAKE document ID.
        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financed-assets/'.$assetPublicId.'/transition', [
                'to_status' => 'purchased',
                'evidence' => ['purchase_evidence' => '01JFAKEDOCIDXXXXXXXXXX'],
            ]);
        $this->assertJsonError($response, 422);
        self::assertStringContainsString(
            'references unknown document',
            $this->asString($response->json('errors.islamic_financed_asset.0'))
        );
    }

    public function test_if040_document_backed_evidence_accepted_when_doc_exists(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $financingPublicId = $this->createDraftFinancing($actor);
        $documentPublicId = $this->createEvidenceDocument($actor);

        $created = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/assets', [
                'asset_type' => 'vehicle',
                'description' => 'Real document evidence test',
            ]);
        $this->assertJsonSuccess($created, 201);
        $assetPublicId = $this->requireStringJsonPath($created, 'data.public_id');

        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financed-assets/'.$assetPublicId.'/transition', [
                'to_status' => 'quoted',
                'evidence' => ['supplier_pricing_ref' => 'PRICE-LIST-001'],
            ])->assertOk();

        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financed-assets/'.$assetPublicId.'/transition', [
                'to_status' => 'purchased',
                'evidence' => ['purchase_evidence' => $documentPublicId],
            ]);
        $this->assertJsonSuccess($response, 200);
        $response->assertJsonPath('data.transition.evidence_refs.purchase_evidence', $documentPublicId);
    }

    public function test_if040_auto_advance_to_purchased_runs_acceptance_screening_and_blocks_on_fail(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $financingPublicId = $this->createDraftFinancing($actor);
        $agencyId = $this->getFinancingAgencyId($financingPublicId);
        $this->seedMurabahaMappings($actor, $agencyId);

        // Set up a screening rule that BLOCKS asset acceptance for supplier "Tainted Supplier SARL".
        $this->seedActiveScreeningPolicyRule(
            ruleType: 'supplier_flag',
            matchKey: 'tainted supplier sarl',
            action: 'block',
            version: 1,
            scopeType: 'product_family',
            scopeValue: 'mourabaha',
        );

        // Create asset with the tainted supplier name.
        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/assets', [
                'asset_type' => 'vehicle',
                'description' => 'Tainted-supplier asset',
                'supplier_name' => 'Tainted Supplier SARL',
            ])->assertCreated();

        // Set up Mourabaha origination chain — purchase evidence attempt MUST block on screening.
        $requestPublicId = $this->createMourabahaRequestForFinancing($actor, $financingPublicId);
        $quotePublicId = $this->createMourabahaQuoteForRequest($actor, $requestPublicId);
        $this->approveMourabahaRequestQuote($actor, $requestPublicId, $quotePublicId);

        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/purchase-evidence', [
                'mourabaha_request_public_id' => $requestPublicId,
                'evidence_type' => 'supplier_invoice',
                'institution_control_status' => 'owned_by_institution',
            ]);
        $this->assertJsonError($response, 422);
        self::assertStringContainsString(
            'blocked by screening',
            $this->asString($response->json('errors.islamic_mourabaha_purchase_evidence.0'))
        );
    }

    public function test_if040_activation_gate_is_product_family_aware(): void
    {
        // Mourabaha: purchased OR controlled (uses IF-040 asset gate).
        $mourabaha = IslamicFinancedAssetStateMachine::activationGateStatusesFor('mourabaha');
        self::assertContains('purchased', $mourabaha);
        self::assertContains('controlled', $mourabaha);
        self::assertNotContains('leased', $mourabaha);
        self::assertTrue(IslamicFinancedAssetStateMachine::requiresAssetActivationGate('mourabaha'));

        // Ijara: controlled OR leased (uses IF-040 asset gate).
        $ijara = IslamicFinancedAssetStateMachine::activationGateStatusesFor('ijara');
        self::assertContains('controlled', $ijara);
        self::assertContains('leased', $ijara);
        self::assertNotContains('purchased', $ijara);
        self::assertTrue(IslamicFinancedAssetStateMachine::requiresAssetActivationGate('ijara'));

        // Ijara wa Iqtina: also uses IF-040 asset gate.
        $ijaraTransfer = IslamicFinancedAssetStateMachine::activationGateStatusesFor('ijara_wa_iqtina');
        self::assertContains('leased', $ijaraTransfer);
        self::assertTrue(IslamicFinancedAssetStateMachine::requiresAssetActivationGate('ijara_wa_iqtina'));

        // Salam and Istisna'a do NOT use the IF-040 asset gate; they use their own registries (IF-041 goods, IF-042 projects).
        self::assertFalse(IslamicFinancedAssetStateMachine::requiresAssetActivationGate('salam'));
        self::assertFalse(IslamicFinancedAssetStateMachine::requiresAssetActivationGate('istisnaa'));
        self::assertFalse(IslamicFinancedAssetStateMachine::requiresAssetActivationGate('nonexistent_family'));
    }

    public function test_if041_salam_goods_creation_requires_quantity_delivery_date_and_quality(): void
    {
        $actor = $this->createUserWithRole('platform-admin');

        $missingQuantity = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-salam-goods', [
                'goods_category' => 'wheat',
                'quality_spec' => 'Grade A, moisture < 12%',
                'quantity_unit' => 'tonne',
                'delivery_date' => '2026-12-01',
                'delivery_place' => 'Port of Douala',
            ]);
        $this->assertJsonError($missingQuantity, 422);

        $missingDeliveryDate = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-salam-goods', [
                'goods_category' => 'wheat',
                'quality_spec' => 'Grade A, moisture < 12%',
                'quantity_units' => 100,
                'quantity_unit' => 'tonne',
                'delivery_place' => 'Port of Douala',
            ]);
        $this->assertJsonError($missingDeliveryDate, 422);

        $missingQuality = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-salam-goods', [
                'goods_category' => 'wheat',
                'quantity_units' => 100,
                'quantity_unit' => 'tonne',
                'delivery_date' => '2026-12-01',
                'delivery_place' => 'Port of Douala',
            ]);
        $this->assertJsonError($missingQuality, 422);

        $ok = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-salam-goods', [
                'goods_category' => 'wheat',
                'quality_spec' => 'Grade A, moisture < 12%',
                'quantity_units' => 100,
                'quantity_unit' => 'tonne',
                'delivery_date' => '2026-12-01',
                'delivery_place' => 'Port of Douala',
            ]);
        $this->assertJsonSuccess($ok, 201);
        $ok->assertJsonPath('data.status', 'specified');
        $ok->assertJsonPath('data.quantity_units', 100);
    }

    public function test_if041_partial_delivery_transitions_then_full_delivers(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $document1 = $this->createEvidenceDocument($actor);
        $document2 = $this->createEvidenceDocument($actor);

        $created = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-salam-goods', [
                'goods_category' => 'rice',
                'quality_spec' => 'White basmati',
                'quantity_units' => 50,
                'quantity_unit' => 'tonne',
                'delivery_date' => '2026-12-15',
                'delivery_place' => 'Douala warehouse',
            ]);
        $this->assertJsonSuccess($created, 201);
        $goodsPublicId = $this->requireStringJsonPath($created, 'data.public_id');

        $partial = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-salam-goods/'.$goodsPublicId.'/deliveries', [
                'delivered_units' => 30,
                'delivered_on' => '2026-12-10',
                'delivery_evidence' => $document1,
                'inventory_reference' => 'INV-001',
            ]);
        $this->assertJsonSuccess($partial, 200);
        $partial->assertJsonPath('data.goods.status', 'partially_delivered');
        $partial->assertJsonPath('data.goods.delivered_units', 30);

        $final = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-salam-goods/'.$goodsPublicId.'/deliveries', [
                'delivered_units' => 20,
                'delivered_on' => '2026-12-15',
                'delivery_evidence' => $document2,
                'inventory_reference' => 'INV-002',
            ]);
        $this->assertJsonSuccess($final, 200);
        $final->assertJsonPath('data.goods.status', 'delivered');
        $final->assertJsonPath('data.goods.delivered_units', 50);
    }

    public function test_if041_delivery_exceeding_specified_quantity_is_rejected(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $document = $this->createEvidenceDocument($actor);

        $created = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-salam-goods', [
                'goods_category' => 'sugar',
                'quality_spec' => 'Refined white',
                'quantity_units' => 10,
                'quantity_unit' => 'tonne',
                'delivery_date' => '2026-11-30',
                'delivery_place' => 'Yaounde',
            ]);
        $this->assertJsonSuccess($created, 201);
        $goodsPublicId = $this->requireStringJsonPath($created, 'data.public_id');

        $excess = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-salam-goods/'.$goodsPublicId.'/deliveries', [
                'delivered_units' => 11,
                'delivered_on' => '2026-11-25',
                'delivery_evidence' => $document,
                'inventory_reference' => 'INV-99',
            ]);
        $this->assertJsonError($excess, 422);
        self::assertStringContainsString(
            'exceeds specified quantity',
            $this->asString($excess->json('errors.islamic_salam_goods_delivery.0'))
        );
    }

    public function test_if041_delivery_requires_inventory_or_settlement_reference(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $document = $this->createEvidenceDocument($actor);

        $created = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-salam-goods', [
                'goods_category' => 'maize',
                'quality_spec' => 'Yellow #2',
                'quantity_units' => 25,
                'quantity_unit' => 'tonne',
                'delivery_date' => '2026-10-15',
                'delivery_place' => 'Garoua',
            ]);
        $this->assertJsonSuccess($created, 201);
        $goodsPublicId = $this->requireStringJsonPath($created, 'data.public_id');

        $missingRef = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-salam-goods/'.$goodsPublicId.'/deliveries', [
                'delivered_units' => 10,
                'delivered_on' => '2026-10-15',
                'delivery_evidence' => $document,
            ]);
        $this->assertJsonError($missingRef, 422);
        self::assertStringContainsString(
            'inventory_reference or settlement_reference',
            $this->asString($missingRef->json('errors.islamic_salam_goods_delivery.0'))
        );
    }

    public function test_if041_substitution_and_non_delivery_require_evidence(): void
    {
        $actor = $this->createUserWithRole('platform-admin');

        $created = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-salam-goods', [
                'goods_category' => 'cotton',
                'quality_spec' => 'Grade 2',
                'quantity_units' => 5,
                'quantity_unit' => 'tonne',
                'delivery_date' => '2026-09-01',
                'delivery_place' => 'Maroua',
            ]);
        $this->assertJsonSuccess($created, 201);
        $goodsPublicId = $this->requireStringJsonPath($created, 'data.public_id');

        $missingReason = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-salam-goods/'.$goodsPublicId.'/transition', [
                'to_status' => 'substitution_requested',
                'evidence' => [],
            ]);
        $this->assertJsonError($missingReason, 422);
        self::assertStringContainsString(
            'requires evidence key "substitution_reason"',
            $this->asString($missingReason->json('errors.islamic_salam_goods.0'))
        );

        $missingEvidence = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-salam-goods/'.$goodsPublicId.'/transition', [
                'to_status' => 'non_delivery',
                'evidence' => [],
            ]);
        $this->assertJsonError($missingEvidence, 422);
        self::assertStringContainsString(
            'requires evidence key "non_delivery_evidence"',
            $this->asString($missingEvidence->json('errors.islamic_salam_goods.0'))
        );
    }

    public function test_if041_invalid_status_transition_rejected_and_terminal_blocks_further(): void
    {
        $actor = $this->createUserWithRole('platform-admin');

        $created = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-salam-goods', [
                'goods_category' => 'corn',
                'quality_spec' => 'Yellow',
                'quantity_units' => 5,
                'quantity_unit' => 'tonne',
                'delivery_date' => '2026-08-01',
                'delivery_place' => 'Bafoussam',
            ]);
        $this->assertJsonSuccess($created, 201);
        $goodsPublicId = $this->requireStringJsonPath($created, 'data.public_id');

        // specified -> settled is not allowed directly.
        $jump = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-salam-goods/'.$goodsPublicId.'/transition', [
                'to_status' => 'settled',
                'evidence' => ['settlement_reference' => 'SET-001'],
            ]);
        $this->assertJsonError($jump, 422);
        self::assertStringContainsString('not allowed', $this->asString($jump->json('errors.islamic_salam_goods.0')));

        $cancel = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-salam-goods/'.$goodsPublicId.'/transition', [
                'to_status' => 'cancelled',
                'evidence' => ['cancellation_reason' => 'customer_withdrew'],
            ]);
        $this->assertJsonSuccess($cancel, 200);

        $afterTerminal = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-salam-goods/'.$goodsPublicId.'/transition', [
                'to_status' => 'delivered',
                'evidence' => ['delivery_evidence' => 'doc-x'],
            ]);
        $this->assertJsonError($afterTerminal, 422);
        self::assertStringContainsString('terminal', $this->asString($afterTerminal->json('errors.islamic_salam_goods.0')));
    }

    public function test_if041_delivery_state_transitions_must_use_deliveries_endpoint(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $document = $this->createEvidenceDocument($actor);

        $created = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-salam-goods', [
                'goods_category' => 'beans',
                'quality_spec' => 'Grade A',
                'quantity_units' => 10,
                'quantity_unit' => 'tonne',
                'delivery_date' => '2026-12-20',
                'delivery_place' => 'Bertoua',
            ]);
        $this->assertJsonSuccess($created, 201);
        $goodsPublicId = $this->requireStringJsonPath($created, 'data.public_id');

        $transition = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-salam-goods/'.$goodsPublicId.'/transition', [
                'to_status' => 'partially_delivered',
                'evidence' => ['delivery_evidence' => $document],
            ]);
        $this->assertJsonError($transition, 422);
        self::assertStringContainsString('deliveries endpoint', strtolower($this->asString($transition->json('errors.islamic_salam_goods.0'))));
    }

    public function test_if041_delivery_evidence_must_reference_existing_document(): void
    {
        $actor = $this->createUserWithRole('platform-admin');

        $created = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-salam-goods', [
                'goods_category' => 'coffee',
                'quality_spec' => 'Arabica AA',
                'quantity_units' => 2,
                'quantity_unit' => 'tonne',
                'delivery_date' => '2026-12-31',
                'delivery_place' => 'Kribi',
            ]);
        $this->assertJsonSuccess($created, 201);
        $goodsPublicId = $this->requireStringJsonPath($created, 'data.public_id');

        $forged = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-salam-goods/'.$goodsPublicId.'/deliveries', [
                'delivered_units' => 1,
                'delivered_on' => '2026-12-20',
                'delivery_evidence' => '01JFAKEDOCXXXXX',
                'inventory_reference' => 'INV-99',
            ]);
        $this->assertJsonError($forged, 422);
    }

    public function test_if041_salam_financing_approval_rejects_terminal_or_breached_goods_statuses(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $financingPublicId = $this->createDraftFinancing($actor);
        $this->setFinancingContractType($financingPublicId, 'salam');

        $created = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-salam-goods', [
                'islamic_financing_public_id' => $financingPublicId,
                'goods_category' => 'sesame',
                'quality_spec' => 'Premium hulled',
                'quantity_units' => 40,
                'quantity_unit' => 'tonne',
                'delivery_date' => '2026-12-31',
                'delivery_place' => 'Douala',
            ]);
        $this->assertJsonSuccess($created, 201);
        $goodsPublicId = $this->requireStringJsonPath($created, 'data.public_id');

        $cancel = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-salam-goods/'.$goodsPublicId.'/transition', [
                'to_status' => 'cancelled',
                'evidence' => ['cancellation_reason' => 'supplier_defaulted'],
            ]);
        $this->assertJsonSuccess($cancel, 200);

        $approve = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/approve');
        $this->assertJsonError($approve, 422);
        self::assertStringContainsString(
            'cannot proceed with goods in "cancelled" status',
            strtolower($this->asString($approve->json('errors.islamic_financing.0')))
        );
    }

    public function test_if081_salam_approval_rejects_vague_goods_specification(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $checker = $this->createUserWithRole('platform-admin');
        $this->ensureProductFamilyBaseline($actor, 'salam');
        $this->ensureShariaApprover($checker);
        $productPublicId = $this->createProduct($actor, 'SAL-VAGUE-'.Str::ulid(), 'salam');
        $reviewPublicId = $this->requestProductReview($actor, $productPublicId);
        $this->withApiHeaders()
            ->actingAsSanctum($checker)
            ->postJson('/api/v1/islamic-compliance-reviews/'.$reviewPublicId.'/review', ['decision' => 'approve'])
            ->assertOk();

        $agency = $this->createAgency('SAL-VG-'.Str::upper(Str::random(4)));
        $clientPublicId = $this->createClient($agency['id']);
        $financing = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings', [
                'client_public_id' => $clientPublicId,
                'agency_public_id' => $agency['public_id'],
                'product_public_id' => $productPublicId,
                'contract_type' => 'salam',
                'purchase_cost_minor' => 500000,
                'allowed_costs_minor' => 0,
                'markup_minor' => 100000,
                'supplier_name' => 'Salam Supplier',
            ]);
        $this->assertJsonSuccess($financing, 201);
        $financingPublicId = $this->requireStringJsonPath($financing, 'data.public_id');

        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-salam-goods', [
                'islamic_financing_public_id' => $financingPublicId,
                'goods_category' => 'wheat',
                'quality_spec' => 'goods',
                'quantity_units' => 20,
                'quantity_unit' => 'tonne',
                'delivery_date' => '2026-12-20',
                'delivery_place' => 'Port',
            ])->assertCreated();

        $approve = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/approve');
        $this->assertJsonError($approve, 422);
        self::assertStringContainsString('vague quality', strtolower($this->asString($approve->json('errors.islamic_financing.0'))));
    }

    public function test_if081_salam_upfront_payment_before_approval_is_rejected(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $checker = $this->createUserWithRole('platform-admin');
        $this->ensureProductFamilyBaseline($actor, 'salam');
        $this->ensureShariaApprover($checker);
        $productPublicId = $this->createProduct($actor, 'SAL-PAY-BLOCK-'.Str::ulid(), 'salam');
        $reviewPublicId = $this->requestProductReview($actor, $productPublicId);
        $this->withApiHeaders()
            ->actingAsSanctum($checker)
            ->postJson('/api/v1/islamic-compliance-reviews/'.$reviewPublicId.'/review', ['decision' => 'approve'])
            ->assertOk();

        $agency = $this->createAgency('SAL-PB-'.Str::upper(Str::random(4)));
        $clientPublicId = $this->createClient($agency['id']);
        $financing = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings', [
                'client_public_id' => $clientPublicId,
                'agency_public_id' => $agency['public_id'],
                'product_public_id' => $productPublicId,
                'contract_type' => 'salam',
                'purchase_cost_minor' => 500000,
                'allowed_costs_minor' => 0,
                'markup_minor' => 100000,
                'supplier_name' => 'Salam Supplier',
            ]);
        $this->assertJsonSuccess($financing, 201);
        $financingPublicId = $this->requireStringJsonPath($financing, 'data.public_id');

        $rejected = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/salam-upfront-payments', [
                'amount_minor' => 100000,
                'idempotency_key' => 'salam-pre-approval-1',
            ]);
        $this->assertJsonError($rejected, 422);
        self::assertStringContainsString('only be posted after financing approval', strtolower($this->asString($rejected->json('errors.islamic_salam_upfront_payment.0'))));
    }

    public function test_if081_salam_upfront_payment_after_approval_posts_successfully(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $checker = $this->createUserWithRole('platform-admin');
        $this->ensureProductFamilyBaseline($actor, 'salam');
        $this->ensureShariaApprover($checker);
        $productPublicId = $this->createProduct($actor, 'SAL-PAY-OK-'.Str::ulid(), 'salam');
        $reviewPublicId = $this->requestProductReview($actor, $productPublicId);
        $this->withApiHeaders()
            ->actingAsSanctum($checker)
            ->postJson('/api/v1/islamic-compliance-reviews/'.$reviewPublicId.'/review', ['decision' => 'approve'])
            ->assertOk();

        $agency = $this->createAgency('SAL-PO-'.Str::upper(Str::random(4)));
        $clientPublicId = $this->createClient($agency['id']);
        $financing = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings', [
                'client_public_id' => $clientPublicId,
                'agency_public_id' => $agency['public_id'],
                'product_public_id' => $productPublicId,
                'contract_type' => 'salam',
                'purchase_cost_minor' => 500000,
                'allowed_costs_minor' => 0,
                'markup_minor' => 100000,
                'supplier_name' => 'Salam Supplier',
            ]);
        $this->assertJsonSuccess($financing, 201);
        $financingPublicId = $this->requireStringJsonPath($financing, 'data.public_id');
        $agencyId = $this->getFinancingAgencyId($financingPublicId);
        $this->seedSalamMappings($actor, $agencyId);

        $goods = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-salam-goods', [
                'islamic_financing_public_id' => $financingPublicId,
                'goods_category' => 'wheat',
                'quality_spec' => 'Grade A wheat with moisture below twelve percent',
                'quantity_units' => 20,
                'quantity_unit' => 'tonne',
                'delivery_date' => '2026-12-20',
                'delivery_place' => 'Port of Douala',
            ]);
        $this->assertJsonSuccess($goods, 201);

        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/approve')
            ->assertOk();

        $posted = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/salam-upfront-payments', [
                'amount_minor' => 300000,
                'idempotency_key' => 'salam-approved-pay-1',
            ]);
        $this->assertJsonSuccess($posted, 201);
        $posted->assertJsonPath('data.operation_code', 'salam_upfront_payment');
        $posted->assertJsonPath('data.amount_minor', 300000);

        $this->assertDatabaseHas('islamic_salam_upfront_payments', [
            'islamic_financing_id' => DB::table('islamic_financings')->where('public_id', $financingPublicId)->value('id'),
            'operation_code' => 'salam_upfront_payment',
            'amount_minor' => 300000,
            'status' => 'posted',
        ]);
        $this->assertDatabaseHas('islamic_financings', [
            'public_id' => $financingPublicId,
            'status' => 'paid',
        ]);
    }

    public function test_if081_salam_partial_delivery_opens_settlement_state(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $document = $this->createEvidenceDocument($actor);

        $created = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-salam-goods', [
                'goods_category' => 'barley',
                'quality_spec' => 'Premium barley export grade',
                'quantity_units' => 100,
                'quantity_unit' => 'tonne',
                'delivery_date' => '2026-12-25',
                'delivery_place' => 'Kribi terminal',
            ]);
        $this->assertJsonSuccess($created, 201);
        $goodsPublicId = $this->requireStringJsonPath($created, 'data.public_id');
        $goodsId = DB::table('islamic_salam_goods')->where('public_id', $goodsPublicId)->value('id');
        self::assertNotNull($goodsId);

        $delivery = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-salam-goods/'.$goodsPublicId.'/deliveries', [
                'delivered_units' => 40,
                'delivered_on' => '2026-12-01',
                'delivery_evidence' => $document,
                'inventory_reference' => 'INV-SAL-001',
            ]);
        $this->assertJsonSuccess($delivery, 200);
        $delivery->assertJsonPath('data.goods.status', 'partially_delivered');

        $this->assertDatabaseHas('islamic_salam_settlement_states', [
            'islamic_salam_goods_id' => $goodsId,
            'status' => 'open',
            'total_units' => 100,
            'delivered_units' => 40,
            'outstanding_units' => 60,
        ]);
        $this->assertDatabaseHas('activity_log', [
            'event' => 'islamic.salam.partial_delivery_settlement_opened',
            'log_name' => 'security',
        ]);
    }

    public function test_if042_milestone_payment_requires_approved_inspection(): void
    {
        $actor = $this->createUserWithRole('platform-admin');

        $project = $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-istisnaa-projects', [
            'project_specification' => 'Build warehouse 1000m2',
            'contractor_reference' => 'CTR-001',
            'customer_reference' => 'CUS-001',
            'site_location' => 'Douala industrial zone',
        ]);
        $this->assertJsonSuccess($project, 201);
        $projectPublicId = $this->requireStringJsonPath($project, 'data.public_id');

        $milestone = $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-istisnaa-projects/'.$projectPublicId.'/milestones', [
            'milestone_code' => 'M1',
            'title' => 'Foundation',
            'planned_amount_minor' => 1000000,
            'due_date' => '2026-12-01',
        ]);
        $this->assertJsonSuccess($milestone, 201);
        $milestonePublicId = $this->requireStringJsonPath($milestone, 'data.public_id');

        // Attempt payment without inspection: must block.
        $denied = $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-istisnaa-milestones/'.$milestonePublicId.'/payments', [
            'amount_minor' => 500000,
            'idempotency_key' => 'pay-1',
        ]);
        $this->assertJsonError($denied, 422);
        self::assertStringContainsString('inspection must be approved', $this->asString($denied->json('errors.islamic_istisnaa_payment.0')));

        // Reject inspection -> payment still blocked.
        $document = $this->createEvidenceDocument($actor);
        $rejected = $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-istisnaa-milestones/'.$milestonePublicId.'/inspection', [
            'decision' => 'rejected',
            'evidence_document_public_id' => $document,
        ]);
        $this->assertJsonSuccess($rejected, 201);
        $blocked = $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-istisnaa-milestones/'.$milestonePublicId.'/payments', [
            'amount_minor' => 500000,
            'idempotency_key' => 'pay-2',
        ]);
        $this->assertJsonError($blocked, 422);

        // Approve inspection -> payment allowed.
        $approveDoc = $this->createEvidenceDocument($actor);
        $approve = $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-istisnaa-milestones/'.$milestonePublicId.'/inspection', [
            'decision' => 'approved',
            'evidence_document_public_id' => $approveDoc,
        ]);
        $this->assertJsonSuccess($approve, 201);
        $paid = $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-istisnaa-milestones/'.$milestonePublicId.'/payments', [
            'amount_minor' => 500000,
            'idempotency_key' => 'pay-3',
        ]);
        $this->assertJsonSuccess($paid, 201);
        $this->assertDatabaseHas('activity_log', [
            'event' => 'islamic.istisnaa_milestone.payment_released',
        ]);
    }

    public function test_if042_payment_amount_cannot_exceed_remaining_approved_amount(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $project = $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-istisnaa-projects', [
            'project_specification' => 'Project Q',
            'contractor_reference' => 'CTR-Q',
            'customer_reference' => 'CUS-Q',
            'site_location' => 'Site Q',
        ]);
        $this->assertJsonSuccess($project, 201);
        $projectPublicId = $this->requireStringJsonPath($project, 'data.public_id');

        $milestone = $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-istisnaa-projects/'.$projectPublicId.'/milestones', [
            'milestone_code' => 'Q1',
            'title' => 'Q1',
            'planned_amount_minor' => 100000,
            'due_date' => '2026-12-01',
        ]);
        $this->assertJsonSuccess($milestone, 201);
        $milestonePublicId = $this->requireStringJsonPath($milestone, 'data.public_id');

        $doc = $this->createEvidenceDocument($actor);
        $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-istisnaa-milestones/'.$milestonePublicId.'/inspection', [
            'decision' => 'approved',
            'evidence_document_public_id' => $doc,
        ])->assertCreated();

        $excess = $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-istisnaa-milestones/'.$milestonePublicId.'/payments', [
            'amount_minor' => 200000,
            'idempotency_key' => 'pay-excess',
        ]);
        $this->assertJsonError($excess, 422);
        self::assertStringContainsString('exceeds remaining approved', $this->asString($excess->json('errors.islamic_istisnaa_payment.0')));
    }

    public function test_if042_variation_cannot_change_already_posted_payment_facts(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $project = $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-istisnaa-projects', [
            'project_specification' => 'Variation immutability test',
            'contractor_reference' => 'CTR-V',
            'customer_reference' => 'CUS-V',
            'site_location' => 'Site V',
        ]);
        $this->assertJsonSuccess($project, 201);
        $projectPublicId = $this->requireStringJsonPath($project, 'data.public_id');

        $milestone = $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-istisnaa-projects/'.$projectPublicId.'/milestones', [
            'milestone_code' => 'V1',
            'title' => 'Variation milestone',
            'planned_amount_minor' => 100000,
            'due_date' => '2026-12-01',
        ]);
        $this->assertJsonSuccess($milestone, 201);
        $milestonePublicId = $this->requireStringJsonPath($milestone, 'data.public_id');

        $doc = $this->createEvidenceDocument($actor);
        $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-istisnaa-milestones/'.$milestonePublicId.'/inspection', [
            'decision' => 'approved',
            'evidence_document_public_id' => $doc,
        ])->assertCreated();
        $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-istisnaa-milestones/'.$milestonePublicId.'/payments', [
            'amount_minor' => 50000,
            'idempotency_key' => 'pay-V1',
        ])->assertCreated();

        // Variation on a milestone that has posted payments must be rejected.
        $blocked = $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-istisnaa-projects/'.$projectPublicId.'/variations', [
            'target_type' => 'milestone',
            'target_public_id' => $milestonePublicId,
            'after_snapshot' => ['planned_amount_minor' => 20000],
            'reason' => 'Trying to retroactively reduce milestone amount',
            'approval_evidence_document_public_id' => $doc,
        ]);
        $this->assertJsonError($blocked, 422);
        self::assertStringContainsString('posted payment facts are immutable', $this->asString($blocked->json('errors.islamic_istisnaa_variation.0')));
    }

    public function test_if042_variation_creates_before_after_snapshot_for_unpaid_milestone(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $project = $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-istisnaa-projects', [
            'project_specification' => 'Snapshot test',
            'contractor_reference' => 'CTR-S',
            'customer_reference' => 'CUS-S',
            'site_location' => 'Site S',
        ]);
        $this->assertJsonSuccess($project, 201);
        $projectPublicId = $this->requireStringJsonPath($project, 'data.public_id');

        $milestone = $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-istisnaa-projects/'.$projectPublicId.'/milestones', [
            'milestone_code' => 'S1',
            'title' => 'Original Title',
            'planned_amount_minor' => 100000,
            'due_date' => '2026-12-01',
        ]);
        $this->assertJsonSuccess($milestone, 201);
        $milestonePublicId = $this->requireStringJsonPath($milestone, 'data.public_id');
        $variationApprovalDoc = $this->createEvidenceDocument($actor);

        $applied = $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-istisnaa-projects/'.$projectPublicId.'/variations', [
            'target_type' => 'milestone',
            'target_public_id' => $milestonePublicId,
            'after_snapshot' => ['planned_amount_minor' => 120000, 'title' => 'Updated Title'],
            'reason' => 'Scope expansion approved',
            'approval_evidence_document_public_id' => $variationApprovalDoc,
        ]);
        $this->assertJsonSuccess($applied, 201);
        $applied->assertJsonPath('data.before_snapshot.planned_amount_minor', 100000);
        $applied->assertJsonPath('data.before_snapshot.title', 'Original Title');
        $applied->assertJsonPath('data.after_snapshot.planned_amount_minor', 120000);
    }

    public function test_if042_project_acceptance_requires_all_milestones_paid_in_full(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $project = $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-istisnaa-projects', [
            'project_specification' => 'Acceptance test',
            'contractor_reference' => 'CTR-A',
            'customer_reference' => 'CUS-A',
            'site_location' => 'Site A',
        ]);
        $this->assertJsonSuccess($project, 201);
        $projectPublicId = $this->requireStringJsonPath($project, 'data.public_id');

        $milestone = $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-istisnaa-projects/'.$projectPublicId.'/milestones', [
            'milestone_code' => 'A1',
            'title' => 'Phase A',
            'planned_amount_minor' => 100000,
            'due_date' => '2026-12-01',
        ]);
        $this->assertJsonSuccess($milestone, 201);
        $milestonePublicId = $this->requireStringJsonPath($milestone, 'data.public_id');

        $acceptanceDoc = $this->createEvidenceDocument($actor);
        // Acceptance fails when milestone unpaid.
        $blocked = $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-istisnaa-projects/'.$projectPublicId.'/accept', [
            'acceptance_evidence_document_public_id' => $acceptanceDoc,
        ]);
        $this->assertJsonError($blocked, 422);
        self::assertStringContainsString('requires all milestones paid in full', $this->asString($blocked->json('errors.islamic_istisnaa_project.0')));

        // Fully pay milestone, then acceptance succeeds.
        $doc = $this->createEvidenceDocument($actor);
        $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-istisnaa-milestones/'.$milestonePublicId.'/inspection', [
            'decision' => 'approved',
            'evidence_document_public_id' => $doc,
        ])->assertCreated();
        $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-istisnaa-milestones/'.$milestonePublicId.'/payments', [
            'amount_minor' => 100000,
            'idempotency_key' => 'pay-full',
        ])->assertCreated();

        $accepted = $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-istisnaa-projects/'.$projectPublicId.'/accept', [
            'acceptance_evidence_document_public_id' => $acceptanceDoc,
        ]);
        $this->assertJsonSuccess($accepted, 200);
        $accepted->assertJsonPath('data.status', 'accepted');
    }

    public function test_if042_parallel_supplier_reference_can_be_approved_via_dedicated_endpoint(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $project = $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-istisnaa-projects', [
            'project_specification' => 'Parallel supplier test',
            'contractor_reference' => 'CTR-PS',
            'customer_reference' => 'CUS-PS',
            'site_location' => 'Site PS',
            'parallel_supplier_reference' => 'SUPP-EXT-001',
            'parallel_supplier_approved' => false,
        ]);
        $this->assertJsonSuccess($project, 201);
        $projectPublicId = $this->requireStringJsonPath($project, 'data.public_id');
        $project->assertJsonPath('data.parallel_supplier_approved', false);

        $approvalDoc = $this->createEvidenceDocument($actor);
        $approve = $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-istisnaa-projects/'.$projectPublicId.'/parallel-supplier/approve', [
            'approval_evidence_document_public_id' => $approvalDoc,
            'comments' => 'Supplier vetted and approved',
        ]);
        $this->assertJsonSuccess($approve, 200);
        $approve->assertJsonPath('data.parallel_supplier_approved', true);

        // Second approval attempt is rejected (already approved).
        $duplicate = $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-istisnaa-projects/'.$projectPublicId.'/parallel-supplier/approve', [
            'approval_evidence_document_public_id' => $approvalDoc,
        ]);
        $this->assertJsonError($duplicate, 422);

        $this->assertDatabaseHas('activity_log', [
            'event' => 'islamic.istisnaa_project.parallel_supplier_approved',
        ]);
    }

    public function test_if042_parallel_supplier_approval_endpoint_rejects_when_no_supplier_reference(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $project = $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-istisnaa-projects', [
            'project_specification' => 'No supplier ref test',
            'contractor_reference' => 'CTR-NO',
            'customer_reference' => 'CUS-NO',
            'site_location' => 'Site NO',
        ]);
        $this->assertJsonSuccess($project, 201);
        $projectPublicId = $this->requireStringJsonPath($project, 'data.public_id');

        $doc = $this->createEvidenceDocument($actor);
        $response = $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-istisnaa-projects/'.$projectPublicId.'/parallel-supplier/approve', [
            'approval_evidence_document_public_id' => $doc,
        ]);
        $this->assertJsonError($response, 422);
        self::assertStringContainsString('no parallel supplier reference', strtolower($this->asString($response->json('errors.islamic_istisnaa_project.0'))));
    }

    public function test_if042_payment_idempotency_key_prevents_double_post(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $project = $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-istisnaa-projects', [
            'project_specification' => 'Idempotency test',
            'contractor_reference' => 'CTR-I',
            'customer_reference' => 'CUS-I',
            'site_location' => 'Site I',
        ]);
        $this->assertJsonSuccess($project, 201);
        $projectPublicId = $this->requireStringJsonPath($project, 'data.public_id');

        $milestone = $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-istisnaa-projects/'.$projectPublicId.'/milestones', [
            'milestone_code' => 'I1',
            'title' => 'Idempotent',
            'planned_amount_minor' => 200000,
            'due_date' => '2026-12-01',
        ]);
        $this->assertJsonSuccess($milestone, 201);
        $milestonePublicId = $this->requireStringJsonPath($milestone, 'data.public_id');

        $doc = $this->createEvidenceDocument($actor);
        $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-istisnaa-milestones/'.$milestonePublicId.'/inspection', [
            'decision' => 'approved',
            'evidence_document_public_id' => $doc,
        ])->assertCreated();
        $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-istisnaa-milestones/'.$milestonePublicId.'/payments', [
            'amount_minor' => 50000,
            'idempotency_key' => 'dup-key',
        ])->assertCreated();

        $dup = $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-istisnaa-milestones/'.$milestonePublicId.'/payments', [
            'amount_minor' => 50000,
            'idempotency_key' => 'dup-key',
        ]);
        $this->assertJsonError($dup, 422);
        self::assertStringContainsString('idempotency_key already posted', $this->asString($dup->json('errors.islamic_istisnaa_payment.0')));
    }

    public function test_if042_istisnaa_financing_approval_does_not_require_murabaha_posting_chain(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $financingPublicId = $this->createDraftFinancing($actor);
        $this->setFinancingContractType($financingPublicId, 'istisnaa');

        $project = $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-istisnaa-projects', [
            'islamic_financing_public_id' => $financingPublicId,
            'project_specification' => 'Factory expansion phase 1',
            'contractor_reference' => 'CTR-APPROVE',
            'customer_reference' => 'CUS-APPROVE',
            'site_location' => 'Douala Zone 2',
        ]);
        $this->assertJsonSuccess($project, 201);
        $projectPublicId = $this->requireStringJsonPath($project, 'data.public_id');

        $milestone = $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-istisnaa-projects/'.$projectPublicId.'/milestones', [
            'milestone_code' => 'APP-1',
            'title' => 'Civil works',
            'planned_amount_minor' => 1500000,
            'due_date' => '2026-12-10',
        ]);
        $this->assertJsonSuccess($milestone, 201);

        $approve = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/approve');
        $this->assertJsonSuccess($approve, 200);
        $approve->assertJsonPath('data.status', 'approved');

        $this->assertDatabaseHas('islamic_financings', [
            'public_id' => $financingPublicId,
            'status' => 'approved',
            'journal_entry_id' => null,
        ]);
        $this->assertDatabaseMissing('journal_entries', [
            'source_module' => 'islamic_finance',
            'source_type' => 'murabaha_financing',
            'source_public_id' => $financingPublicId,
        ]);
    }

    public function test_if042_project_approval_screening_fail_blocks_financing_approval_and_payment(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $financingPublicId = $this->createDraftFinancing($actor);
        $this->setFinancingContractType($financingPublicId, 'istisnaa');
        $this->seedActiveScreeningPolicyRule('supplier_flag', 'ctr-blocked', 'block', 1, 'product_family', 'istisnaa');

        $project = $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-istisnaa-projects', [
            'islamic_financing_public_id' => $financingPublicId,
            'project_specification' => 'Blocked project',
            'contractor_reference' => 'CTR-BLOCKED',
            'customer_reference' => 'CUS-BLOCKED',
            'site_location' => 'Site blocked',
        ]);
        $this->assertJsonSuccess($project, 201);
        $projectPublicId = $this->requireStringJsonPath($project, 'data.public_id');

        $milestone = $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-istisnaa-projects/'.$projectPublicId.'/milestones', [
            'milestone_code' => 'BLK-1',
            'title' => 'Blocked stage',
            'planned_amount_minor' => 900000,
            'due_date' => '2026-12-01',
        ]);
        $this->assertJsonSuccess($milestone, 201);
        $milestonePublicId = $this->requireStringJsonPath($milestone, 'data.public_id');

        $approve = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/approve');
        $this->assertJsonError($approve, 422);
        self::assertStringContainsString('project approval screening result', strtolower($this->asString($approve->json('errors.islamic_financing.0'))));

        $doc = $this->createEvidenceDocument($actor);
        $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-istisnaa-milestones/'.$milestonePublicId.'/inspection', [
            'decision' => 'approved',
            'evidence_document_public_id' => $doc,
        ])->assertCreated();

        $payment = $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-istisnaa-milestones/'.$milestonePublicId.'/payments', [
            'amount_minor' => 100000,
            'idempotency_key' => 'blocked-payment',
        ]);
        $this->assertJsonError($payment, 422);
        self::assertStringContainsString('project approval screening result', strtolower($this->asString($payment->json('errors.islamic_istisnaa_payment.0'))));
    }

    public function test_if042_financing_approval_validates_all_linked_projects_not_just_first(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $financingPublicId = $this->createDraftFinancing($actor);
        $this->setFinancingContractType($financingPublicId, 'istisnaa');

        $projectA = $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-istisnaa-projects', [
            'islamic_financing_public_id' => $financingPublicId,
            'project_specification' => 'Project A',
            'contractor_reference' => 'CTR-A',
            'customer_reference' => 'CUS-A',
            'site_location' => 'Site A',
        ]);
        $this->assertJsonSuccess($projectA, 201);
        $projectAPublicId = $this->requireStringJsonPath($projectA, 'data.public_id');
        $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-istisnaa-projects/'.$projectAPublicId.'/milestones', [
            'milestone_code' => 'A1',
            'title' => 'A1',
            'planned_amount_minor' => 100000,
            'due_date' => '2026-12-01',
        ])->assertCreated();

        $projectB = $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-istisnaa-projects', [
            'islamic_financing_public_id' => $financingPublicId,
            'project_specification' => 'Project B',
            'contractor_reference' => 'CTR-B',
            'customer_reference' => 'CUS-B',
            'site_location' => 'Site B',
            'parallel_supplier_reference' => 'SUP-B-EXT',
        ]);
        $this->assertJsonSuccess($projectB, 201);
        $projectBPublicId = $this->requireStringJsonPath($projectB, 'data.public_id');
        $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-istisnaa-projects/'.$projectBPublicId.'/milestones', [
            'milestone_code' => 'B1',
            'title' => 'B1',
            'planned_amount_minor' => 120000,
            'due_date' => '2026-12-10',
        ])->assertCreated();

        $blocked = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/approve');
        $this->assertJsonError($blocked, 422);
        self::assertStringContainsString(
            'parallel supplier reference must be approved',
            strtolower($this->asString($blocked->json('errors.islamic_financing.0')))
        );
        self::assertStringContainsString(
            strtolower($projectBPublicId),
            strtolower($this->asString($blocked->json('errors.islamic_financing.0')))
        );

        $approvalDoc = $this->createEvidenceDocument($actor);
        $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-istisnaa-projects/'.$projectBPublicId.'/parallel-supplier/approve', [
            'approval_evidence_document_public_id' => $approvalDoc,
        ])->assertOk();

        $approved = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/approve');
        $this->assertJsonSuccess($approved, 200);
        $approved->assertJsonPath('data.status', 'approved');
    }

    public function test_if042_project_linking_to_non_draft_financing_is_rejected(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $financingPublicId = $this->createDraftFinancing($actor);
        $this->setFinancingContractType($financingPublicId, 'istisnaa');
        DB::table('islamic_financings')
            ->where('public_id', $financingPublicId)
            ->update(['status' => 'approved']);

        $response = $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-istisnaa-projects', [
            'islamic_financing_public_id' => $financingPublicId,
            'project_specification' => 'Should fail',
            'contractor_reference' => 'CTR-ND',
            'customer_reference' => 'CUS-ND',
            'site_location' => 'Site ND',
        ]);
        $this->assertJsonError($response, 422);
        self::assertStringContainsString(
            'only be linked to draft financings',
            strtolower($this->asString($response->json('errors.islamic_istisnaa_project.0')))
        );
    }

    public function test_if042_timeline_records_milestones_inspections_payments_and_variations(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $project = $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-istisnaa-projects', [
            'project_specification' => 'Timeline coverage test',
            'contractor_reference' => 'CTR-TL',
            'customer_reference' => 'CUS-TL',
            'site_location' => 'Site TL',
        ]);
        $this->assertJsonSuccess($project, 201);
        $projectPublicId = $this->requireStringJsonPath($project, 'data.public_id');

        $milestone = $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-istisnaa-projects/'.$projectPublicId.'/milestones', [
            'milestone_code' => 'TL-1',
            'title' => 'Timeline phase',
            'planned_amount_minor' => 100000,
            'due_date' => '2026-12-01',
        ]);
        $this->assertJsonSuccess($milestone, 201);
        $milestonePublicId = $this->requireStringJsonPath($milestone, 'data.public_id');

        $inspectionEvidence = $this->createEvidenceDocument($actor);
        $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-istisnaa-milestones/'.$milestonePublicId.'/inspection', [
            'decision' => 'approved',
            'evidence_document_public_id' => $inspectionEvidence,
        ])->assertCreated();
        $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-istisnaa-milestones/'.$milestonePublicId.'/payments', [
            'amount_minor' => 50000,
            'idempotency_key' => 'tl-pay-1',
        ])->assertCreated();
        $variationMilestone = $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-istisnaa-projects/'.$projectPublicId.'/milestones', [
            'milestone_code' => 'TL-2',
            'title' => 'Variation target milestone',
            'planned_amount_minor' => 50000,
            'due_date' => '2026-12-05',
        ]);
        $this->assertJsonSuccess($variationMilestone, 201);
        $variationMilestonePublicId = $this->requireStringJsonPath($variationMilestone, 'data.public_id');

        $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-istisnaa-projects/'.$projectPublicId.'/variations', [
            'target_type' => 'milestone',
            'target_public_id' => $variationMilestonePublicId,
            'after_snapshot' => ['title' => 'Variation target milestone (updated)'],
            'reason' => 'Schedule update approved',
            'approval_evidence_document_public_id' => $inspectionEvidence,
        ])->assertCreated();

        $timeline = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->getJson('/api/v1/islamic-istisnaa-projects/'.$projectPublicId.'/timeline');
        $this->assertJsonSuccess($timeline);
        $timeline->assertJsonPath('data.project.public_id', $projectPublicId);
        self::assertNotEmpty($timeline->json('data.timeline_events'));
        $timeline->assertJsonPath('meta.pagination.current_page', 1);
    }

    public function test_if091_payment_without_approved_milestone_rejected(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $project = $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-istisnaa-projects', [
            'project_specification' => 'IF091 payment gate',
            'contractor_reference' => 'CTR-IF091',
            'customer_reference' => 'CUS-IF091',
            'site_location' => 'IF091 Site',
        ]);
        $this->assertJsonSuccess($project, 201);
        $projectPublicId = $this->requireStringJsonPath($project, 'data.public_id');
        $milestone = $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-istisnaa-projects/'.$projectPublicId.'/milestones', [
            'milestone_code' => 'IF091-M1',
            'title' => 'Milestone',
            'planned_amount_minor' => 100000,
            'due_date' => '2026-12-01',
        ]);
        $this->assertJsonSuccess($milestone, 201);
        $milestonePublicId = $this->requireStringJsonPath($milestone, 'data.public_id');

        $payment = $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-istisnaa-milestones/'.$milestonePublicId.'/payments', [
            'amount_minor' => 10000,
            'idempotency_key' => 'if091-no-approval',
        ]);
        $this->assertJsonError($payment, 422);
        self::assertStringContainsString('inspection must be approved', strtolower($this->asString($payment->json('errors.islamic_istisnaa_payment.0'))));
    }

    public function test_if091_variation_requires_approval_before_future_obligation_change(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $project = $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-istisnaa-projects', [
            'project_specification' => 'IF091 variation approval',
            'contractor_reference' => 'CTR-IF091-V',
            'customer_reference' => 'CUS-IF091-V',
            'site_location' => 'IF091 Variation Site',
        ]);
        $this->assertJsonSuccess($project, 201);
        $projectPublicId = $this->requireStringJsonPath($project, 'data.public_id');
        $milestone = $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-istisnaa-projects/'.$projectPublicId.'/milestones', [
            'milestone_code' => 'IF091-V1',
            'title' => 'Variation target',
            'planned_amount_minor' => 120000,
            'due_date' => '2026-12-05',
        ]);
        $this->assertJsonSuccess($milestone, 201);
        $milestonePublicId = $this->requireStringJsonPath($milestone, 'data.public_id');

        $missingApproval = $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-istisnaa-projects/'.$projectPublicId.'/variations', [
            'target_type' => 'milestone',
            'target_public_id' => $milestonePublicId,
            'after_snapshot' => ['planned_amount_minor' => 130000],
            'reason' => 'Future obligation update',
        ]);
        $this->assertJsonError($missingApproval, 422);
        self::assertNotEmpty($missingApproval->json('errors.approval_evidence_document_public_id'));
    }

    public function test_if091_delivery_without_acceptance_rejected(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $project = $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-istisnaa-projects', [
            'project_specification' => 'IF091 acceptance gate',
            'contractor_reference' => 'CTR-IF091-A',
            'customer_reference' => 'CUS-IF091-A',
            'site_location' => 'IF091 Acceptance Site',
        ]);
        $this->assertJsonSuccess($project, 201);
        $projectPublicId = $this->requireStringJsonPath($project, 'data.public_id');
        $milestone = $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-istisnaa-projects/'.$projectPublicId.'/milestones', [
            'milestone_code' => 'IF091-A1',
            'title' => 'Acceptance milestone',
            'planned_amount_minor' => 50000,
            'due_date' => '2026-12-05',
        ]);
        $this->assertJsonSuccess($milestone, 201);
        $milestonePublicId = $this->requireStringJsonPath($milestone, 'data.public_id');
        $doc = $this->createEvidenceDocument($actor);
        $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-istisnaa-milestones/'.$milestonePublicId.'/inspection', [
            'decision' => 'approved',
            'evidence_document_public_id' => $doc,
        ])->assertCreated();
        $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-istisnaa-milestones/'.$milestonePublicId.'/payments', [
            'amount_minor' => 50000,
            'idempotency_key' => 'if091-close-ready',
        ])->assertCreated();

        $missingAcceptance = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-istisnaa-projects/'.$projectPublicId.'/accept', []);
        $this->assertJsonError($missingAcceptance, 422);
        self::assertNotEmpty($missingAcceptance->json('errors.acceptance_evidence_document_public_id'));
    }

    public function test_if071_rental_schedule_uses_rental_lines_not_sale_price_formula(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $checker = $this->createUserWithRole('platform-admin');
        $this->ensureProductFamilyBaseline($actor, 'ijara');
        $this->ensureShariaApprover($checker);
        $productPublicId = $this->createProduct($actor, 'IJ-RS-'.Str::ulid(), 'ijara');
        $reviewPublicId = $this->requestProductReview($actor, $productPublicId);
        $this->assertJsonSuccess(
            $this->withApiHeaders()
                ->actingAsSanctum($checker)
                ->postJson('/api/v1/islamic-compliance-reviews/'.$reviewPublicId.'/review', ['decision' => 'approve'])
        );

        $agency = $this->createAgency('IJ-AG-'.Str::upper(Str::random(4)));
        $clientPublicId = $this->createClient($agency['id']);
        $financing = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings', [
                'client_public_id' => $clientPublicId,
                'agency_public_id' => $agency['public_id'],
                'product_public_id' => $productPublicId,
                'contract_type' => 'ijara',
                'purchase_cost_minor' => 800000,
                'allowed_costs_minor' => 0,
                'markup_minor' => 200000,
                'supplier_name' => 'Lease Supplier',
            ]);
        $this->assertJsonSuccess($financing, 201);
        $financingPublicId = $this->requireStringJsonPath($financing, 'data.public_id');

        $schedule = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/rental-schedules', [
                'lines' => [
                    ['due_on' => '2026-12-01', 'rental_amount_minor' => 100000],
                    ['due_on' => '2027-01-01', 'rental_amount_minor' => 90000],
                ],
            ]);
        $this->assertJsonSuccess($schedule, 201);

        $sum = (int) DB::table('islamic_ijara_rental_schedule_lines')
            ->join('islamic_financings', 'islamic_financings.id', '=', 'islamic_ijara_rental_schedule_lines.islamic_financing_id')
            ->where('islamic_financings.public_id', $financingPublicId)
            ->sum('islamic_ijara_rental_schedule_lines.rental_amount_minor');
        self::assertSame(190000, $sum);
    }

    public function test_if071_ijara_activation_requires_owned_or_controlled_asset(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $checker = $this->createUserWithRole('platform-admin');
        $this->ensureProductFamilyBaseline($actor, 'ijara');
        $this->ensureShariaApprover($checker);
        $productPublicId = $this->createProduct($actor, 'IJ-ACT-'.Str::ulid(), 'ijara');
        $reviewPublicId = $this->requestProductReview($actor, $productPublicId);
        $this->assertJsonSuccess(
            $this->withApiHeaders()
                ->actingAsSanctum($checker)
                ->postJson('/api/v1/islamic-compliance-reviews/'.$reviewPublicId.'/review', ['decision' => 'approve'])
        );

        $agency = $this->createAgency('IJ-AG-'.Str::upper(Str::random(4)));
        $clientPublicId = $this->createClient($agency['id']);
        $financing = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings', [
                'client_public_id' => $clientPublicId,
                'agency_public_id' => $agency['public_id'],
                'product_public_id' => $productPublicId,
                'contract_type' => 'ijara',
                'purchase_cost_minor' => 700000,
                'allowed_costs_minor' => 0,
                'markup_minor' => 100000,
            ]);
        $this->assertJsonSuccess($financing, 201);
        $financingPublicId = $this->requireStringJsonPath($financing, 'data.public_id');

        DB::table('islamic_financings')->where('public_id', $financingPublicId)->update(['status' => 'approved', 'updated_at' => now()]);

        $blocked = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/activate-lease');
        $this->assertJsonError($blocked, 422);
        self::assertStringContainsString('owned/controlled asset', strtolower($this->asString($blocked->json('errors.islamic_ijara_activation.0'))));
    }

    public function test_if071_ijara_damage_event_creates_approved_workflow(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $checker = $this->createUserWithRole('platform-admin');
        $this->ensureProductFamilyBaseline($actor, 'ijara');
        $this->ensureShariaApprover($checker);
        $productPublicId = $this->createProduct($actor, 'IJ-DMG-'.Str::ulid(), 'ijara');
        $reviewPublicId = $this->requestProductReview($actor, $productPublicId);
        $this->assertJsonSuccess(
            $this->withApiHeaders()
                ->actingAsSanctum($checker)
                ->postJson('/api/v1/islamic-compliance-reviews/'.$reviewPublicId.'/review', ['decision' => 'approve'])
        );

        $agency = $this->createAgency('IJ-AG-'.Str::upper(Str::random(4)));
        $clientPublicId = $this->createClient($agency['id']);
        $financing = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings', [
                'client_public_id' => $clientPublicId,
                'agency_public_id' => $agency['public_id'],
                'product_public_id' => $productPublicId,
                'contract_type' => 'ijara',
                'purchase_cost_minor' => 700000,
                'allowed_costs_minor' => 0,
                'markup_minor' => 100000,
            ]);
        $this->assertJsonSuccess($financing, 201);
        $financingPublicId = $this->requireStringJsonPath($financing, 'data.public_id');
        $agencyId = $this->getFinancingAgencyId($financingPublicId);
        $this->seedIjaraMappings($actor, $agencyId);

        $asset = $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/assets', [
            'asset_type' => 'machine',
            'description' => 'Lease asset',
        ]);
        $this->assertJsonSuccess($asset, 201);
        $assetPublicId = $this->requireStringJsonPath($asset, 'data.public_id');

        $purchaseDoc = $this->createEvidenceDocument($actor);
        $controlDoc = $this->createEvidenceDocument($actor);
        $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-financed-assets/'.$assetPublicId.'/transition', [
            'to_status' => 'purchased',
            'evidence' => ['purchase_evidence' => $purchaseDoc],
        ])->assertOk();
        $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-financed-assets/'.$assetPublicId.'/transition', [
            'to_status' => 'controlled',
            'evidence' => ['control_evidence' => $controlDoc],
        ])->assertOk();

        $conditionDoc = $this->createEvidenceDocument($actor);
        $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/lease-condition-report', [
            'asset_public_id' => $assetPublicId,
            'condition_snapshot' => ['state' => 'good'],
            'evidence_document_public_id' => $conditionDoc,
        ])->assertCreated();
        $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/rental-schedules', [
            'lines' => [
                ['due_on' => '2026-12-01', 'rental_amount_minor' => 100000],
            ],
        ])->assertCreated();
        $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/approve')->assertOk();
        $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/activate-lease')->assertOk();

        $damageDoc = $this->createEvidenceDocument($actor);
        $damage = $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/damage-events', [
            'incident_description' => 'Minor damage observed',
            'evidence_document_public_id' => $damageDoc,
        ]);
        $this->assertJsonSuccess($damage, 201);
        $damage->assertJsonPath('data.workflow_state', 'under_review');

        $this->assertDatabaseHas('islamic_ijara_events', [
            'event_type' => 'damage',
            'workflow_state' => 'under_review',
        ]);
    }

    public function test_if072_direct_transfer_mutation_rejected(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $checker = $this->createUserWithRole('platform-admin');
        $this->ensureProductFamilyBaseline($actor, 'ijara_wa_iqtina');
        $this->ensureShariaApprover($checker);
        $productPublicId = $this->createProduct($actor, 'IJW-DIR-'.Str::ulid(), 'ijara_wa_iqtina');
        $reviewPublicId = $this->requestProductReview($actor, $productPublicId);
        $this->assertJsonSuccess(
            $this->withApiHeaders()
                ->actingAsSanctum($checker)
                ->postJson('/api/v1/islamic-compliance-reviews/'.$reviewPublicId.'/review', ['decision' => 'approve'])
        );

        $agency = $this->createAgency('IJW-AG-'.Str::upper(Str::random(4)));
        $clientPublicId = $this->createClient($agency['id']);
        $financing = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings', [
                'client_public_id' => $clientPublicId,
                'agency_public_id' => $agency['public_id'],
                'product_public_id' => $productPublicId,
                'contract_type' => 'ijara_wa_iqtina',
                'purchase_cost_minor' => 700000,
                'allowed_costs_minor' => 0,
                'markup_minor' => 100000,
            ]);
        $this->assertJsonSuccess($financing, 201);
        $financingPublicId = $this->requireStringJsonPath($financing, 'data.public_id');

        $asset = $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/assets', [
            'asset_type' => 'vehicle',
            'description' => 'Transfer target asset',
        ]);
        $this->assertJsonSuccess($asset, 201);
        $assetPublicId = $this->requireStringJsonPath($asset, 'data.public_id');

        $purchaseDoc = $this->createEvidenceDocument($actor);
        $controlDoc = $this->createEvidenceDocument($actor);
        $leaseDoc = $this->createEvidenceDocument($actor);
        $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-financed-assets/'.$assetPublicId.'/transition', [
            'to_status' => 'purchased',
            'evidence' => ['purchase_evidence' => $purchaseDoc],
        ])->assertOk();
        $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-financed-assets/'.$assetPublicId.'/transition', [
            'to_status' => 'controlled',
            'evidence' => ['control_evidence' => $controlDoc],
        ])->assertOk();
        $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-financed-assets/'.$assetPublicId.'/transition', [
            'to_status' => 'leased',
            'evidence' => ['lease_commencement_evidence' => $leaseDoc],
        ])->assertOk();

        $transferDoc = $this->createEvidenceDocument($actor);
        $blocked = $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-financed-assets/'.$assetPublicId.'/transition', [
            'to_status' => 'transferred',
            'evidence' => ['transfer_evidence' => $transferDoc],
        ]);
        $this->assertJsonError($blocked, 422);
        self::assertStringContainsString('direct transfer mutation rejected', strtolower($this->asString($blocked->json('errors.islamic_financed_asset.0'))));
    }

    public function test_if072_transfer_requires_completed_rental_obligations_or_approved_exception(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $checker = $this->createUserWithRole('platform-admin');
        $this->ensureProductFamilyBaseline($actor, 'ijara_wa_iqtina');
        $this->ensureShariaApprover($checker);
        $productPublicId = $this->createProduct($actor, 'IJW-REQ-'.Str::ulid(), 'ijara_wa_iqtina');
        $reviewPublicId = $this->requestProductReview($actor, $productPublicId);
        $this->assertJsonSuccess(
            $this->withApiHeaders()
                ->actingAsSanctum($checker)
                ->postJson('/api/v1/islamic-compliance-reviews/'.$reviewPublicId.'/review', ['decision' => 'approve'])
        );
        $agency = $this->createAgency('IJW-AG-'.Str::upper(Str::random(4)));
        $clientPublicId = $this->createClient($agency['id']);
        $financing = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings', [
                'client_public_id' => $clientPublicId,
                'agency_public_id' => $agency['public_id'],
                'product_public_id' => $productPublicId,
                'contract_type' => 'ijara_wa_iqtina',
                'purchase_cost_minor' => 700000,
                'allowed_costs_minor' => 0,
                'markup_minor' => 100000,
            ]);
        $this->assertJsonSuccess($financing, 201);
        $financingPublicId = $this->requireStringJsonPath($financing, 'data.public_id');

        $asset = $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/assets', [
            'asset_type' => 'machine',
            'description' => 'Transfer eligibility asset',
        ]);
        $this->assertJsonSuccess($asset, 201);
        $assetPublicId = $this->requireStringJsonPath($asset, 'data.public_id');

        $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/rental-schedules', [
            'lines' => [
                ['due_on' => '2026-12-01', 'rental_amount_minor' => 100000],
            ],
        ])->assertCreated();

        $transferDoc = $this->createEvidenceDocument($actor);
        $blocked = $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/assets/'.$assetPublicId.'/transfer-requests', [
            'residual_amount_minor' => 10000,
            'transfer_document_public_id' => $transferDoc,
            'customer_acceptance' => [
                'accepted_at' => '2026-12-10',
                'accepted_by' => 'Customer One',
                'channel' => 'signature',
            ],
        ]);
        $this->assertJsonError($blocked, 422);
        self::assertStringContainsString('completed rental obligations', strtolower($this->asString($blocked->json('errors.islamic_ijara_transfer.0'))));
    }

    public function test_if072_transfer_posts_configured_residual_or_approved_zero_residual_mapping(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $checker = $this->createUserWithRole('platform-admin');
        $this->ensureProductFamilyBaseline($actor, 'ijara_wa_iqtina');
        $this->ensureShariaApprover($checker);
        $productPublicId = $this->createProduct($actor, 'IJW-POST-'.Str::ulid(), 'ijara_wa_iqtina');
        $reviewPublicId = $this->requestProductReview($actor, $productPublicId);
        $this->assertJsonSuccess(
            $this->withApiHeaders()
                ->actingAsSanctum($checker)
                ->postJson('/api/v1/islamic-compliance-reviews/'.$reviewPublicId.'/review', ['decision' => 'approve'])
        );
        $agency = $this->createAgency('IJW-AG-'.Str::upper(Str::random(4)));
        $clientPublicId = $this->createClient($agency['id']);
        $financing = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings', [
                'client_public_id' => $clientPublicId,
                'agency_public_id' => $agency['public_id'],
                'product_public_id' => $productPublicId,
                'contract_type' => 'ijara_wa_iqtina',
                'purchase_cost_minor' => 700000,
                'allowed_costs_minor' => 0,
                'markup_minor' => 100000,
            ]);
        $this->assertJsonSuccess($financing, 201);
        $financingPublicId = $this->requireStringJsonPath($financing, 'data.public_id');
        $agencyId = $this->getFinancingAgencyId($financingPublicId);
        $this->seedIjaraMappings($actor, $agencyId);

        $asset = $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/assets', [
            'asset_type' => 'vehicle',
            'description' => 'Transfer posting asset',
        ]);
        $this->assertJsonSuccess($asset, 201);
        $assetPublicId = $this->requireStringJsonPath($asset, 'data.public_id');

        $transferDoc = $this->createEvidenceDocument($actor);
        $requestTransfer = $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/assets/'.$assetPublicId.'/transfer-requests', [
            'residual_amount_minor' => 20000,
            'waiver_amount_minor' => 5000,
            'transfer_document_public_id' => $transferDoc,
            'customer_acceptance' => [
                'accepted_at' => '2026-12-10',
                'accepted_by' => 'Customer One',
                'channel' => 'signature',
            ],
            'approved_exception' => [
                'approved' => true,
                'reference' => 'EXC-TRF-1',
            ],
        ]);
        $this->assertJsonSuccess($requestTransfer, 201);
        $transferPublicId = $this->requireStringJsonPath($requestTransfer, 'data.public_id');

        $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-transfer-events/'.$transferPublicId.'/approve')->assertOk();
        $posted = $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-transfer-events/'.$transferPublicId.'/post', [
            'idempotency_key' => 'trf-post-1',
        ]);
        $this->assertJsonSuccess($posted, 200);
        $posted->assertJsonPath('data.status', 'posted');

        $this->assertDatabaseHas('islamic_ijara_accounting_posts', [
            'operation_code' => 'ijara_residual_transfer',
            'amount_minor' => 15000,
        ]);
        $this->assertDatabaseHas('islamic_financed_assets', [
            'public_id' => $assetPublicId,
            'lifecycle_status' => 'transferred',
            'ownership_status' => 'owned_by_customer',
        ]);
    }

    public function test_if041_timeline_records_all_transitions_and_deliveries(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $document = $this->createEvidenceDocument($actor);

        $created = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-salam-goods', [
                'goods_category' => 'cocoa',
                'quality_spec' => 'Grade I beans',
                'quantity_units' => 10,
                'quantity_unit' => 'tonne',
                'delivery_date' => '2026-12-15',
                'delivery_place' => 'Edea',
            ]);
        $this->assertJsonSuccess($created, 201);
        $goodsPublicId = $this->requireStringJsonPath($created, 'data.public_id');

        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-salam-goods/'.$goodsPublicId.'/deliveries', [
                'delivered_units' => 4,
                'delivered_on' => '2026-12-01',
                'delivery_evidence' => $document,
                'inventory_reference' => 'COCO-INV-1',
            ])->assertOk();

        $timeline = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->getJson('/api/v1/islamic-salam-goods/'.$goodsPublicId.'/timeline');
        $this->assertJsonSuccess($timeline);
        $timeline->assertJsonPath('data.current_status', 'partially_delivered');
        self::assertNotEmpty($timeline->json('data.timeline_events'));
        $timeline->assertJsonPath('meta.pagination.current_page', 1);

        $this->assertDatabaseHas('activity_log', [
            'event' => 'islamic.salam_goods.delivery_recorded',
            'log_name' => 'security',
        ]);
        $this->assertDatabaseHas('activity_log', [
            'event' => 'islamic.salam_goods.transitioned',
            'log_name' => 'security',
        ]);
    }

    public function test_if040_disposed_or_cancelled_assets_are_not_eligible_for_activation_gate(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $financingPublicId = $this->createDraftFinancing($actor);
        $agencyId = $this->getFinancingAgencyId($financingPublicId);
        $this->seedMurabahaMappings($actor, $agencyId);

        $created = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/assets', [
                'asset_type' => 'vehicle',
                'description' => 'Will be cancelled',
            ]);
        $this->assertJsonSuccess($created, 201);
        $assetPublicId = $this->requireStringJsonPath($created, 'data.public_id');

        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financed-assets/'.$assetPublicId.'/transition', [
                'to_status' => 'cancelled',
                'evidence' => ['cancellation_reason' => 'supplier_unavailable'],
            ])->assertOk();

        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/installments', [
                'installments' => [
                    ['due_on' => now()->addMonth()->toDateString(), 'amount_minor' => 500000],
                    ['due_on' => now()->addMonths(2)->toDateString(), 'amount_minor' => 500000],
                ],
            ])->assertCreated();

        $approve = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/approve');
        $this->assertJsonError($approve, 422);
        self::assertStringContainsString(
            'IF-040 activation gate',
            $this->asString($approve->json('errors.islamic_financing.0'))
        );
    }

    public function test_if043_missing_contribution_evidence_blocks_partnership_activation(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $partnership = $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-partnerships', [
            'partnership_type' => 'moucharaka',
            'reporting_cadence' => 'monthly',
            'expected_total_capital_minor' => 100000,
        ]);
        $this->assertJsonSuccess($partnership, 201);
        $partnershipPublicId = $this->requireStringJsonPath($partnership, 'data.public_id');

        $p1 = $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-partnerships/'.$partnershipPublicId.'/partners', [
            'partner_role' => 'joint_partner',
            'partner_reference' => 'P1',
            'profit_share_ratio' => 0.5,
            'loss_share_ratio' => 0.5,
            'expected_contribution_minor' => 50000,
        ]);
        $this->assertJsonSuccess($p1, 201);
        $partnerOnePublicId = $this->requireStringJsonPath($p1, 'data.public_id');
        $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-partnerships/'.$partnershipPublicId.'/partners', [
            'partner_role' => 'joint_partner',
            'partner_reference' => 'P2',
            'profit_share_ratio' => 0.5,
            'loss_share_ratio' => 0.5,
            'expected_contribution_minor' => 50000,
        ])->assertCreated();

        $doc = $this->createEvidenceDocument($actor);
        $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-partnerships/'.$partnershipPublicId.'/contributions', [
            'partner_public_id' => $partnerOnePublicId,
            'amount_minor' => 100000,
            'contributed_on' => '2026-12-01',
            'evidence_document_public_id' => $doc,
        ])->assertCreated();

        $activate = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-partnerships/'.$partnershipPublicId.'/activate');
        $this->assertJsonError($activate, 422);
        self::assertStringContainsString('evidence-backed contributions', strtolower($this->asString($activate->json('errors.islamic_partnership.0'))));
    }

    public function test_if043_moudaraba_rules_reject_invalid_partner_role_configuration(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $partnership = $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-partnerships', [
            'partnership_type' => 'moudaraba',
            'reporting_cadence' => 'monthly',
            'expected_total_capital_minor' => 100000,
        ]);
        $this->assertJsonSuccess($partnership, 201);
        $partnershipPublicId = $this->requireStringJsonPath($partnership, 'data.public_id');

        $invalid = $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-partnerships/'.$partnershipPublicId.'/partners', [
            'partner_role' => 'joint_partner',
            'partner_reference' => 'J1',
            'profit_share_ratio' => 1,
            'expected_contribution_minor' => 100000,
        ]);
        $this->assertJsonError($invalid, 422);
        self::assertStringContainsString('moudaraba partnership requires capital_provider/entrepreneur roles', strtolower($this->asString($invalid->json('errors.islamic_partnership_partner.0'))));
    }

    public function test_if043_moucharaka_rules_require_joint_contribution_structure(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $partnership = $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-partnerships', [
            'partnership_type' => 'moucharaka',
            'reporting_cadence' => 'monthly',
            'expected_total_capital_minor' => 100000,
        ]);
        $this->assertJsonSuccess($partnership, 201);
        $partnershipPublicId = $this->requireStringJsonPath($partnership, 'data.public_id');

        $p1 = $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-partnerships/'.$partnershipPublicId.'/partners', [
            'partner_role' => 'joint_partner',
            'partner_reference' => 'M1',
            'profit_share_ratio' => 1,
            'loss_share_ratio' => 1,
            'expected_contribution_minor' => 100000,
        ]);
        $this->assertJsonSuccess($p1, 201);
        $partnerPublicId = $this->requireStringJsonPath($p1, 'data.public_id');

        $doc = $this->createEvidenceDocument($actor);
        $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-partnerships/'.$partnershipPublicId.'/contributions', [
            'partner_public_id' => $partnerPublicId,
            'amount_minor' => 100000,
            'contributed_on' => '2026-12-01',
            'evidence_document_public_id' => $doc,
        ])->assertCreated();

        $activate = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-partnerships/'.$partnershipPublicId.'/activate');
        $this->assertJsonError($activate, 422);
        self::assertStringContainsString('at least two joint partners', strtolower($this->asString($activate->json('errors.islamic_partnership.0'))));
    }

    public function test_if043_profit_declaration_requires_approved_report_evidence(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $partnership = $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-partnerships', [
            'partnership_type' => 'moudaraba',
            'reporting_cadence' => 'monthly',
            'expected_total_capital_minor' => 100000,
        ]);
        $this->assertJsonSuccess($partnership, 201);
        $partnershipPublicId = $this->requireStringJsonPath($partnership, 'data.public_id');

        $capitalProvider = $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-partnerships/'.$partnershipPublicId.'/partners', [
            'partner_role' => 'capital_provider',
            'partner_reference' => 'CAP-1',
            'profit_share_ratio' => 0.8,
            'loss_share_ratio' => 1,
            'expected_contribution_minor' => 100000,
        ]);
        $this->assertJsonSuccess($capitalProvider, 201);
        $providerPublicId = $this->requireStringJsonPath($capitalProvider, 'data.public_id');
        $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-partnerships/'.$partnershipPublicId.'/partners', [
            'partner_role' => 'entrepreneur',
            'partner_reference' => 'ENT-1',
            'profit_share_ratio' => 0.2,
            'loss_share_ratio' => 0,
            'expected_contribution_minor' => 0,
        ])->assertCreated();

        $doc = $this->createEvidenceDocument($actor);
        $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-partnerships/'.$partnershipPublicId.'/contributions', [
            'partner_public_id' => $providerPublicId,
            'amount_minor' => 100000,
            'contributed_on' => '2026-12-01',
            'evidence_document_public_id' => $doc,
        ])->assertCreated();

        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-partnerships/'.$partnershipPublicId.'/activate')
            ->assertOk();

        $declaration = $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-partnerships/'.$partnershipPublicId.'/profit-declarations', [
            'period_code' => '2026M12',
            'amount_minor' => 10000,
        ]);
        $this->assertJsonError($declaration, 422);
        self::assertStringContainsString('requires an approved report', strtolower($this->asString($declaration->json('errors.islamic_partnership_profit_declaration.0'))));
    }

    public function test_if043_buyout_requires_approved_valuation(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $partnership = $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-partnerships', [
            'partnership_type' => 'moucharaka',
            'reporting_cadence' => 'monthly',
            'expected_total_capital_minor' => 100000,
        ]);
        $this->assertJsonSuccess($partnership, 201);
        $partnershipPublicId = $this->requireStringJsonPath($partnership, 'data.public_id');
        $partnershipId = $this->asInt(DB::table('islamic_partnerships')->where('public_id', $partnershipPublicId)->value('id'));

        $p1 = $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-partnerships/'.$partnershipPublicId.'/partners', [
            'partner_role' => 'joint_partner',
            'partner_reference' => 'B1',
            'profit_share_ratio' => 0.5,
            'loss_share_ratio' => 0.5,
            'expected_contribution_minor' => 50000,
        ]);
        $this->assertJsonSuccess($p1, 201);
        $partnerPublicId = $this->requireStringJsonPath($p1, 'data.public_id');
        $partnerId = $this->asInt(DB::table('islamic_partnership_partners')->where('public_id', $partnerPublicId)->value('id'));
        $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-partnerships/'.$partnershipPublicId.'/partners', [
            'partner_role' => 'joint_partner',
            'partner_reference' => 'B2',
            'profit_share_ratio' => 0.5,
            'loss_share_ratio' => 0.5,
            'expected_contribution_minor' => 50000,
        ])->assertCreated();

        $doc = $this->createEvidenceDocument($actor);
        $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-partnerships/'.$partnershipPublicId.'/contributions', [
            'partner_public_id' => $partnerPublicId,
            'amount_minor' => 50000,
            'contributed_on' => '2026-12-01',
            'evidence_document_public_id' => $doc,
        ])->assertCreated();
        $secondPartnerPublicId = $this->asString(DB::table('islamic_partnership_partners')
            ->where('islamic_partnership_id', $partnershipId)
            ->where('id', '!=', $partnerId)
            ->value('public_id'));
        $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-partnerships/'.$partnershipPublicId.'/contributions', [
            'partner_public_id' => $secondPartnerPublicId,
            'amount_minor' => 50000,
            'contributed_on' => '2026-12-01',
            'evidence_document_public_id' => $doc,
        ])->assertCreated();

        $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-partnerships/'.$partnershipPublicId.'/activate')->assertOk();

        $valuationPublicId = (string) Str::ulid();
        DB::table('islamic_partnership_valuations')->insert([
            'public_id' => $valuationPublicId,
            'islamic_partnership_id' => $partnershipId,
            'valuation_method' => 'dcf',
            'valuation_amount_minor' => 300000,
            'valuation_date' => '2026-12-01',
            'validity_until' => '2027-01-31',
            'evidence_document_public_id' => $doc,
            'approval_status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $buyout = $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-partnerships/'.$partnershipPublicId.'/buyouts', [
            'partner_public_id' => $partnerPublicId,
            'valuation_public_id' => $valuationPublicId,
            'amount_minor' => 100000,
            'idempotency_key' => 'if043-buyout-unapproved',
        ]);
        $this->assertJsonError($buyout, 422);
        self::assertStringContainsString('approved valuation', strtolower($this->asString($buyout->json('errors.islamic_partnership_buyout.0'))));
    }

    public function test_if043_liquidation_requires_approved_valuation_and_timeline_is_queryable(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $partnership = $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-partnerships', [
            'partnership_type' => 'moucharaka',
            'reporting_cadence' => 'quarterly',
            'expected_total_capital_minor' => 100000,
        ]);
        $this->assertJsonSuccess($partnership, 201);
        $partnershipPublicId = $this->requireStringJsonPath($partnership, 'data.public_id');
        $partnershipId = $this->asInt(DB::table('islamic_partnerships')->where('public_id', $partnershipPublicId)->value('id'));

        $first = $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-partnerships/'.$partnershipPublicId.'/partners', [
            'partner_role' => 'joint_partner',
            'partner_reference' => 'L1',
            'profit_share_ratio' => 0.5,
            'loss_share_ratio' => 0.5,
            'expected_contribution_minor' => 50000,
        ]);
        $this->assertJsonSuccess($first, 201);
        $firstPartnerPublicId = $this->requireStringJsonPath($first, 'data.public_id');
        $firstPartnerId = $this->asInt(DB::table('islamic_partnership_partners')->where('public_id', $firstPartnerPublicId)->value('id'));
        $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-partnerships/'.$partnershipPublicId.'/partners', [
            'partner_role' => 'joint_partner',
            'partner_reference' => 'L2',
            'profit_share_ratio' => 0.5,
            'loss_share_ratio' => 0.5,
            'expected_contribution_minor' => 50000,
        ])->assertCreated();

        $doc = $this->createEvidenceDocument($actor);
        $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-partnerships/'.$partnershipPublicId.'/contributions', [
            'partner_public_id' => $firstPartnerPublicId,
            'amount_minor' => 50000,
            'contributed_on' => '2026-12-01',
            'evidence_document_public_id' => $doc,
        ])->assertCreated();
        $secondPartnerPublicId = $this->asString(DB::table('islamic_partnership_partners')
            ->where('islamic_partnership_id', $partnershipId)
            ->where('id', '!=', $firstPartnerId)
            ->value('public_id'));
        $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-partnerships/'.$partnershipPublicId.'/contributions', [
            'partner_public_id' => $secondPartnerPublicId,
            'amount_minor' => 50000,
            'contributed_on' => '2026-12-01',
            'evidence_document_public_id' => $doc,
        ])->assertCreated();
        $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-partnerships/'.$partnershipPublicId.'/activate')->assertOk();

        $unapprovedValuationPublicId = (string) Str::ulid();
        DB::table('islamic_partnership_valuations')->insert([
            'public_id' => $unapprovedValuationPublicId,
            'islamic_partnership_id' => $partnershipId,
            'valuation_method' => 'nav',
            'valuation_amount_minor' => 200000,
            'valuation_date' => '2026-12-10',
            'validity_until' => '2027-01-15',
            'evidence_document_public_id' => $doc,
            'approval_status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $blocked = $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-partnerships/'.$partnershipPublicId.'/liquidate', [
            'valuation_public_id' => $unapprovedValuationPublicId,
            'settlement_plan_document_public_id' => $doc,
            'liquidation_evidence_document_public_id' => $doc,
        ]);
        $this->assertJsonError($blocked, 422);
        self::assertStringContainsString('approved valuation', strtolower($this->asString($blocked->json('errors.islamic_partnership.0'))));

        $approvedValuation = $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-partnerships/'.$partnershipPublicId.'/valuations', [
            'valuation_method' => 'nav',
            'valuation_amount_minor' => 250000,
            'valuation_date' => '2026-12-12',
            'validity_until' => '2027-01-31',
            'evidence_document_public_id' => $doc,
        ]);
        $this->assertJsonSuccess($approvedValuation, 201);
        $approvedValuationPublicId = $this->requireStringJsonPath($approvedValuation, 'data.public_id');

        $liquidated = $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/islamic-partnerships/'.$partnershipPublicId.'/liquidate', [
            'valuation_public_id' => $approvedValuationPublicId,
            'settlement_plan_document_public_id' => $doc,
            'liquidation_evidence_document_public_id' => $doc,
        ]);
        $this->assertJsonSuccess($liquidated, 200);
        $liquidated->assertJsonPath('data.status', 'liquidated');

        $timeline = $this->withApiHeaders()->actingAsSanctum($actor)->getJson('/api/v1/islamic-partnerships/'.$partnershipPublicId.'/timeline');
        $this->assertJsonSuccess($timeline);
        $timeline->assertJsonPath('data.status', 'liquidated');
        self::assertNotEmpty($timeline->json('data.timeline_events'));
        $timeline->assertJsonPath('meta.pagination.current_page', 1);
        $this->assertDatabaseHas('activity_log', [
            'event' => 'islamic.partnership.liquidated',
            'log_name' => 'security',
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $rules
     */
    private function createProduct(User $actor, string $code, string $contractType, ?string $agencyPublicId = null, ?array $rules = null): string
    {
        $rules = $rules === null ? $this->defaultGovernanceRulesFor($contractType) : array_merge($this->defaultGovernanceRulesFor($contractType), $rules);
        $payload = [
            'code' => $code,
            'name' => 'Product '.$code,
            'contract_type' => $contractType,
        ];
        if ($agencyPublicId !== null) {
            $payload['agency_public_id'] = $agencyPublicId;
        }
        $payload['rules'] = $rules;

        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-products', $payload);
        $this->assertJsonSuccess($response, 201);

        return $this->requireStringJsonPath($response, 'data.public_id');
    }

    /** @return array<string, mixed> */
    private function defaultGovernanceRulesFor(string $contractType): array
    {
        $familyReportingCategory = [
            'murabaha' => 'mourabaha_receivables',
            'ijara' => 'ijara_rentals',
            'ijara_wa_iqtina' => 'ijara_transfers',
            'salam' => 'salam_goods_commitments',
            'istisnaa' => 'istisnaa_projects',
            'moudaraba' => 'moudaraba_investments',
            'moucharaka' => 'moucharaka_partnerships',
            'islamic_current_account' => 'islamic_current_accounts',
            'islamic_savings_account' => 'islamic_savings_accounts',
            'islamic_investment_account' => 'islamic_investment_accounts',
        ];

        $rules = [
            'document_requirements' => ['evidence_pack' => 'baseline'],
            'authorization_rules' => ['maker_checker' => true, 'approver_scope' => 'platform_admin'],
            'operational_procedure' => ['reference' => 'if-op-v1', 'version' => '2026.01'],
            'reporting_category' => $familyReportingCategory[$contractType] ?? null,
        ];

        if ($contractType === 'murabaha') {
            $rules['mourabaha_configuration'] = [
                'allowed_asset_categories' => ['vehicles', 'equipment'],
                'allowed_costs_policy' => ['allow_documented_costs' => true, 'categories' => ['logistics', 'registration']],
                'margin_rule' => ['type' => 'fixed_markup', 'compounding' => false, 'calculus_class' => 'cost_plus_flat'],
                'repayment_schedule_rules' => ['calculation_mode' => 'fixed_installments', 'max_tenor_months' => 24],
                'delivery_requirements' => ['supplier_invoice', 'asset_transfer_note'],
                'early_settlement_policy' => ['mode' => 'rebate_allowed', 'requires_approval' => true],
                'late_payment_policy' => ['mode' => 'charity', 'compounding' => false],
                'cancellation_policy' => ['mode' => 'pre_delivery_only', 'requires_supplier_confirmation' => true],
                'accounting_mapping_requirements' => [
                    'operation_codes' => ['murabaha_receivable', 'murabaha_payable', 'murabaha_profit'],
                ],
                'sharia_approval_reference' => [
                    'workflow_public_id' => 'wf-sharia-reference',
                    'decision_reference' => 'sharia-decision-reference',
                ],
                'contract_template_reference' => [
                    'template_public_id' => 'tpl-reference',
                    'version' => 1,
                ],
            ];
        }
        if ($contractType === 'ijara') {
            $rules = array_merge($rules, [
                'leased_asset_categories' => ['equipment'],
                'rental_rules' => ['frequency' => 'monthly', 'day_of_month' => 5],
                'maintenance_policy' => ['institution_responsibility' => true],
                'takaful_policy' => ['provider' => 'takaful_co'],
                'damage_loss_rules' => ['mode' => 'insured_first'],
                'termination_rules' => ['early_termination_allowed' => true],
                'accounting_mapping_profile' => ['profile_code' => 'ijara_profile_v1'],
                'contract_template_reference' => ['template_code' => 'ijara_contract_template'],
                'transfer_option' => false,
            ]);
        }
        if ($contractType === 'ijara_wa_iqtina') {
            $rules = array_merge($this->defaultGovernanceRulesFor('ijara'), [
                'reporting_category' => 'ijara_transfers',
                'transfer_option' => true,
                'transfer_terms' => ['path' => 'residual_settlement'],
                'residual_value_policy' => ['amount_minor' => 10000],
                'contract_template_reference' => ['template_code' => 'ijara_wa_iqtina_contract_template'],
            ]);
        }
        if ($contractType === 'salam') {
            $rules = array_merge($rules, [
                'allowed_goods_policy' => [
                    'categories' => ['agri_goods'],
                    'codes' => ['rice_grade_a'],
                ],
                'specification_requirements' => [
                    'quality_standard' => 'grade_a',
                    'quantity_unit' => 'tonne',
                    'minimum_quality_clause' => 'moisture_lt_12_pct',
                ],
                'payment_timing_policy' => [
                    'mode' => 'upfront',
                    'upfront_required' => true,
                ],
                'delivery_rules' => [
                    'delivery_window_days' => 30,
                    'delivery_place_required' => true,
                ],
                'inspection_rules' => [
                    'inspector_role' => 'quality_control',
                    'acceptance_threshold' => 'contract_spec_match',
                ],
                'substitution_policy' => [
                    'allowed' => true,
                    'requires_approval' => true,
                ],
                'non_delivery_policy' => [
                    'default_remedy' => 'refund_or_replacement',
                    'escalation' => 'dispute_workflow',
                ],
                'parallel_salam_policy' => [
                    'enabled' => false,
                    'counterparty_separation_required' => true,
                    'risk_controls' => ['supplier_segregation'],
                ],
                'upfront_payment_mapping' => [
                    'profile' => 'salam_upfront',
                    'operation_code' => 'salam_upfront_payment',
                ],
                'accounting_mapping_profile' => [
                    'profile_code' => 'salam_profile_v1',
                ],
                'contract_template_reference' => [
                    'template_code' => 'salam_contract_template',
                ],
                'screening_policy_binding' => [
                    'context' => 'contract_approval',
                ],
            ]);
        }
        if ($contractType === 'istisnaa') {
            $rules = array_merge($rules, [
                'project_categories_policy' => [
                    'categories' => ['construction'],
                ],
                'milestone_rules' => [
                    'milestones' => ['foundation', 'superstructure'],
                    'approval_required' => true,
                ],
                'inspection_rules' => [
                    'inspector_role' => 'engineering_supervisor',
                ],
                'payment_rules' => [
                    'mode' => 'staged',
                    'approval_gate' => 'milestone_approved',
                ],
                'variation_rules' => [
                    'approval_threshold_minor' => 100000,
                    'scope' => 'future_obligations_only',
                ],
                'delivery_acceptance_rules' => [
                    'evidence_required' => true,
                ],
                'defect_rules' => [
                    'defect_liability_days' => 180,
                ],
                'parallel_istisnaa_policy' => [
                    'enabled' => false,
                    'counterparty_separation_required' => true,
                    'risk_controls' => ['supplier_firewall'],
                ],
                'project_accounting_mapping_profile' => [
                    'profile_code' => 'istisnaa_project_v1',
                ],
                'contract_template_reference' => [
                    'template_code' => 'istisnaa_contract_template',
                ],
                'screening_policy_binding' => [
                    'context' => 'project_approval',
                ],
            ]);
        }
        if ($contractType === 'moudaraba') {
            $rules = array_merge($rules, [
                'eligible_business_activities_policy' => [
                    'categories' => ['trade', 'services'],
                ],
                'capital_rules' => [
                    'disbursement_mode' => 'tranche',
                    'capital_protection_guarantee' => false,
                ],
                'profit_sharing_ratio_rules' => [
                    'institution_ratio' => 0.6,
                    'entrepreneur_ratio' => 0.4,
                ],
                'reporting_cadence_policy' => [
                    'frequency' => 'monthly',
                    'max_days_after_period_end' => 10,
                ],
                'evidence_requirements_policy' => [
                    'business_plan_required' => true,
                    'periodic_report_evidence_required' => true,
                ],
                'loss_rules' => [
                    'ordinary_loss_allocation' => 'capital_provider',
                ],
                'misconduct_negligence_breach_rules' => [
                    'entrepreneur_liability_requires_evidence' => true,
                ],
                'liquidation_rules' => [
                    'requires_final_report' => true,
                ],
                'accounting_mapping_profile' => [
                    'profile_code' => 'moudaraba_profile_v1',
                ],
                'contract_template_reference' => [
                    'template_code' => 'moudaraba_contract_template',
                ],
                'screening_policy_binding' => [
                    'context' => 'contract_approval',
                ],
            ]);
        }

        return $rules;
    }

    private function requestProductReview(User $actor, string $productPublicId): string
    {
        $review = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-products/'.$productPublicId.'/compliance-reviews');
        $this->assertJsonSuccess($review, 201);

        return $this->requireStringJsonPath($review, 'data.public_id');
    }

    private function createApprovedProduct(User $maker, string $code, string $contractType, ?string $agencyPublicId = null): string
    {
        $checker = $this->createUserWithRole('platform-admin');
        $this->ensureMourabahaBaseline($maker);
        $this->ensureShariaApprover($checker);
        $productPublicId = $this->createProduct($maker, $code, $contractType, $agencyPublicId);

        $review = $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/islamic-products/'.$productPublicId.'/compliance-reviews');
        $reviewPublicId = $this->requireStringJsonPath($review, 'data.public_id');

        $this->assertJsonSuccess($this->withApiHeaders()
            ->actingAsSanctum($checker)
            ->postJson('/api/v1/islamic-compliance-reviews/'.$reviewPublicId.'/review', [
                'decision' => 'approve',
            ]));

        return $productPublicId;
    }

    private function ensureShariaApprover(User $approver): void
    {
        $exists = DB::table('islamic_sharia_authority_members as m')
            ->join('islamic_sharia_authorities as a', 'a.id', '=', 'm.islamic_sharia_authority_id')
            ->where('m.user_id', $approver->id)
            ->where('m.member_role', 'approver')
            ->where('m.status', 'active')
            ->where('a.status', 'active')
            ->exists();
        if ($exists) {
            return;
        }

        $admin = $this->createUserWithRole('platform-admin');
        $authorityRow = DB::table('islamic_sharia_authorities')->where('status', 'active')->orderBy('id')->first(['public_id']);

        if (is_object($authorityRow) && is_string($authorityRow->public_id)) {
            $authorityPublicId = $authorityRow->public_id;
        } else {
            $chair = $this->createUserWithRole('platform-admin');
            $signoffAgency = $this->createAgency('IF-SH-'.Str::upper(Str::random(4)));
            $documentPublicId = (string) Str::ulid();
            DB::table('documents')->insert([
                'public_id' => $documentPublicId,
                'agency_id' => $signoffAgency['id'],
                'uploaded_by_user_id' => $admin->id,
                'category' => 'sharia_authority',
                'title' => 'Sharia mandate evidence',
                'disk' => 'local',
                'path' => 'documents/'.$documentPublicId,
                'original_name' => 'mandate.pdf',
                'mime_type' => 'application/pdf',
                'size_bytes' => 1024,
                'checksum_sha256' => str_repeat('c', 64),
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $created = $this->withApiHeaders()
                ->actingAsSanctum($admin)
                ->postJson('/api/v1/islamic-sharia-authorities', [
                    'name' => 'Test Sharia Board',
                    'authority_type' => 'board',
                    'jurisdiction' => 'institution',
                    'mandate_scope' => ['type' => 'institution'],
                    'mandate_summary' => 'Governs Sharia compliance for tests.',
                    'effective_date' => CarbonImmutable::now()->subDays(2)->toDateString(),
                    'document_public_id' => $documentPublicId,
                ]);
            $this->assertJsonSuccess($created, 201);
            $authorityPublicId = $this->requireStringJsonPath($created, 'data.public_id');

            $this->withApiHeaders()
                ->actingAsSanctum($admin)
                ->postJson('/api/v1/islamic-sharia-authorities/'.$authorityPublicId.'/members', [
                    'user_public_id' => $chair->public_id,
                    'member_role' => 'chair',
                    'starts_on' => CarbonImmutable::now()->subDays(2)->toDateString(),
                ]);
            $this->withApiHeaders()
                ->actingAsSanctum($admin)
                ->postJson('/api/v1/islamic-sharia-authorities/'.$authorityPublicId.'/members', [
                    'user_public_id' => $approver->public_id,
                    'member_role' => 'approver',
                    'starts_on' => CarbonImmutable::now()->subDays(2)->toDateString(),
                ]);
            $this->withApiHeaders()
                ->actingAsSanctum($admin)
                ->postJson('/api/v1/islamic-sharia-authorities/'.$authorityPublicId.'/activate');

            return;
        }

        $this->withApiHeaders()
            ->actingAsSanctum($admin)
            ->postJson('/api/v1/islamic-sharia-authorities/'.$authorityPublicId.'/members', [
                'user_public_id' => $approver->public_id,
                'member_role' => 'approver',
                'starts_on' => CarbonImmutable::now()->subDays(2)->toDateString(),
            ]);
    }

    private function ensureMourabahaBaseline(User $actor): void
    {
        $this->ensureProductFamilyBaseline($actor, 'mourabaha');
    }

    private function ensureProductFamilyBaseline(User $actor, string $productFamily): void
    {
        $existing = DB::table('islamic_standard_links as l')
            ->join('islamic_standards as s', 's.id', '=', 'l.islamic_standard_id')
            ->where('l.linkable_type', 'product_family')
            ->where('l.linkable_code', $productFamily)
            ->where('s.status', 'active')
            ->exists();
        if ($existing) {
            if ($this->isFinancingFamily($productFamily)) {
                $this->ensureApprovedContractTemplate($actor, $productFamily);
            }
            $this->ensureProductFamilySignoff($actor, $productFamily);

            return;
        }

        $baselineAgency = $this->createAgency('IF-BL-'.Str::upper(Str::random(4)));
        $documentPublicId = (string) Str::ulid();
        DB::table('documents')->insert([
            'public_id' => $documentPublicId,
            'agency_id' => $baselineAgency['id'],
            'uploaded_by_user_id' => $actor->id,
            'category' => 'islamic_standard',
            'title' => Str::headline($productFamily).' baseline evidence',
            'disk' => 'local',
            'path' => 'documents/'.$documentPublicId,
            'original_name' => 'standard.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'checksum_sha256' => str_repeat('a', 64),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $created = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-standards', [
                'source' => 'AAOIFI',
                'reference' => 'AAOIFI-SS-'.Str::random(4),
                'title' => Str::headline($productFamily).' baseline',
                'scope_summary' => 'Applies to '.$productFamily.' product family.',
                'owner_type' => 'committee',
                'owner_committee' => 'Sharia Board',
                'effective_date' => CarbonImmutable::now()->subDay()->toDateString(),
                'document_public_id' => $documentPublicId,
            ]);
        $this->assertJsonSuccess($created, 201);
        $publicId = $this->requireStringJsonPath($created, 'data.public_id');

        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-standards/'.$publicId.'/links', [
                'linkable_type' => 'product_family',
                'linkable_code' => $productFamily,
            ]);
        if ($this->isFinancingFamily($productFamily)) {
            $this->withApiHeaders()
                ->actingAsSanctum($actor)
                ->postJson('/api/v1/islamic-standards/'.$publicId.'/links', [
                    'linkable_type' => 'contract_template',
                    'linkable_code' => $productFamily.'_contract_template',
                    'linkable_identifier' => 'reserved_code',
                ]);
        }
        $mappingPublicId = $this->seedIslamicAccountingMappingLinkableCode();
        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-standards/'.$publicId.'/links', [
                'linkable_type' => 'accounting_mapping',
                'linkable_code' => $mappingPublicId,
            ]);

        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-standards/'.$publicId.'/activate');

        if ($this->isFinancingFamily($productFamily)) {
            $this->ensureApprovedContractTemplate($actor, $productFamily);
        }
        $this->ensureProductFamilySignoff($actor, $productFamily);
    }

    private function ensureMourabahaSignoff(User $actor): void
    {
        $this->ensureProductFamilySignoff($actor, 'mourabaha');
    }

    private function ensureProductFamilySignoff(User $actor, string $productFamily): void
    {
        $existing = DB::table('islamic_regulatory_signoff_links as l')
            ->join('islamic_regulatory_signoffs as s', 's.id', '=', 'l.islamic_regulatory_signoff_id')
            ->where('l.linkable_type', 'product_family')
            ->where('l.linkable_code', $productFamily)
            ->where('l.restriction_mode', 'allow')
            ->where('s.status', 'active')
            ->exists();
        if ($existing) {
            return;
        }

        $signoffAgency = $this->createAgency('IF-SO-'.Str::upper(Str::random(4)));
        $documentPublicId = (string) Str::ulid();
        DB::table('documents')->insert([
            'public_id' => $documentPublicId,
            'agency_id' => $signoffAgency['id'],
            'uploaded_by_user_id' => $actor->id,
            'category' => 'regulatory_signoff',
            'title' => Str::headline($productFamily).' sign-off evidence',
            'disk' => 'local',
            'path' => 'documents/'.$documentPublicId,
            'original_name' => 'signoff.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'checksum_sha256' => str_repeat('b', 64),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $created = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-regulatory-signoffs', [
                'jurisdiction' => 'cameroon',
                'regulator' => 'cobac',
                'opinion_reference' => 'COBAC-MEMO-'.Str::random(4),
                'opinion_summary' => 'Authorisation for '.$productFamily.' operations.',
                'approval_type' => 'allow',
                'owner_type' => 'committee',
                'owner_committee' => 'Compliance Board',
                'approved_on' => CarbonImmutable::now()->subDays(2)->toDateString(),
                'effective_date' => CarbonImmutable::now()->subDay()->toDateString(),
                'document_public_id' => $documentPublicId,
            ]);
        $this->assertJsonSuccess($created, 201);
        $publicId = $this->requireStringJsonPath($created, 'data.public_id');

        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-regulatory-signoffs/'.$publicId.'/links', [
                'linkable_type' => 'product_family',
                'linkable_code' => $productFamily,
                'restriction_mode' => 'allow',
            ]);

        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-regulatory-signoffs/'.$publicId.'/activate');
    }

    private function createDraftFinancing(User $actor, int $allowedCosts = 0, int $markup = 200000): string
    {
        $agency = $this->createAgency('IF-'.Str::upper(Str::random(4)));
        $productPublicId = $this->createApprovedProduct($actor, 'MUR-'.Str::ulid(), 'murabaha');
        $clientPublicId = $this->createClient($agency['id']);

        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings', [
                'client_public_id' => $clientPublicId,
                'agency_public_id' => $agency['public_id'],
                'product_public_id' => $productPublicId,
                'contract_type' => 'murabaha',
                'purchase_cost_minor' => 800000,
                'allowed_costs_minor' => $allowedCosts,
                'markup_minor' => $markup,
                'supplier_name' => 'Supplier',
            ]);
        $this->assertJsonSuccess($response, 201);

        return $this->requireStringJsonPath($response, 'data.public_id');
    }

    private function setFinancingContractType(string $financingPublicId, string $contractType): void
    {
        DB::table('islamic_financings')
            ->where('public_id', $financingPublicId)
            ->update([
                'contract_type' => $contractType,
                'updated_at' => now(),
            ]);
    }

    private function getFinancingAgencyId(string $financingPublicId): int
    {
        $row = DB::table('islamic_financings')->where('public_id', $financingPublicId)->first(['agency_id']);

        self::assertIsObject($row);

        return (int) $row->agency_id;
    }

    private function getFinancingAgencyPublicId(string $financingPublicId): string
    {
        $row = DB::table('islamic_financings as f')
            ->join('agencies as a', 'a.id', '=', 'f.agency_id')
            ->where('f.public_id', $financingPublicId)
            ->first(['a.public_id']);
        self::assertIsObject($row);
        self::assertIsString($row->public_id);

        return $row->public_id;
    }

    private function setupMourabahaOriginationChain(User $actor, string $financingPublicId, string $controlStatus = 'owned_by_institution'): void
    {
        $requestPublicId = $this->createMourabahaRequestForFinancing($actor, $financingPublicId);
        $quotePublicId = $this->createMourabahaQuoteForRequest($actor, $requestPublicId);
        $this->approveMourabahaRequestQuote($actor, $requestPublicId, $quotePublicId);

        $purchaseEvidence = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/purchase-evidence', [
                'mourabaha_request_public_id' => $requestPublicId,
                'evidence_type' => 'supplier_invoice',
                'institution_control_status' => $controlStatus,
            ]);
        $this->assertJsonSuccess($purchaseEvidence, 201);

        $costEvidence = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-financings/'.$financingPublicId.'/cost-evidence', [
                'cost_type' => 'purchase_cost',
                'amount_minor' => 800000,
            ]);
        $this->assertJsonSuccess($costEvidence, 201);
    }

    private function createMourabahaRequestForFinancing(User $actor, string $financingPublicId): string
    {
        $financing = DB::table('islamic_financings as f')
            ->join('clients as c', 'c.id', '=', 'f.client_id')
            ->join('agencies as a', 'a.id', '=', 'f.agency_id')
            ->join('islamic_products as p', 'p.id', '=', 'f.islamic_product_id')
            ->where('f.public_id', $financingPublicId)
            ->first([
                'c.public_id as client_public_id',
                'a.public_id as agency_public_id',
                'p.public_id as product_public_id',
            ]);
        self::assertIsObject($financing);

        $request = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-mourabaha-requests', [
                'client_public_id' => (string) $financing->client_public_id,
                'agency_public_id' => (string) $financing->agency_public_id,
                'product_public_id' => (string) $financing->product_public_id,
                'financing_public_id' => $financingPublicId,
                'asset_type' => 'vehicle',
                'asset_description' => 'Toyota Hilux',
                'supplier_name' => 'Supplier SARL',
                'requested_purchase_cost_minor' => 800000,
            ]);
        $this->assertJsonSuccess($request, 201);

        return $this->requireStringJsonPath($request, 'data.public_id');
    }

    private function createMourabahaQuoteForRequest(User $actor, string $requestPublicId): string
    {
        $quote = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-mourabaha-requests/'.$requestPublicId.'/quotes', [
                'supplier_name' => 'Supplier SARL',
                'quoted_purchase_cost_minor' => 800000,
                'quoted_allowed_costs_minor' => 0,
                'currency' => 'XAF',
                'valid_until' => now()->addDays(10)->toDateString(),
                'is_selected' => true,
            ]);
        $this->assertJsonSuccess($quote, 201);

        return $this->requireStringJsonPath($quote, 'data.public_id');
    }

    private function approveMourabahaRequestQuote(User $actor, string $requestPublicId, string $quotePublicId): void
    {
        $approve = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-mourabaha-requests/'.$requestPublicId.'/purchase-approval', [
                'supplier_quote_public_id' => $quotePublicId,
                'decision' => 'approved',
            ]);
        $this->assertJsonSuccess($approve, 201);
    }

    private function seedMurabahaMappings(User $actor, int $agencyId, ?int $wrongAgencyId = null): void
    {
        $codes = [
            'murabaha_receivable' => 'debit',
            'murabaha_payable' => 'credit',
            'murabaha_profit' => 'credit',
        ];
        foreach ($codes as $code => $side) {
            $ledgerId = $this->seedLedger($agencyId);
            $opCodeId = DB::table('operation_codes')->insertGetId([
                'public_id' => (string) Str::ulid(),
                'code' => $code,
                'label' => str_replace('_', ' ', $code),
                'module' => 'islamic_finance',
                'operation_type' => 'murabaha',
                'direction' => 'mixed',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            if ($wrongAgencyId !== null) {
                $wrongLedgerId = $this->seedLedger($wrongAgencyId);
                DB::table('operation_account_mappings')->insert([
                    'public_id' => $wrongMappingPublicId = (string) Str::ulid(),
                    'operation_code_id' => $opCodeId,
                    'agency_id' => $wrongAgencyId,
                    'debit_ledger_account_id' => $side === 'debit' ? $wrongLedgerId : null,
                    'credit_ledger_account_id' => $side === 'credit' ? $wrongLedgerId : null,
                    'currency' => null,
                    'effective_from' => now()->subDay()->toDateString(),
                    'effective_to' => null,
                    'status' => 'active',
                    'approval_status' => 'approved',
                    'accounting_owner_user_id' => $actor->id,
                    'sharia_approval_required' => false,
                    'sharia_approval_status' => 'not_required',
                    'approved_by_user_id' => $actor->id,
                    'approved_at' => now(),
                    'rules' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->seedApprovedMappingWorkflowRow($wrongMappingPublicId, $actor->id);
            }
            DB::table('operation_account_mappings')->insert([
                'public_id' => $mappingPublicId = (string) Str::ulid(),
                'operation_code_id' => $opCodeId,
                'agency_id' => $agencyId,
                'debit_ledger_account_id' => $side === 'debit' ? $ledgerId : null,
                'credit_ledger_account_id' => $side === 'credit' ? $ledgerId : null,
                'currency' => null,
                'effective_from' => now()->subDay()->toDateString(),
                'effective_to' => null,
                'status' => 'active',
                'approval_status' => 'approved',
                'accounting_owner_user_id' => $actor->id,
                'sharia_approval_required' => false,
                'sharia_approval_status' => 'not_required',
                'approved_by_user_id' => $actor->id,
                'approved_at' => now(),
                'rules' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->seedApprovedMappingWorkflowRow($mappingPublicId, $actor->id);
        }
    }

    private function seedMurabahaCollectionMapping(User $actor, int $agencyId, string $operationCode): void
    {
        $opCodeId = DB::table('operation_codes')
            ->where('module', 'islamic_finance')
            ->where('code', $operationCode)
            ->value('id');
        if (! is_numeric($opCodeId)) {
            $opCodeId = DB::table('operation_codes')->insertGetId([
                'public_id' => (string) Str::ulid(),
                'code' => $operationCode,
                'label' => str_replace('_', ' ', $operationCode),
                'module' => 'islamic_finance',
                'operation_type' => 'murabaha',
                'direction' => 'mixed',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $debitLedgerId = $this->seedLedger($agencyId);
        $creditLedgerId = $this->seedLedger($agencyId);
        $mappingPublicId = (string) Str::ulid();

        DB::table('operation_account_mappings')->insert([
            'public_id' => $mappingPublicId,
            'operation_code_id' => (int) $opCodeId,
            'agency_id' => $agencyId,
            'debit_ledger_account_id' => $debitLedgerId,
            'credit_ledger_account_id' => $creditLedgerId,
            'currency' => null,
            'effective_from' => now()->subDay()->toDateString(),
            'effective_to' => null,
            'status' => 'active',
            'approval_status' => 'approved',
            'accounting_owner_user_id' => $actor->id,
            'sharia_approval_required' => false,
            'sharia_approval_status' => 'not_required',
            'approved_by_user_id' => $actor->id,
            'approved_at' => now(),
            'rules' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->seedApprovedMappingWorkflowRow($mappingPublicId, $actor->id);
    }

    private function seedIjaraMappings(User $actor, int $agencyId): void
    {
        $codes = [
            'ijara_rental_receivable' => 'debit',
            'ijara_rental_income' => 'credit',
            'ijara_termination_adjustment' => 'credit',
            'ijara_residual_transfer' => 'credit',
            'ijara_zero_residual_transfer' => 'credit',
        ];
        foreach ($codes as $code => $side) {
            $opCodeId = DB::table('operation_codes')
                ->where('module', 'islamic_finance')
                ->where('code', $code)
                ->value('id');
            if (! is_numeric($opCodeId)) {
                $opCodeId = DB::table('operation_codes')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'code' => $code,
                    'label' => str_replace('_', ' ', $code),
                    'module' => 'islamic_finance',
                    'operation_type' => 'ijara',
                    'direction' => 'mixed',
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $debitLedgerId = $this->seedLedger($agencyId);
            $creditLedgerId = $this->seedLedger($agencyId);
            $mappingPublicId = (string) Str::ulid();

            DB::table('operation_account_mappings')->insert([
                'public_id' => $mappingPublicId,
                'operation_code_id' => (int) $opCodeId,
                'agency_id' => $agencyId,
                'debit_ledger_account_id' => $side === 'debit' ? $debitLedgerId : null,
                'credit_ledger_account_id' => $side === 'credit' ? $creditLedgerId : null,
                'currency' => null,
                'effective_from' => now()->subDay()->toDateString(),
                'effective_to' => null,
                'status' => 'active',
                'approval_status' => 'approved',
                'accounting_owner_user_id' => $actor->id,
                'sharia_approval_required' => false,
                'sharia_approval_status' => 'not_required',
                'approved_by_user_id' => $actor->id,
                'approved_at' => now(),
                'rules' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->seedApprovedMappingWorkflowRow($mappingPublicId, $actor->id);
        }
    }

    private function seedSalamMappings(User $actor, int $agencyId): void
    {
        $codes = ['salam_upfront_payment'];
        foreach ($codes as $code) {
            $opCodeId = DB::table('operation_codes')
                ->where('module', 'islamic_finance')
                ->where('code', $code)
                ->value('id');
            if (! is_numeric($opCodeId)) {
                $opCodeId = DB::table('operation_codes')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'code' => $code,
                    'label' => str_replace('_', ' ', $code),
                    'module' => 'islamic_finance',
                    'operation_type' => 'salam',
                    'direction' => 'mixed',
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $debitLedgerId = $this->seedLedger($agencyId);
            $creditLedgerId = $this->seedLedger($agencyId);
            $mappingPublicId = (string) Str::ulid();

            DB::table('operation_account_mappings')->insert([
                'public_id' => $mappingPublicId,
                'operation_code_id' => (int) $opCodeId,
                'agency_id' => $agencyId,
                'debit_ledger_account_id' => $debitLedgerId,
                'credit_ledger_account_id' => $creditLedgerId,
                'currency' => null,
                'effective_from' => now()->subDay()->toDateString(),
                'effective_to' => null,
                'status' => 'active',
                'approval_status' => 'approved',
                'accounting_owner_user_id' => $actor->id,
                'sharia_approval_required' => false,
                'sharia_approval_status' => 'not_required',
                'approved_by_user_id' => $actor->id,
                'approved_at' => now(),
                'rules' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->seedApprovedMappingWorkflowRow($mappingPublicId, $actor->id);
        }
    }

    private function seedLedger(int $agencyId): int
    {
        return DB::table('ledger_accounts')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agencyId,
            'code' => 'IFL-'.Str::ulid(),
            'name' => 'Islamic Ledger',
            'account_class' => LedgerAccount::ACCOUNT_CLASS_ASSET,
            'normal_balance_side' => LedgerAccount::NORMAL_BALANCE_DEBIT,
            'status' => LedgerAccount::STATUS_ACTIVE,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedMourabahaBaselineWithoutTemplate(
        User $actor,
        bool $withAccountingMapping,
        bool $withContractTemplate = false,
    ): void {
        $baselineAgency = $this->createAgency('IF-BL-'.Str::upper(Str::random(4)));
        $documentPublicId = (string) Str::ulid();
        DB::table('documents')->insert([
            'public_id' => $documentPublicId,
            'agency_id' => $baselineAgency['id'],
            'uploaded_by_user_id' => $actor->id,
            'category' => 'islamic_standard',
            'title' => 'Mourabaha baseline evidence',
            'disk' => 'local',
            'path' => 'documents/'.$documentPublicId,
            'original_name' => 'standard.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'checksum_sha256' => str_repeat('a', 64),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $created = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-standards', [
                'source' => 'AAOIFI',
                'reference' => 'AAOIFI-SS-'.Str::random(4),
                'title' => 'Mourabaha baseline',
                'scope_summary' => 'Applies to mourabaha product family.',
                'owner_type' => 'committee',
                'owner_committee' => 'Sharia Board',
                'effective_date' => CarbonImmutable::now()->subDay()->toDateString(),
                'document_public_id' => $documentPublicId,
            ]);
        $this->assertJsonSuccess($created, 201);
        $publicId = $this->requireStringJsonPath($created, 'data.public_id');

        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-standards/'.$publicId.'/links', [
                'linkable_type' => 'product_family',
                'linkable_code' => 'mourabaha',
            ]);
        if ($withContractTemplate) {
            $this->withApiHeaders()
                ->actingAsSanctum($actor)
                ->postJson('/api/v1/islamic-standards/'.$publicId.'/links', [
                    'linkable_type' => 'contract_template',
                    'linkable_code' => 'mourabaha_contract_template',
                    'linkable_identifier' => 'reserved_code',
                ]);
        }
        if ($withAccountingMapping) {
            $mappingPublicId = $this->seedIslamicAccountingMappingLinkableCode();
            $this->withApiHeaders()
                ->actingAsSanctum($actor)
                ->postJson('/api/v1/islamic-standards/'.$publicId.'/links', [
                    'linkable_type' => 'accounting_mapping',
                    'linkable_code' => $mappingPublicId,
                ]);
        }

        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-standards/'.$publicId.'/activate');
    }

    private function isFinancingFamily(string $productFamily): bool
    {
        return in_array($productFamily, ['mourabaha', 'ijara', 'ijara_wa_iqtina', 'salam', 'istisnaa', 'moudaraba', 'moucharaka'], true);
    }

    private function seedIslamicAccountingMappingLinkableCode(): string
    {
        $opCodeId = DB::table('operation_codes')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'code' => 'if_map_'.Str::ulid(),
            'label' => 'Islamic readiness mapping',
            'module' => 'islamic_finance',
            'operation_type' => null,
            'direction' => null,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $publicId = (string) Str::ulid();
        DB::table('operation_account_mappings')->insert([
            'public_id' => $publicId,
            'operation_code_id' => $opCodeId,
            'agency_id' => null,
            'debit_ledger_account_id' => null,
            'credit_ledger_account_id' => null,
            'currency' => null,
            'effective_from' => now()->subDay()->toDateString(),
            'effective_to' => null,
            'status' => 'active',
            'approval_status' => 'approved',
            'accounting_owner_user_id' => null,
            'sharia_approval_required' => false,
            'sharia_approval_status' => 'not_required',
            'approved_by_user_id' => null,
            'approved_at' => now(),
            'rules' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actorId = DB::table('users')->orderBy('id')->value('id');
        if (is_numeric($actorId)) {
            $this->seedApprovedMappingWorkflowRow($publicId, (int) $actorId);
        }

        return $publicId;
    }

    private function seedApprovedMappingWorkflowRow(string $mappingPublicId, int $actorUserId): void
    {
        $exists = DB::table('islamic_approval_workflows')
            ->where('subject_type', 'islamic_mapping')
            ->where('subject_public_id', $mappingPublicId)
            ->exists();
        if ($exists) {
            return;
        }

        DB::table('islamic_approval_workflows')->insert([
            'public_id' => (string) Str::ulid(),
            'subject_type' => 'islamic_mapping',
            'subject_public_id' => $mappingPublicId,
            'current_state' => 'approved',
            'effective_from' => now()->subDay()->toDateString(),
            'effective_to' => null,
            'is_blocking' => true,
            'version' => 1,
            'created_by_user_id' => $actorUserId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function ensureApprovedContractTemplate(User $actor, string $familyCode): void
    {
        $existing = DB::table('islamic_contract_templates')
            ->where('family_code', $familyCode)
            ->where('status', 'approved')
            ->where('effective_from', '<=', now()->toDateString())
            ->where(function ($q): void {
                $q->whereNull('effective_to')->orWhere('effective_to', '>', now()->toDateString());
            })
            ->exists();
        if ($existing) {
            return;
        }

        $agency = $this->createAgency('IF-TPL-'.Str::upper(Str::random(4)));
        $this->createApprovedTemplate(
            actor: $actor,
            familyCode: $familyCode,
            templateCode: $familyCode.'_contract_template',
            effectiveFrom: now()->subDay()->toDateString(),
            effectiveTo: null,
            agencyId: $agency['id'],
        );
    }

    private function createApprovedTemplate(
        User $actor,
        string $familyCode,
        string $templateCode,
        string $effectiveFrom,
        ?string $effectiveTo,
        int $agencyId,
        int $version = 1,
    ): string {
        $documentPublicId = (string) Str::ulid();
        DB::table('documents')->insert([
            'public_id' => $documentPublicId,
            'agency_id' => $agencyId,
            'uploaded_by_user_id' => $actor->id,
            'category' => 'contract_template',
            'title' => Str::headline($familyCode).' contract template evidence',
            'disk' => 'local',
            'path' => 'documents/'.$documentPublicId,
            'original_name' => 'template.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 2048,
            'checksum_sha256' => str_repeat('d', 64),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $create = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-contract-templates', [
                'family_code' => $familyCode,
                'language_code' => 'fr',
                'template_code' => $templateCode,
                'version' => $version,
                'effective_from' => $effectiveFrom,
                'effective_to' => $effectiveTo,
                'document_public_id' => $documentPublicId,
                'legal_signoff_ref' => 'LEGAL-'.$familyCode,
                'sharia_signoff_ref' => 'SHARIA-'.$familyCode,
                'fields_schema' => ['required' => ['client_public_id', 'agency_public_id']],
                'commercial_terms_schema' => ['required' => ['purchase_cost_minor', 'markup_minor']],
            ]);
        $this->assertJsonSuccess($create, 201);
        $templatePublicId = $this->requireStringJsonPath($create, 'data.public_id');

        $submit = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-contract-templates/'.$templatePublicId.'/submit');
        $this->assertJsonSuccess($submit);

        $approve = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-contract-templates/'.$templatePublicId.'/approve');
        $this->assertJsonSuccess($approve);

        return $templatePublicId;
    }

    /**
     * @param array{
     *   zakat_enabled?: bool,
     *   charity_treatment_enabled?: bool,
     *   non_compliant_income_treatment_enabled?: bool,
     *   purification_mode?: string|null,
     *   required_operation_codes?: array<string, string>,
     *   effective_from?: string,
     *   effective_to?: string|null
     * } $overrides
     */
    private function createApprovedTreatmentPolicy(User $actor, string $agencyPublicId, array $overrides = []): string
    {
        $createPayload = [
            'policy_code' => 'TP-'.Str::upper(Str::random(6)),
            'version' => 1,
            'scope_type' => 'agency',
            'agency_public_id' => $agencyPublicId,
            'zakat_enabled' => $overrides['zakat_enabled'] ?? false,
            'charity_treatment_enabled' => $overrides['charity_treatment_enabled'] ?? false,
            'non_compliant_income_treatment_enabled' => $overrides['non_compliant_income_treatment_enabled'] ?? false,
            'purification_mode' => $overrides['purification_mode'] ?? null,
            'required_operation_codes' => $overrides['required_operation_codes'] ?? [],
            'effective_from' => is_string($overrides['effective_from'] ?? null) ? $overrides['effective_from'] : now()->subDay()->toDateString(),
            'effective_to' => array_key_exists('effective_to', $overrides) ? $overrides['effective_to'] : null,
        ];

        $create = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-treatment-policies', $createPayload);
        $this->assertJsonSuccess($create, 201);
        $policyPublicId = $this->requireStringJsonPath($create, 'data.public_id');

        $approve = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-treatment-policies/'.$policyPublicId.'/approve');
        $this->assertJsonSuccess($approve);

        return $policyPublicId;
    }

    private function createApprovedTreatmentMapping(User $actor, int $agencyId, string $operationCode): void
    {
        $opCodeId = DB::table('operation_codes')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'code' => $operationCode,
            'label' => 'Treatment '.str_replace('_', ' ', $operationCode),
            'module' => 'islamic_finance',
            'operation_type' => 'treatment',
            'direction' => 'mixed',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $debitLedgerId = $this->seedLedger($agencyId);
        $creditLedgerId = $this->seedLedger($agencyId);
        $mappingPublicId = (string) Str::ulid();

        DB::table('operation_account_mappings')->insert([
            'public_id' => $mappingPublicId,
            'operation_code_id' => $opCodeId,
            'agency_id' => $agencyId,
            'debit_ledger_account_id' => $debitLedgerId,
            'credit_ledger_account_id' => $creditLedgerId,
            'currency' => 'XAF',
            'effective_from' => now()->subDay()->toDateString(),
            'effective_to' => null,
            'status' => 'active',
            'approval_status' => 'approved',
            'accounting_owner_user_id' => $actor->id,
            'sharia_approval_required' => false,
            'sharia_approval_status' => 'not_required',
            'approved_by_user_id' => $actor->id,
            'approved_at' => now(),
            'rules' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('islamic_approval_workflows')->insert([
            'public_id' => (string) Str::ulid(),
            'subject_type' => 'islamic_mapping',
            'subject_public_id' => $mappingPublicId,
            'current_state' => 'approved',
            'effective_from' => now()->subDay()->toDateString(),
            'effective_to' => null,
            'is_blocking' => true,
            'version' => 1,
            'created_by_user_id' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
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

    private function createClient(int $agencyId): string
    {
        $publicId = (string) Str::ulid();
        DB::table('clients')->insert([
            'public_id' => $publicId,
            'agency_id' => $agencyId,
            'first_name' => 'Client',
            'last_name' => 'Test',
            'client_reference' => 'REF-'.Str::ulid(),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $publicId;
    }

    private function createEvidenceDocument(User $actor): string
    {
        $agency = $this->createAgency('IF-EVD-'.Str::upper(Str::random(6)));
        $publicId = (string) Str::ulid();
        DB::table('documents')->insert([
            'public_id' => $publicId,
            'agency_id' => $agency['id'],
            'uploaded_by_user_id' => $actor->id,
            'category' => 'islamic_compliance',
            'title' => 'Compliance evidence',
            'disk' => 'local',
            'path' => 'documents/'.$publicId,
            'original_name' => 'compliance-evidence.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'checksum_sha256' => str_repeat('e', 64),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $publicId;
    }

    private function requireStringJsonPath(TestResponse $response, string $path): string
    {
        $value = $response->json($path);
        self::assertIsString($value);

        return $value;
    }

    private function asString(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private function asInt(mixed $value): int
    {
        return is_int($value) ? $value : 0;
    }

    private function insertComplianceCaseWithBlocker(
        int $userId,
        string $productPublicId,
        string $blockerType,
        string $status,
        ?string $latestDecision,
        ?string $effectiveTo,
        ?string $dueAt = null,
    ): string {
        $casePublicId = (string) Str::ulid();
        $caseId = DB::table('islamic_compliance_cases')->insertGetId([
            'public_id' => $casePublicId,
            'subject_type' => 'islamic_product',
            'subject_public_id' => $productPublicId,
            'reason_code' => 'manual_screening',
            'risk_level' => 'high',
            'checklist_version' => 'v1',
            'status' => $status,
            'blocking_mode' => 'hard',
            'latest_decision' => $latestDecision,
            'latest_decided_at' => now(),
            'created_by_user_id' => $userId,
            'due_at' => $dueAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('islamic_compliance_case_blockers')->insert([
            'public_id' => (string) Str::ulid(),
            'case_id' => $caseId,
            'blocker_type' => $blockerType,
            'target_subject_type' => 'islamic_product',
            'target_subject_public_id' => $productPublicId,
            'is_active' => true,
            'activated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($latestDecision !== null) {
            DB::table('islamic_compliance_case_decisions')->insert([
                'public_id' => (string) Str::ulid(),
                'case_id' => $caseId,
                'decision' => $latestDecision,
                'decision_comments' => null,
                'conditions' => $latestDecision === 'conditionally_approved'
                    ? json_encode(['expires_on' => CarbonImmutable::now()->subDay()->toDateString()], JSON_THROW_ON_ERROR)
                    : null,
                'evidence_document_id' => null,
                'decided_by_user_id' => $userId,
                'decided_at' => now(),
                'effective_from' => CarbonImmutable::now()->subDays(5)->toDateTimeString(),
                'effective_to' => $effectiveTo,
                'metadata' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $casePublicId;
    }

    /** @return array{public_id:string} */
    private function seedActiveScreeningPolicyRule(
        string $ruleType,
        string $matchKey,
        string $action,
        int $version = 1,
        string $scopeType = 'product_family',
        ?string $scopeValue = 'mourabaha',
    ): array {
        $user = $this->createUserWithRole('platform-admin');
        $policyPublicId = (string) Str::ulid();
        $policyId = DB::table('islamic_screening_policies')->insertGetId([
            'public_id' => $policyPublicId,
            'code' => 'SP-'.Str::upper(Str::random(6)),
            'name' => 'Policy '.Str::upper(Str::random(4)),
            'version' => $version,
            'scope_type' => $scopeType,
            'scope_value' => $scopeValue,
            'status' => 'active',
            'effective_from' => now()->subDay()->toDateString(),
            'effective_to' => null,
            'description' => 'seeded',
            'document_id' => null,
            'created_by_user_id' => $user->id,
            'metadata' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('islamic_screening_policy_rules')->insert([
            'public_id' => (string) Str::ulid(),
            'policy_id' => $policyId,
            'rule_type' => $ruleType,
            'match_key' => $matchKey,
            'match_operator' => 'equals',
            'risk_level' => 'high',
            'action' => $action,
            'priority' => 10,
            'is_active' => true,
            'metadata' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['public_id' => $policyPublicId];
    }
}
