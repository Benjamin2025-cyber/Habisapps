<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

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

        app(\App\Application\IslamicFinance\IslamicComplianceCaseService::class)->recordDecision(
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
        app(\App\Application\IslamicFinance\IslamicComplianceCaseService::class)->recordDecision(
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
        self::assertGreaterThanOrEqual(1, (int) $summary->json('data.active_blockers'));

        $timeline = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->getJson('/api/v1/islamic-compliance-cases/'.$casePublicId.'/timeline');
        $this->assertJsonSuccess($timeline);
        self::assertIsArray($timeline->json('data'));
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
                'rules' => [
                    'sector_codes' => ['gambling'],
                ],
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
                'rules' => [
                    'sector_codes' => ['oil'],
                ],
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
        self::assertSame((string) $policy['public_id'], (string) $result->policy_public_id);
        self::assertIsString($result->policy_snapshot);
        self::assertStringContainsString('"version":7', (string) $result->policy_snapshot);
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
        $workflow = app(\App\Application\IslamicFinance\IslamicApprovalWorkflowService::class);
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

    public function test_financing_approval_posts_journal_with_correct_lines(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $financingPublicId = $this->createDraftFinancing($actor);
        $agencyId = $this->getFinancingAgencyId($financingPublicId);
        $otherAgency = $this->createAgency('IF-MAP2');

        $this->seedMurabahaMappings($agencyId, wrongAgencyId: $otherAgency['id']);

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
        $this->seedMurabahaMappings($agencyId);

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

    private function createProduct(User $actor, string $code, string $contractType, ?string $agencyPublicId = null): string
    {
        $payload = [
            'code' => $code,
            'name' => 'Product '.$code,
            'contract_type' => $contractType,
        ];
        if ($agencyPublicId !== null) {
            $payload['agency_public_id'] = $agencyPublicId;
        }

        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-products', $payload);
        $this->assertJsonSuccess($response, 201);

        return $this->requireStringJsonPath($response, 'data.public_id');
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
        $existing = DB::table('islamic_standard_links as l')
            ->join('islamic_standards as s', 's.id', '=', 'l.islamic_standard_id')
            ->where('l.linkable_type', 'product_family')
            ->where('l.linkable_code', 'mourabaha')
            ->where('s.status', 'active')
            ->exists();
        if ($existing) {
            return;
        }

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
                'title' => 'Murabaha baseline',
                'scope_summary' => 'Applies to Mourabaha product family.',
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

        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-standards/'.$publicId.'/activate');

        $this->ensureMourabahaSignoff($actor);
    }

    private function ensureMourabahaSignoff(User $actor): void
    {
        $existing = DB::table('islamic_regulatory_signoff_links as l')
            ->join('islamic_regulatory_signoffs as s', 's.id', '=', 'l.islamic_regulatory_signoff_id')
            ->where('l.linkable_type', 'product_family')
            ->where('l.linkable_code', 'mourabaha')
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
            'title' => 'Mourabaha sign-off evidence',
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
                'opinion_summary' => 'Authorisation for Mourabaha operations.',
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
                'linkable_code' => 'mourabaha',
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

    private function getFinancingAgencyId(string $financingPublicId): int
    {
        $row = DB::table('islamic_financings')->where('public_id', $financingPublicId)->first(['agency_id']);

        self::assertIsObject($row);

        return (int) $row->agency_id;
    }

    private function seedMurabahaMappings(int $agencyId, ?int $wrongAgencyId = null): void
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
                    'public_id' => (string) Str::ulid(),
                    'operation_code_id' => $opCodeId,
                    'debit_ledger_account_id' => $side === 'debit' ? $wrongLedgerId : null,
                    'credit_ledger_account_id' => $side === 'credit' ? $wrongLedgerId : null,
                    'currency' => null,
                    'status' => 'active',
                    'rules' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            DB::table('operation_account_mappings')->insert([
                'public_id' => (string) Str::ulid(),
                'operation_code_id' => $opCodeId,
                'debit_ledger_account_id' => $side === 'debit' ? $ledgerId : null,
                'credit_ledger_account_id' => $side === 'credit' ? $ledgerId : null,
                'currency' => null,
                'status' => 'active',
                'rules' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
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

    private function requireStringJsonPath(TestResponse $response, string $path): string
    {
        $value = $response->json($path);
        self::assertIsString($value);

        return $value;
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
    ): array
    {
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
