<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Application\IslamicFinance\IslamicApprovalStateMachine;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class IslamicApprovalWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_draft_product_cannot_originate_contract(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $productPublicId = $this->createProduct($actor, 'MUR-DRAFT');
        $agency = $this->createAgency('IF-D1');
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
        $this->assertDatabaseHas('islamic_approval_workflows', [
            'subject_type' => IslamicApprovalStateMachine::SUBJECT_PRODUCT,
            'subject_public_id' => $productPublicId,
            'current_state' => IslamicApprovalStateMachine::STATE_DRAFT,
        ]);
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'security',
            'event' => 'islamic.approval.use_blocked',
        ]);
    }

    public function test_submitted_product_cannot_originate_contract(): void
    {
        $maker = $this->createUserWithRole('platform-admin');
        $productPublicId = $this->createProduct($maker, 'MUR-SUB');

        $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/islamic-products/'.$productPublicId.'/compliance-reviews');

        $agency = $this->createAgency('IF-S1');
        $clientPublicId = $this->createClient($agency['id']);

        $response = $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/islamic-financings', [
                'client_public_id' => $clientPublicId,
                'agency_public_id' => $agency['public_id'],
                'product_public_id' => $productPublicId,
                'contract_type' => 'murabaha',
                'purchase_cost_minor' => 800000,
                'markup_minor' => 200000,
            ]);
        $this->assertJsonError($response, 422);
        $this->assertDatabaseHas('islamic_approval_workflows', [
            'subject_type' => IslamicApprovalStateMachine::SUBJECT_PRODUCT,
            'subject_public_id' => $productPublicId,
            'current_state' => IslamicApprovalStateMachine::STATE_SUBMITTED,
        ]);
    }

    public function test_invalid_transition_is_rejected(): void
    {
        $admin = $this->createUserWithRole('platform-admin');
        $productPublicId = $this->createProduct($admin, 'MUR-INV');

        $response = $this->withApiHeaders()
            ->actingAsSanctum($admin)
            ->postJson('/api/v1/islamic-approval-workflows/islamic_product/'.$productPublicId.'/revoke', [
                'reason' => 'should not work',
            ]);
        $this->assertJsonError($response, 422);
    }

    public function test_unknown_subject_type_is_rejected(): void
    {
        $admin = $this->createUserWithRole('platform-admin');

        $response = $this->withApiHeaders()
            ->actingAsSanctum($admin)
            ->postJson('/api/v1/islamic-approval-workflows/not_a_real_subject/some-id/submit');
        $this->assertJsonError($response, 422);
    }

    public function test_self_approval_on_material_subject_is_rejected(): void
    {
        $admin = $this->createUserWithRole('platform-admin');
        $productPublicId = $this->createProduct($admin, 'MUR-SELF');

        // submit so it can be approved
        $this->withApiHeaders()
            ->actingAsSanctum($admin)
            ->postJson('/api/v1/islamic-products/'.$productPublicId.'/compliance-reviews');

        // Attempt direct approve via workflow endpoint with self as requester => self-approval blocked.
        $response = $this->withApiHeaders()
            ->actingAsSanctum($admin)
            ->postJson('/api/v1/islamic-approval-workflows/islamic_product/'.$productPublicId.'/approve', [
                'requester_user_public_id' => $admin->public_id,
            ]);
        $this->assertJsonError($response, 422);
        $errors = $response->json('errors.islamic_sharia_authority');
        self::assertIsArray($errors);
        self::assertNotEmpty($errors);
    }

    public function test_workflow_records_immutable_decision_log(): void
    {
        $admin = $this->createUserWithRole('platform-admin');
        $productPublicId = $this->createProduct($admin, 'MUR-LOG');

        $this->withApiHeaders()
            ->actingAsSanctum($admin)
            ->postJson('/api/v1/islamic-products/'.$productPublicId.'/compliance-reviews');

        $workflowId = DB::table('islamic_approval_workflows')
            ->where('subject_type', IslamicApprovalStateMachine::SUBJECT_PRODUCT)
            ->where('subject_public_id', $productPublicId)
            ->value('id');
        self::assertIsNumeric($workflowId);

        $decisions = DB::table('islamic_approval_decisions')
            ->where('workflow_id', $workflowId)
            ->orderBy('id')
            ->get();
        self::assertCount(1, $decisions);
        $first = $decisions->first();
        self::assertIsObject($first);
        self::assertSame(IslamicApprovalStateMachine::DECISION_SUBMIT, $first->decision);
        self::assertSame(IslamicApprovalStateMachine::STATE_DRAFT, $first->from_state);
        self::assertSame(IslamicApprovalStateMachine::STATE_SUBMITTED, $first->to_state);
    }

    public function test_conditional_approval_without_expiry_is_rejected(): void
    {
        $admin = $this->createUserWithRole('platform-admin');
        $productPublicId = $this->createProduct($admin, 'MUR-COND');
        $this->withApiHeaders()
            ->actingAsSanctum($admin)
            ->postJson('/api/v1/islamic-products/'.$productPublicId.'/compliance-reviews');

        // Set up authority and approver so the IF-010 check passes when not self-approving.
        $approver = $this->createUserWithRole('platform-admin');
        $this->createActiveAuthority($admin, [
            ['user' => $approver, 'role' => 'approver'],
            ['user' => $this->createUserWithRole('platform-admin'), 'role' => 'chair'],
        ]);

        $response = $this->withApiHeaders()
            ->actingAsSanctum($approver)
            ->postJson('/api/v1/islamic-approval-workflows/islamic_product/'.$productPublicId.'/approve', [
                'conditions' => ['required_controls' => ['quarterly_review']],
                'requester_user_public_id' => $admin->public_id,
            ]);
        $this->assertJsonError($response, 422);
    }

    public function test_conditional_approval_with_past_expiry_blocks_usability(): void
    {
        $admin = $this->createUserWithRole('platform-admin');
        $approver = $this->createUserWithRole('platform-admin');
        $this->createActiveAuthority($admin, [
            ['user' => $approver, 'role' => 'approver'],
            ['user' => $this->createUserWithRole('platform-admin'), 'role' => 'chair'],
        ]);

        $productPublicId = $this->createProduct($admin, 'MUR-PASTEXP');
        $this->withApiHeaders()
            ->actingAsSanctum($admin)
            ->postJson('/api/v1/islamic-products/'.$productPublicId.'/compliance-reviews');

        $approve = $this->withApiHeaders()
            ->actingAsSanctum($approver)
            ->postJson('/api/v1/islamic-approval-workflows/islamic_product/'.$productPublicId.'/approve', [
                'effective_to' => CarbonImmutable::now()->subDay()->toDateString(),
                'requester_user_public_id' => $admin->public_id,
            ]);
        $this->assertJsonSuccess($approve);

        $show = $this->withApiHeaders()
            ->actingAsSanctum($admin)
            ->getJson('/api/v1/islamic-approval-workflows/islamic_product/'.$productPublicId);
        $this->assertJsonSuccess($show);
        $ok = $show->json('data.usability.ok');
        self::assertFalse($ok);
    }

    public function test_suspended_product_cannot_be_used(): void
    {
        $maker = $this->createUserWithRole('platform-admin');
        $checker = $this->createUserWithRole('platform-admin');
        $this->ensureMourabahaBaseline($maker);
        $this->createActiveAuthority($maker, [
            ['user' => $checker, 'role' => 'approver'],
            ['user' => $this->createUserWithRole('platform-admin'), 'role' => 'chair'],
        ]);
        $productPublicId = $this->createApprovedProduct($maker, $checker, 'MUR-SUSP');

        $suspend = $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/islamic-approval-workflows/islamic_product/'.$productPublicId.'/suspend', [
                'comments' => 'compliance issue',
            ]);
        $this->assertJsonSuccess($suspend);
        $suspend->assertJsonPath('data.current_state', IslamicApprovalStateMachine::STATE_SUSPENDED);

        $agency = $this->createAgency('IF-SUS');
        $clientPublicId = $this->createClient($agency['id']);
        $response = $this->withApiHeaders()
            ->actingAsSanctum($maker)
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

    public function test_revoked_product_blocks_new_use(): void
    {
        $maker = $this->createUserWithRole('platform-admin');
        $checker = $this->createUserWithRole('platform-admin');
        $this->ensureMourabahaBaseline($maker);
        $this->createActiveAuthority($maker, [
            ['user' => $checker, 'role' => 'approver'],
            ['user' => $this->createUserWithRole('platform-admin'), 'role' => 'chair'],
        ]);
        $productPublicId = $this->createApprovedProduct($maker, $checker, 'MUR-REV');

        $revoke = $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/islamic-approval-workflows/islamic_product/'.$productPublicId.'/revoke', [
                'comments' => 'shariah violation discovered',
            ]);
        $this->assertJsonSuccess($revoke);
        $revoke->assertJsonPath('data.current_state', IslamicApprovalStateMachine::STATE_REVOKED);

        $agency = $this->createAgency('IF-REV');
        $clientPublicId = $this->createClient($agency['id']);
        $response = $this->withApiHeaders()
            ->actingAsSanctum($maker)
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

    public function test_revoked_screening_policy_workflow_blocks_strict_screening_use(): void
    {
        $admin = $this->createUserWithRole('platform-admin');
        $approver = $this->createUserWithRole('platform-admin');
        $this->createActiveAuthority($admin, [
            ['user' => $approver, 'role' => 'approver'],
            ['user' => $this->createUserWithRole('platform-admin'), 'role' => 'chair'],
        ]);

        $policyPublicId = $this->createActiveScreeningPolicy($admin, $approver);

        $before = $this->withApiHeaders()
            ->actingAsSanctum($admin)
            ->postJson('/api/v1/islamic-screening/evaluate', [
                'subject_type' => 'islamic_product',
                'subject_public_id' => (string) Str::ulid(),
                'context_type' => 'product_approval',
                'strict_policy' => true,
                'facts' => [
                    'scope_type' => 'institution',
                    'supplier_flags' => ['trusted_supplier'],
                ],
            ]);
        $this->assertJsonSuccess($before);
        $before->assertJsonPath('data.result', 'pass');

        $revoke = $this->withApiHeaders()
            ->actingAsSanctum($admin)
            ->postJson('/api/v1/islamic-approval-workflows/islamic_screening_policy/'.$policyPublicId.'/revoke', [
                'comments' => 'workflow revoked for contradiction test',
            ]);
        $this->assertJsonSuccess($revoke);
        $revoke->assertJsonPath('data.current_state', IslamicApprovalStateMachine::STATE_REVOKED);

        $after = $this->withApiHeaders()
            ->actingAsSanctum($admin)
            ->postJson('/api/v1/islamic-screening/evaluate', [
                'subject_type' => 'islamic_product',
                'subject_public_id' => (string) Str::ulid(),
                'context_type' => 'product_approval',
                'strict_policy' => true,
                'facts' => [
                    'scope_type' => 'institution',
                    'supplier_flags' => ['trusted_supplier'],
                ],
            ]);
        $this->assertJsonSuccess($after);
        $after->assertJsonPath('data.result', 'fail');
        self::assertStringContainsString('No active screening policy', (string) $after->json('data.block_reason'));
    }

    public function test_expired_state_blocks_new_use_while_preserving_history(): void
    {
        $maker = $this->createUserWithRole('platform-admin');
        $checker = $this->createUserWithRole('platform-admin');
        $this->ensureMourabahaBaseline($maker);
        $this->createActiveAuthority($maker, [
            ['user' => $checker, 'role' => 'approver'],
            ['user' => $this->createUserWithRole('platform-admin'), 'role' => 'chair'],
        ]);
        $productPublicId = $this->createApprovedProduct($maker, $checker, 'MUR-EXP');

        $expire = $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/islamic-approval-workflows/islamic_product/'.$productPublicId.'/expire', [
                'comments' => 'review period ended',
            ]);
        $this->assertJsonSuccess($expire);
        $expire->assertJsonPath('data.current_state', IslamicApprovalStateMachine::STATE_EXPIRED);

        $agency = $this->createAgency('IF-EXP');
        $clientPublicId = $this->createClient($agency['id']);
        $response = $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/islamic-financings', [
                'client_public_id' => $clientPublicId,
                'agency_public_id' => $agency['public_id'],
                'product_public_id' => $productPublicId,
                'contract_type' => 'murabaha',
                'purchase_cost_minor' => 800000,
                'markup_minor' => 200000,
            ]);
        $this->assertJsonError($response, 422);

        // Historical decisions are preserved (submit + approve + expire).
        $workflowId = DB::table('islamic_approval_workflows')
            ->where('subject_type', IslamicApprovalStateMachine::SUBJECT_PRODUCT)
            ->where('subject_public_id', $productPublicId)
            ->value('id');
        $count = DB::table('islamic_approval_decisions')->where('workflow_id', $workflowId)->count();
        self::assertSame(3, $count);
    }

    public function test_audit_trail_records_every_transition_and_block(): void
    {
        $maker = $this->createUserWithRole('platform-admin');
        $checker = $this->createUserWithRole('platform-admin');
        $this->ensureMourabahaBaseline($maker);
        $this->createActiveAuthority($maker, [
            ['user' => $checker, 'role' => 'approver'],
            ['user' => $this->createUserWithRole('platform-admin'), 'role' => 'chair'],
        ]);
        $productPublicId = $this->createApprovedProduct($maker, $checker, 'MUR-AUD');

        $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/islamic-approval-workflows/islamic_product/'.$productPublicId.'/suspend', [
                'comments' => 'audit test',
            ]);
        $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/islamic-approval-workflows/islamic_product/'.$productPublicId.'/revoke', [
                'comments' => 'audit test',
            ]);

        // use_blocked event when origination is attempted against a revoked subject.
        $agency = $this->createAgency('IF-AUD');
        $clientPublicId = $this->createClient($agency['id']);
        $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/islamic-financings', [
                'client_public_id' => $clientPublicId,
                'agency_public_id' => $agency['public_id'],
                'product_public_id' => $productPublicId,
                'contract_type' => 'murabaha',
                'purchase_cost_minor' => 800000,
                'markup_minor' => 200000,
            ]);

        foreach ([
            'islamic.approval_workflow.created',
            'islamic.approval.submitted',
            'islamic.approval.approved',
            'islamic.approval.suspended',
            'islamic.approval.revoked',
            'islamic.approval.use_blocked',
        ] as $event) {
            $this->assertDatabaseHas('activity_log', [
                'log_name' => 'security',
                'event' => $event,
            ]);
        }
    }

    public function test_conditional_max_notional_is_deny_by_default(): void
    {
        // Phase 5 enforcement: a conditional approval that fixes a max notional
        // ceiling cannot be evaluated by the central gate without caller
        // context, so the gate must deny-by-default and the financing flow
        // must refuse to originate.
        $admin = $this->createUserWithRole('platform-admin');
        $approver = $this->createUserWithRole('platform-admin');
        $this->createActiveAuthority($admin, [
            ['user' => $approver, 'role' => 'approver'],
            ['user' => $this->createUserWithRole('platform-admin'), 'role' => 'chair'],
        ]);
        $productPublicId = $this->createProduct($admin, 'MUR-CMAX');
        $this->withApiHeaders()
            ->actingAsSanctum($admin)
            ->postJson('/api/v1/islamic-products/'.$productPublicId.'/compliance-reviews');

        $approve = $this->withApiHeaders()
            ->actingAsSanctum($approver)
            ->postJson('/api/v1/islamic-approval-workflows/islamic_product/'.$productPublicId.'/approve', [
                'effective_to' => CarbonImmutable::now()->addMonth()->toDateString(),
                'conditions' => ['max_notional_minor' => 100000],
                'requester_user_public_id' => $admin->public_id,
            ]);
        $this->assertJsonSuccess($approve);

        $show = $this->withApiHeaders()
            ->actingAsSanctum($admin)
            ->getJson('/api/v1/islamic-approval-workflows/islamic_product/'.$productPublicId);
        self::assertFalse($show->json('data.usability.ok'));

        $agency = $this->createAgency('IF-CMAX');
        $clientPublicId = $this->createClient($agency['id']);
        $financing = $this->withApiHeaders()
            ->actingAsSanctum($admin)
            ->postJson('/api/v1/islamic-financings', [
                'client_public_id' => $clientPublicId,
                'agency_public_id' => $agency['public_id'],
                'product_public_id' => $productPublicId,
                'contract_type' => 'murabaha',
                'purchase_cost_minor' => 800000,
                'markup_minor' => 200000,
            ]);
        $this->assertJsonError($financing, 422);
    }

    public function test_conditional_required_controls_as_string_is_rejected(): void
    {
        // Defends against the deny-by-default gate being silently bypassed by a
        // malformed `required_controls` value (string instead of list).
        $admin = $this->createUserWithRole('platform-admin');
        $approver = $this->createUserWithRole('platform-admin');
        $this->createActiveAuthority($admin, [
            ['user' => $approver, 'role' => 'approver'],
            ['user' => $this->createUserWithRole('platform-admin'), 'role' => 'chair'],
        ]);
        $productPublicId = $this->createProduct($admin, 'MUR-COND-MAL');
        $this->withApiHeaders()
            ->actingAsSanctum($admin)
            ->postJson('/api/v1/islamic-products/'.$productPublicId.'/compliance-reviews');

        $response = $this->withApiHeaders()
            ->actingAsSanctum($approver)
            ->postJson('/api/v1/islamic-approval-workflows/islamic_product/'.$productPublicId.'/approve', [
                'effective_to' => CarbonImmutable::now()->addMonth()->toDateString(),
                'conditions' => ['required_controls' => 'quarterly_review'],
                'requester_user_public_id' => $admin->public_id,
            ]);
        $this->assertJsonError($response, 422);
    }

    public function test_policy_financing_flow_does_not_read_legacy_product_status(): void
    {
        // Phase 4.3 cutover policy: new-use selection in Islamic flows MUST depend
        // on the workflow service (`isUsableForNewActions*`). Direct reads of
        // `islamic_products.status` for gating are forbidden so legacy mirror
        // drift cannot bypass workflow restrictions.
        $source = (string) file_get_contents(base_path('app/Application/IslamicFinance/IslamicFinancingWorkflow.php'));
        self::assertNotSame('', $source);
        self::assertStringNotContainsString("rowString(\$product, 'status')", $source, 'IslamicFinancingWorkflow must not read the legacy islamic_products.status column.');
        self::assertStringNotContainsString("status') !== 'approved'", $source, 'IslamicFinancingWorkflow must not gate on legacy approved status.');
        self::assertStringContainsString('isUsableForNewActionsLocked', $source, 'Financing flow must lock the workflow row when checking usability.');
    }

    public function test_reconcile_command_detects_and_repairs_mismatches(): void
    {
        $maker = $this->createUserWithRole('platform-admin');
        $checker = $this->createUserWithRole('platform-admin');
        $this->ensureMourabahaBaseline($maker);
        $this->createActiveAuthority($maker, [
            ['user' => $checker, 'role' => 'approver'],
            ['user' => $this->createUserWithRole('platform-admin'), 'role' => 'chair'],
        ]);
        $productPublicId = $this->createApprovedProduct($maker, $checker, 'MUR-REC');

        // Force drift: workflow says approved but legacy mirror is downgraded.
        DB::table('islamic_products')->where('public_id', $productPublicId)->update(['status' => 'draft']);

        $exit = Artisan::call('islamic:approval-workflow:reconcile-statuses');
        self::assertSame(1, $exit, 'Drift should exit FAILURE without --fix.');

        $fixExit = Artisan::call('islamic:approval-workflow:reconcile-statuses', ['--fix' => true]);
        self::assertSame(0, $fixExit);
        $this->assertDatabaseHas('islamic_products', [
            'public_id' => $productPublicId,
            'status' => 'approved',
        ]);
    }

    // --- helpers ----------------------------------------------------------

    private function createUserWithRole(string $role): User
    {
        $user = User::factory()->createOne([
            'status' => User::STATUS_ACTIVE,
            'phone_verified_at' => now(),
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function createProduct(User $actor, string $code): string
    {
        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-products', [
                'code' => $code,
                'name' => 'Product '.$code,
                'contract_type' => 'murabaha',
                'rules' => [
                    'document_requirements' => ['evidence_pack' => 'baseline'],
                    'authorization_rules' => ['maker_checker' => true, 'approver_scope' => 'platform_admin'],
                    'operational_procedure' => ['reference' => 'if-op-v1', 'version' => '2026.01'],
                    'reporting_category' => 'mourabaha_receivables',
                    'mourabaha_configuration' => [
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
                    ],
                ],
            ]);
        $this->assertJsonSuccess($response, 201);

        return $this->requireStringJsonPath($response, 'data.public_id');
    }

    private function createApprovedProduct(User $maker, User $checker, string $code): string
    {
        $productPublicId = $this->createProduct($maker, $code);

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
        $this->assertJsonSuccess($approve);

        return $productPublicId;
    }

    /**
     * @return array{id: int, public_id: string}
     */
    private function createAgency(string $code): array
    {
        $publicId = (string) Str::ulid();
        $id = DB::table('agencies')->insertGetId([
            'public_id' => $publicId,
            'code' => $code.'-'.Str::upper(Str::random(4)),
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

    private function createDocument(User $uploader): string
    {
        $publicId = (string) Str::ulid();
        DB::table('documents')->insert([
            'public_id' => $publicId,
            'agency_id' => $this->createAgency('DOC')['id'],
            'uploaded_by_user_id' => $uploader->id,
            'category' => 'sharia_authority',
            'title' => 'Evidence',
            'disk' => 'local',
            'path' => 'documents/'.$publicId,
            'original_name' => 'evidence.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'checksum_sha256' => str_repeat('a', 64),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $publicId;
    }

    /**
     * @param  array<int, array{user: User, role: string}>  $members
     */
    private function createActiveAuthority(User $actor, array $members): string
    {
        $documentPublicId = $this->createDocument($actor);
        $created = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-sharia-authorities', [
                'name' => 'Test Sharia Board '.Str::random(4),
                'authority_type' => 'board',
                'jurisdiction' => 'institution',
                'mandate_scope' => ['type' => 'institution'],
                'mandate_summary' => 'Governs Sharia compliance for tests.',
                'effective_date' => CarbonImmutable::now()->subDays(2)->toDateString(),
                'document_public_id' => $documentPublicId,
            ]);
        $this->assertJsonSuccess($created, 201);
        $authorityPublicId = $this->requireStringJsonPath($created, 'data.public_id');

        foreach ($members as $m) {
            $this->withApiHeaders()
                ->actingAsSanctum($actor)
                ->postJson('/api/v1/islamic-sharia-authorities/'.$authorityPublicId.'/members', [
                    'user_public_id' => $m['user']->public_id,
                    'member_role' => $m['role'],
                    'starts_on' => CarbonImmutable::now()->subDay()->toDateString(),
                ]);
        }

        $activate = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-sharia-authorities/'.$authorityPublicId.'/activate');
        $this->assertJsonSuccess($activate);

        return $authorityPublicId;
    }

    private function ensureMourabahaBaseline(User $actor): void
    {
        $standardsDoc = $this->createDocument($actor);
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
                'document_public_id' => $standardsDoc,
            ]);
        $standardPublicId = $this->requireStringJsonPath($created, 'data.public_id');
        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-standards/'.$standardPublicId.'/links', [
                'linkable_type' => 'product_family',
                'linkable_code' => 'mourabaha',
            ]);
        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-standards/'.$standardPublicId.'/links', [
                'linkable_type' => 'contract_template',
                'linkable_code' => 'mourabaha_contract_template',
                'linkable_identifier' => 'reserved_code',
            ]);
        $mappingPublicId = $this->seedIslamicAccountingMapping();
        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-standards/'.$standardPublicId.'/links', [
                'linkable_type' => 'accounting_mapping',
                'linkable_code' => $mappingPublicId,
            ]);
        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-standards/'.$standardPublicId.'/activate');

        $signoffDoc = $this->createDocument($actor);
        $signoffCreated = $this->withApiHeaders()
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
                'document_public_id' => $signoffDoc,
            ]);
        $signoffPublicId = $this->requireStringJsonPath($signoffCreated, 'data.public_id');
        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-regulatory-signoffs/'.$signoffPublicId.'/links', [
                'linkable_type' => 'product_family',
                'linkable_code' => 'mourabaha',
                'restriction_mode' => 'allow',
            ]);
        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-regulatory-signoffs/'.$signoffPublicId.'/activate');

        $this->ensureApprovedContractTemplate($actor, 'mourabaha');
    }

    private function requireStringJsonPath(TestResponse $response, string $path): string
    {
        $value = $response->json($path);
        self::assertIsString($value);

        return $value;
    }

    private function seedIslamicAccountingMapping(): string
    {
        $opCodeId = DB::table('operation_codes')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'code' => 'if_awf_'.Str::ulid(),
            'label' => 'Islamic workflow mapping',
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
            DB::table('islamic_approval_workflows')->insert([
                'public_id' => (string) Str::ulid(),
                'subject_type' => 'islamic_mapping',
                'subject_public_id' => $publicId,
                'current_state' => 'approved',
                'effective_from' => now()->subDay()->toDateString(),
                'effective_to' => null,
                'is_blocking' => true,
                'version' => 1,
                'created_by_user_id' => (int) $actorId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $publicId;
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

        $documentPublicId = $this->createDocument($actor);
        $create = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-contract-templates', [
                'family_code' => $familyCode,
                'language_code' => 'fr',
                'template_code' => $familyCode.'_contract_template',
                'version' => 1,
                'effective_from' => now()->subDay()->toDateString(),
                'document_public_id' => $documentPublicId,
                'legal_signoff_ref' => 'LEGAL-REF-'.$familyCode,
                'sharia_signoff_ref' => 'SHARIA-REF-'.$familyCode,
                'fields_schema' => ['party_identifiers' => ['required' => true]],
                'commercial_terms_schema' => ['term_keys' => ['purchase_cost_minor', 'markup_minor']],
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
    }

    private function createActiveScreeningPolicy(User $creator, User $approver): string
    {
        $create = $this->withApiHeaders()
            ->actingAsSanctum($creator)
            ->postJson('/api/v1/islamic-screening-policies', [
                'code' => 'IF011-SP-'.Str::upper(Str::random(6)),
                'name' => 'IF-011 Screening Policy',
                'scope_type' => 'institution',
                'effective_from' => CarbonImmutable::now()->subDay()->toDateString(),
            ]);
        $this->assertJsonSuccess($create, 201);
        $policyPublicId = $this->requireStringJsonPath($create, 'data.public_id');

        $rule = $this->withApiHeaders()
            ->actingAsSanctum($creator)
            ->postJson('/api/v1/islamic-screening-policies/'.$policyPublicId.'/rules', [
                'rule_type' => 'supplier_flag',
                'match_key' => 'trusted_supplier',
                'match_operator' => 'equals',
                'action' => 'allow_with_note',
                'priority' => 1,
            ]);
        $this->assertJsonSuccess($rule);

        $submit = $this->withApiHeaders()
            ->actingAsSanctum($creator)
            ->postJson('/api/v1/islamic-approval-workflows/islamic_screening_policy/'.$policyPublicId.'/submit');
        $this->assertJsonSuccess($submit);

        $approve = $this->withApiHeaders()
            ->actingAsSanctum($approver)
            ->postJson('/api/v1/islamic-approval-workflows/islamic_screening_policy/'.$policyPublicId.'/approve', [
                'requester_user_public_id' => $creator->public_id,
            ]);
        $this->assertJsonSuccess($approve);

        $activate = $this->withApiHeaders()
            ->actingAsSanctum($creator)
            ->postJson('/api/v1/islamic-screening-policies/'.$policyPublicId.'/activate');
        $this->assertJsonSuccess($activate);

        return $policyPublicId;
    }
}
