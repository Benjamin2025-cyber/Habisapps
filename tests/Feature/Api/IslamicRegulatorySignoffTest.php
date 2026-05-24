<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Application\IslamicFinance\IslamicRegulatorySignoffService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class IslamicRegulatorySignoffTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_product_can_be_configured_in_draft_without_signoff(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $productPublicId = $this->createDraftProduct($actor, 'MUR-IF002-DRAFT');

        $review = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-products/'.$productPublicId.'/compliance-reviews', [
                'comments' => 'requesting review while drafting',
            ]);
        $this->assertJsonSuccess($review, 201);
        $review->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseHas('islamic_products', [
            'public_id' => $productPublicId,
            'status' => 'draft',
        ]);
    }

    public function test_product_cannot_be_approved_without_signoff(): void
    {
        $maker = $this->createUserWithRole('platform-admin');
        $checker = $this->createUserWithRole('platform-admin');
        $this->ensureMourabahaStandardsBaseline($maker);
        $productPublicId = $this->createDraftProduct($maker, 'MUR-IF002-NS');
        $reviewPublicId = $this->requestComplianceReview($maker, $productPublicId);

        $response = $this->reviewComplianceAsChecker($checker, $reviewPublicId);
        $this->assertJsonError($response, 422);
        self::assertIsArray($response->json('errors.islamic_regulatory_signoff'));

        $this->assertDatabaseHas('islamic_products', [
            'public_id' => $productPublicId,
            'status' => 'draft',
        ]);
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'security',
            'event' => 'islamic.product.readiness_blocked',
        ]);
    }

    public function test_combined_failures_are_recorded_under_both_gate_keys(): void
    {
        $maker = $this->createUserWithRole('platform-admin');
        $checker = $this->createUserWithRole('platform-admin');
        // Neither standards baseline nor sign-off is set up.

        $productPublicId = $this->createDraftProduct($maker, 'MUR-IF002-BOTH');
        $reviewPublicId = $this->requestComplianceReview($maker, $productPublicId);

        $response = $this->reviewComplianceAsChecker($checker, $reviewPublicId);
        $this->assertJsonError($response, 422);

        $standardsErrors = $response->json('errors.islamic_standards_baseline');
        $signoffErrors = $response->json('errors.islamic_regulatory_signoff');
        self::assertIsArray($standardsErrors);
        self::assertIsArray($signoffErrors);
        self::assertNotEmpty($standardsErrors);
        self::assertNotEmpty($signoffErrors);

        $audit = DB::table('activity_log')
            ->where('log_name', 'security')
            ->where('event', 'islamic.product.readiness_blocked')
            ->orderByDesc('id')
            ->first(['properties']);
        self::assertNotNull($audit);
        $properties = is_string($audit->properties) ? json_decode($audit->properties, true) : null;
        self::assertIsArray($properties);
        self::assertIsArray($properties['failed_gates'] ?? null);
        self::assertContains('islamic_standards_baseline', $properties['failed_gates']);
        self::assertContains('islamic_regulatory_signoff', $properties['failed_gates']);
    }

    public function test_future_effective_signoff_blocks_approval(): void
    {
        $maker = $this->createUserWithRole('platform-admin');
        $checker = $this->createUserWithRole('platform-admin');
        $this->ensureMourabahaStandardsBaseline($maker);
        $this->createActiveSignoff($maker, [
            'effective_date' => CarbonImmutable::now()->addDay()->toDateString(),
            'links' => [['linkable_type' => 'product_family', 'linkable_code' => 'mourabaha']],
        ]);

        $productPublicId = $this->createDraftProduct($maker, 'MUR-IF002-FE');
        $reviewPublicId = $this->requestComplianceReview($maker, $productPublicId);
        $response = $this->reviewComplianceAsChecker($checker, $reviewPublicId);
        $this->assertJsonError($response, 422);
    }

    public function test_expired_signoff_blocks_approval(): void
    {
        $maker = $this->createUserWithRole('platform-admin');
        $checker = $this->createUserWithRole('platform-admin');
        $this->ensureMourabahaStandardsBaseline($maker);
        $this->createActiveSignoff($maker, [
            'effective_date' => CarbonImmutable::now()->subDays(10)->toDateString(),
            'expiry_date' => CarbonImmutable::now()->subDay()->toDateString(),
            'links' => [['linkable_type' => 'product_family', 'linkable_code' => 'mourabaha']],
        ]);

        $productPublicId = $this->createDraftProduct($maker, 'MUR-IF002-EX');
        $reviewPublicId = $this->requestComplianceReview($maker, $productPublicId);
        $response = $this->reviewComplianceAsChecker($checker, $reviewPublicId);
        $this->assertJsonError($response, 422);
    }

    public function test_suspended_signoff_blocks_approval(): void
    {
        $this->assertStatusTransitionBlocksApproval('suspend', ['reason' => 'pending review']);
    }

    public function test_revoked_signoff_blocks_approval(): void
    {
        $this->assertStatusTransitionBlocksApproval('revoke', ['reason' => 'compliance revocation']);
    }

    public function test_deny_link_blocks_disallowed_product_family(): void
    {
        $maker = $this->createUserWithRole('platform-admin');
        $checker = $this->createUserWithRole('platform-admin');
        $this->ensureMourabahaStandardsBaseline($maker);
        $this->ensureShariaApprover($checker);
        $this->createActiveSignoff($maker, [
            'links' => [
                ['linkable_type' => 'product_family', 'linkable_code' => 'mourabaha', 'restriction_mode' => 'allow'],
                ['linkable_type' => 'product_family', 'linkable_code' => 'ijara', 'restriction_mode' => 'deny'],
            ],
        ]);
        // Another sign-off that also allows ijara to ensure deny wins
        $this->createActiveSignoff($maker, [
            'links' => [['linkable_type' => 'product_family', 'linkable_code' => 'ijara', 'restriction_mode' => 'allow']],
        ]);

        // mourabaha product still passes
        $okProduct = $this->createDraftProduct($maker, 'MUR-IF002-OK');
        $reviewOk = $this->requestComplianceReview($maker, $okProduct);
        $this->assertJsonSuccess($this->reviewComplianceAsChecker($checker, $reviewOk));

        // Direct service check for ijara via the deny scope
        /** @var IslamicRegulatorySignoffService $service */
        $service = app(IslamicRegulatorySignoffService::class);
        $failures = $service->activationFailuresForProductFamily('ijara');
        self::assertNotEmpty($failures);
        self::assertStringContainsString('denies', strtolower(implode(' ', $failures)));
    }

    public function test_valid_signoff_allows_approval(): void
    {
        $maker = $this->createUserWithRole('platform-admin');
        $checker = $this->createUserWithRole('platform-admin');
        $this->ensureMourabahaStandardsBaseline($maker);
        $this->ensureShariaApprover($checker);
        $this->createActiveSignoff($maker, [
            'links' => [['linkable_type' => 'product_family', 'linkable_code' => 'mourabaha']],
        ]);

        $productPublicId = $this->createDraftProduct($maker, 'MUR-IF002-OK');
        $reviewPublicId = $this->requestComplianceReview($maker, $productPublicId);
        $response = $this->reviewComplianceAsChecker($checker, $reviewPublicId);
        $this->assertJsonSuccess($response);
        $response->assertJsonPath('data.status', 'approved');
    }

    public function test_link_validation_rejects_unsupported_family_and_account_codes(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $draft = $this->createDraftSignoff($actor);

        $badFamily = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-regulatory-signoffs/'.$draft.'/links', [
                'linkable_type' => 'product_family',
                'linkable_code' => 'mafia',
            ]);
        $this->assertJsonError($badFamily, 422);

        $badAccount = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-regulatory-signoffs/'.$draft.'/links', [
                'linkable_type' => 'account_type',
                'linkable_code' => 'not_a_real_account',
            ]);
        $this->assertJsonError($badAccount, 422);

        $okAccount = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-regulatory-signoffs/'.$draft.'/links', [
                'linkable_type' => 'account_type',
                'linkable_code' => 'islamic_current_account',
            ]);
        $this->assertJsonSuccess($okAccount, 201);
    }

    public function test_active_signoff_cannot_be_edited_in_place(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $active = $this->createActiveSignoff($actor, [
            'links' => [['linkable_type' => 'product_family', 'linkable_code' => 'mourabaha']],
        ]);

        $editAttempt = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->putJson('/api/v1/islamic-regulatory-signoffs/'.$active['public_id'], [
                'opinion_summary' => 'in-place edit attempt',
            ]);
        $this->assertJsonError($editAttempt, 422);

        $linkAttempt = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-regulatory-signoffs/'.$active['public_id'].'/links', [
                'linkable_type' => 'product_family',
                'linkable_code' => 'ijara',
            ]);
        $this->assertJsonError($linkAttempt, 422);
    }

    public function test_lifecycle_transitions_create_audit_entries(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $signoff = $this->createActiveSignoff($actor, [
            'links' => [['linkable_type' => 'product_family', 'linkable_code' => 'mourabaha']],
        ]);

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'security',
            'event' => 'islamic.regulatory_signoff.activated',
        ]);

        $suspend = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-regulatory-signoffs/'.$signoff['public_id'].'/suspend', [
                'reason' => 'compliance review',
            ]);
        $this->assertJsonSuccess($suspend);
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'security',
            'event' => 'islamic.regulatory_signoff.suspended',
        ]);

        $revoke = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-regulatory-signoffs/'.$signoff['public_id'].'/revoke', [
                'reason' => 'regulator withdrew approval',
            ]);
        $this->assertJsonSuccess($revoke);
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'security',
            'event' => 'islamic.regulatory_signoff.revoked',
        ]);

        $retire = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-regulatory-signoffs/'.$signoff['public_id'].'/retire', [
                'reason' => 'archived',
            ]);
        $this->assertJsonSuccess($retire);
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'security',
            'event' => 'islamic.regulatory_signoff.retired',
        ]);
    }

    public function test_account_type_signoff_service_returns_failures_and_passes_when_valid(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        /** @var IslamicRegulatorySignoffService $service */
        $service = app(IslamicRegulatorySignoffService::class);

        // No sign-off yet
        self::assertNotEmpty($service->activationFailuresForAccountType('islamic_savings_account'));

        // Future-effective
        $this->createActiveSignoff($actor, [
            'effective_date' => CarbonImmutable::now()->addDay()->toDateString(),
            'links' => [['linkable_type' => 'account_type', 'linkable_code' => 'islamic_savings_account']],
        ]);
        self::assertNotEmpty($service->activationFailuresForAccountType('islamic_savings_account'));

        // Valid coverage now
        $this->createActiveSignoff($actor, [
            'links' => [['linkable_type' => 'account_type', 'linkable_code' => 'islamic_savings_account']],
        ]);
        self::assertSame([], $service->activationFailuresForAccountType('islamic_savings_account'));

        // Unsupported code
        self::assertNotEmpty($service->activationFailuresForAccountType('unknown_account_type'));
    }

    public function test_deny_signoff_cannot_be_activated(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $documentPublicId = $this->createDocument($actor);

        $create = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-regulatory-signoffs', $this->minimalCreatePayload([
                'approval_type' => 'deny',
                'document_public_id' => $documentPublicId,
            ]));
        $this->assertJsonSuccess($create, 201);
        $publicId = $this->requireStringJsonPath($create, 'data.public_id');

        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-regulatory-signoffs/'.$publicId.'/links', [
                'linkable_type' => 'product_family',
                'linkable_code' => 'mourabaha',
                'restriction_mode' => 'allow',
            ]);

        $activate = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-regulatory-signoffs/'.$publicId.'/activate');
        $this->assertJsonError($activate, 422);
    }

    public function test_db_check_prevents_deny_approval_type_from_being_active(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $documentPublicId = $this->createDocument($actor);

        $create = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-regulatory-signoffs', $this->minimalCreatePayload([
                'approval_type' => 'deny',
                'document_public_id' => $documentPublicId,
            ]));
        $this->assertJsonSuccess($create, 201);
        $signoffPublicId = $this->requireStringJsonPath($create, 'data.public_id');

        // Try to force status=active via raw DB. The CHECK constraint must reject it.
        $rejected = false;
        try {
            DB::table('islamic_regulatory_signoffs')->where('public_id', $signoffPublicId)->update([
                'status' => 'active',
                'activated_by_user_id' => $actor->id,
                'activated_at' => now(),
            ]);
        } catch (\Throwable) {
            $rejected = true;
        }
        self::assertTrue($rejected, 'DB CHECK should reject deny+active.');
    }

    public function test_service_treats_deny_approval_type_active_row_as_denying(): void
    {
        // This exercises defense-in-depth: even if a deny+active row somehow exists
        // (e.g. raw migration), the service must treat its scopes as denied. We bypass
        // the activate() workflow by disabling the CHECK constraint temporarily.
        $actor = $this->createUserWithRole('platform-admin');
        $documentPublicId = $this->createDocument($actor);

        $create = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-regulatory-signoffs', $this->minimalCreatePayload([
                'approval_type' => 'deny',
                'document_public_id' => $documentPublicId,
            ]));
        $this->assertJsonSuccess($create, 201);
        $signoffPublicId = $this->requireStringJsonPath($create, 'data.public_id');

        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-regulatory-signoffs/'.$signoffPublicId.'/links', [
                'linkable_type' => 'product_family',
                'linkable_code' => 'mourabaha',
                'restriction_mode' => 'allow',
            ]);

        DB::statement('ALTER TABLE islamic_regulatory_signoffs DROP CONSTRAINT islamic_regulatory_signoffs_deny_not_active');
        DB::table('islamic_regulatory_signoffs')->where('public_id', $signoffPublicId)->update([
            'status' => 'active',
            'activated_by_user_id' => $actor->id,
            'activated_at' => now(),
        ]);

        try {
            /** @var IslamicRegulatorySignoffService $service */
            $service = app(IslamicRegulatorySignoffService::class);
            $failures = $service->activationFailuresForProductFamily('mourabaha');
            self::assertNotEmpty($failures);
            self::assertStringContainsString('denies', strtolower(implode(' ', $failures)));
        } finally {
            DB::table('islamic_regulatory_signoffs')->where('public_id', $signoffPublicId)->update(['status' => 'draft']);
            DB::statement("ALTER TABLE islamic_regulatory_signoffs ADD CONSTRAINT islamic_regulatory_signoffs_deny_not_active CHECK (approval_type <> 'deny' OR status <> 'active')");
        }
    }

    public function test_update_draft_cannot_clear_accounting_implications_while_restrictions_still_reference_accounting(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $documentPublicId = $this->createDocument($actor);

        $create = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-regulatory-signoffs', $this->minimalCreatePayload([
                'approval_type' => 'allow_with_conditions',
                'document_public_id' => $documentPublicId,
                'restrictions' => ['accounting_limits' => ['no leverage']],
                'accounting_implications' => 'Post to deferred-payment ledger 410-200.',
            ]));
        $this->assertJsonSuccess($create, 201);
        $publicId = $this->requireStringJsonPath($create, 'data.public_id');

        // Attempt to clear accounting_implications without removing accounting_limits from restrictions.
        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->putJson('/api/v1/islamic-regulatory-signoffs/'.$publicId, [
                'accounting_implications' => null,
            ]);
        $this->assertJsonError($response, 422);
    }

    public function test_allow_with_conditions_accepts_null_accounting_implications_when_restrictions_omit_accounting_limits(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $documentPublicId = $this->createDocument($actor);

        // No restrictions at all — accounting_implications may be null even for allow_with_conditions.
        $okWithout = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-regulatory-signoffs', $this->minimalCreatePayload([
                'approval_type' => 'allow_with_conditions',
                'document_public_id' => $documentPublicId,
            ]));
        $this->assertJsonSuccess($okWithout, 201);

        // Only operational restrictions (no accounting_limits key) — also allowed without accounting_implications.
        $documentPublicId2 = $this->createDocument($actor);
        $okOperational = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-regulatory-signoffs', $this->minimalCreatePayload([
                'approval_type' => 'allow_with_conditions',
                'document_public_id' => $documentPublicId2,
                'restrictions' => ['conditions' => ['quarterly review']],
            ]));
        $this->assertJsonSuccess($okOperational, 201);

        // With accounting_limits key, accounting_implications becomes required.
        $documentPublicId3 = $this->createDocument($actor);
        $missingImpl = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-regulatory-signoffs', $this->minimalCreatePayload([
                'approval_type' => 'allow_with_conditions',
                'document_public_id' => $documentPublicId3,
                'restrictions' => ['accounting_limits' => ['no leveraged positions']],
            ]));
        $this->assertJsonError($missingImpl, 422);

        // Supplying both passes.
        $documentPublicId4 = $this->createDocument($actor);
        $bothOk = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-regulatory-signoffs', $this->minimalCreatePayload([
                'approval_type' => 'allow_with_conditions',
                'document_public_id' => $documentPublicId4,
                'restrictions' => ['accounting_limits' => ['no leveraged positions']],
                'accounting_implications' => 'Post deferred-payment to liability ledger 410-200.',
            ]));
        $this->assertJsonSuccess($bothOk, 201);
    }

    public function test_restrictions_payload_rejects_unstructured_keys(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $documentPublicId = $this->createDocument($actor);

        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-regulatory-signoffs', $this->minimalCreatePayload([
                'document_public_id' => $documentPublicId,
                'restrictions' => ['conditions' => ['no leverage'], 'arbitrary_key' => 'nope'],
            ]));
        $this->assertJsonError($response, 422);
    }

    public function test_non_admin_cannot_mutate_signoffs(): void
    {
        $admin = $this->createUserWithRole('platform-admin');
        $active = $this->createActiveSignoff($admin, [
            'links' => [['linkable_type' => 'product_family', 'linkable_code' => 'mourabaha']],
        ]);
        $draft = $this->createDraftSignoff($admin);

        $nonAdmin = User::factory()->createOne([
            'status' => User::STATUS_ACTIVE,
            'phone_verified_at' => now(),
        ]);

        $minimal = $this->minimalCreatePayload(['document_public_id' => 'whatever']);

        $cases = [
            ['post', '/api/v1/islamic-regulatory-signoffs', $minimal],
            ['put', '/api/v1/islamic-regulatory-signoffs/'.$draft, ['opinion_summary' => 'x']],
            ['post', '/api/v1/islamic-regulatory-signoffs/'.$draft.'/activate', []],
            ['post', '/api/v1/islamic-regulatory-signoffs/'.$active['public_id'].'/suspend', ['reason' => 'r']],
            ['post', '/api/v1/islamic-regulatory-signoffs/'.$active['public_id'].'/revoke', ['reason' => 'r']],
            ['post', '/api/v1/islamic-regulatory-signoffs/'.$active['public_id'].'/retire', ['reason' => 'r']],
            ['post', '/api/v1/islamic-regulatory-signoffs/'.$draft.'/links', ['linkable_type' => 'product_family', 'linkable_code' => 'mourabaha']],
            ['delete', '/api/v1/islamic-regulatory-signoffs/'.$active['public_id'].'/links', ['linkable_type' => 'product_family', 'linkable_code' => 'mourabaha']],
        ];

        foreach ($cases as [$verb, $url, $payload]) {
            $response = $this->withApiHeaders()
                ->actingAsSanctum($nonAdmin)
                ->json(strtoupper($verb), $url, $payload);
            $this->assertJsonError($response, 403);
        }
    }

    public function test_api_responses_expose_public_ids_only(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $draft = $this->createDraftSignoff($actor);

        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->getJson('/api/v1/islamic-regulatory-signoffs/'.$draft);
        $this->assertJsonSuccess($response);

        $data = $response->json('data');
        self::assertIsArray($data);
        self::assertArrayNotHasKey('id', $data);
        self::assertArrayNotHasKey('document_id', $data);
        self::assertArrayNotHasKey('created_by_user_id', $data);
        self::assertArrayHasKey('public_id', $data);
        self::assertArrayHasKey('document_public_id', $data);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertStatusTransitionBlocksApproval(string $transition, array $payload = []): void
    {
        $maker = $this->createUserWithRole('platform-admin');
        $checker = $this->createUserWithRole('platform-admin');
        $this->ensureMourabahaStandardsBaseline($maker);
        $signoff = $this->createActiveSignoff($maker, [
            'links' => [['linkable_type' => 'product_family', 'linkable_code' => 'mourabaha']],
        ]);

        $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/islamic-regulatory-signoffs/'.$signoff['public_id'].'/'.$transition, $payload);

        $productPublicId = $this->createDraftProduct($maker, 'MUR-IF002-'.strtoupper($transition));
        $reviewPublicId = $this->requestComplianceReview($maker, $productPublicId);
        $response = $this->reviewComplianceAsChecker($checker, $reviewPublicId);
        $this->assertJsonError($response, 422);
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

    private function createDocument(User $uploader): string
    {
        $publicId = (string) Str::ulid();
        DB::table('documents')->insert([
            'public_id' => $publicId,
            'agency_id' => $this->agencyId(),
            'uploaded_by_user_id' => $uploader->id,
            'category' => 'regulatory_signoff',
            'title' => 'Evidence',
            'disk' => 'local',
            'path' => 'documents/'.$publicId,
            'original_name' => 'signoff.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'checksum_sha256' => str_repeat('a', 64),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $publicId;
    }

    private function agencyId(): int
    {
        $existing = DB::table('agencies')->where('code', 'IF002')->first(['id']);
        if (is_object($existing) && is_numeric($existing->id)) {
            return (int) $existing->id;
        }

        return DB::table('agencies')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'code' => 'IF002',
            'name' => 'IF-002 Agency',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function minimalCreatePayload(array $overrides = []): array
    {
        return array_merge([
            'jurisdiction' => 'cameroon',
            'regulator' => 'cobac',
            'opinion_reference' => 'COBAC-MEMO-'.Str::random(4),
            'opinion_summary' => 'Authorisation for Islamic finance operations in Cameroon.',
            'approval_type' => 'allow',
            'owner_type' => 'committee',
            'owner_committee' => 'Compliance Board',
            'approved_on' => CarbonImmutable::now()->subDays(2)->toDateString(),
            'effective_date' => CarbonImmutable::now()->subDay()->toDateString(),
        ], $overrides);
    }

    private function createDraftSignoff(User $actor): string
    {
        $documentPublicId = $this->createDocument($actor);
        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-regulatory-signoffs', $this->minimalCreatePayload([
                'document_public_id' => $documentPublicId,
            ]));
        $this->assertJsonSuccess($response, 201);

        return $this->requireStringJsonPath($response, 'data.public_id');
    }

    /**
     * @param  array{
     *   effective_date?: string,
     *   expiry_date?: string,
     *   approval_type?: string,
     *   links: array<int, array{linkable_type: string, linkable_code: string, restriction_mode?: string}>,
     * }  $overrides
     * @return array{public_id: string, document_public_id: string}
     */
    private function createActiveSignoff(User $actor, array $overrides): array
    {
        $documentPublicId = $this->createDocument($actor);
        $payload = $this->minimalCreatePayload([
            'document_public_id' => $documentPublicId,
        ]);
        if (isset($overrides['effective_date'])) {
            $payload['effective_date'] = $overrides['effective_date'];
        }
        if (isset($overrides['expiry_date'])) {
            $payload['expiry_date'] = $overrides['expiry_date'];
        }
        if (isset($overrides['approval_type'])) {
            $payload['approval_type'] = $overrides['approval_type'];
        }

        $created = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-regulatory-signoffs', $payload);
        $this->assertJsonSuccess($created, 201);
        $publicId = $this->requireStringJsonPath($created, 'data.public_id');

        foreach ($overrides['links'] as $link) {
            $this->withApiHeaders()
                ->actingAsSanctum($actor)
                ->postJson('/api/v1/islamic-regulatory-signoffs/'.$publicId.'/links', $link);
        }

        $activate = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-regulatory-signoffs/'.$publicId.'/activate');
        $this->assertJsonSuccess($activate);

        return ['public_id' => $publicId, 'document_public_id' => $documentPublicId];
    }

    private function ensureMourabahaStandardsBaseline(User $actor): void
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

        $documentPublicId = $this->createDocument($actor);
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
        $chair = $this->createUserWithRole('platform-admin');
        $documentPublicId = $this->createDocument($admin);

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
    }

    private function createDraftProduct(User $actor, string $code): string
    {
        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-products', [
                'code' => $code,
                'name' => 'Product '.$code,
                'contract_type' => 'murabaha',
            ]);
        $this->assertJsonSuccess($response, 201);

        return $this->requireStringJsonPath($response, 'data.public_id');
    }

    private function requestComplianceReview(User $maker, string $productPublicId): string
    {
        $review = $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/islamic-products/'.$productPublicId.'/compliance-reviews');
        $this->assertJsonSuccess($review, 201);

        return $this->requireStringJsonPath($review, 'data.public_id');
    }

    private function reviewComplianceAsChecker(User $checker, string $reviewPublicId): TestResponse
    {
        return $this->withApiHeaders()
            ->actingAsSanctum($checker)
            ->postJson('/api/v1/islamic-compliance-reviews/'.$reviewPublicId.'/review', [
                'decision' => 'approve',
            ]);
    }

    private function requireStringJsonPath(TestResponse $response, string $path): string
    {
        $value = $response->json($path);
        self::assertIsString($value);

        return $value;
    }
}
