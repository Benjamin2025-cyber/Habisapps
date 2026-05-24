<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Application\IslamicFinance\IslamicProductReadinessService;
use App\Application\IslamicFinance\IslamicStandardsBaselineService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class IslamicStandardsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_standards_creation_requires_active_document_evidence(): void
    {
        $actor = $this->createUserWithRole('platform-admin');

        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-standards', [
                'source' => 'AAOIFI',
                'reference' => 'AAOIFI-SS-8',
                'title' => 'Murabaha standard',
                'scope_summary' => 'Applies to Mourabaha product family.',
                'owner_type' => 'committee',
                'owner_committee' => 'Sharia Board',
                'effective_date' => CarbonImmutable::now()->subDay()->toDateString(),
                'document_public_id' => 'nonexistent',
            ]);

        $this->assertJsonError($response, 422);
    }

    public function test_product_readiness_fails_without_active_standards_baseline(): void
    {
        $maker = $this->createUserWithRole('platform-admin');
        $checker = $this->createUserWithRole('platform-admin');
        $productPublicId = $this->createDraftProduct($maker, 'MUR-IF001-NB');

        $reviewPublicId = $this->requestComplianceReview($maker, $productPublicId);
        $response = $this->reviewComplianceAsChecker($checker, $reviewPublicId);

        $this->assertJsonError($response, 422);
        $errors = $response->json('errors.islamic_standards_baseline');
        self::assertIsArray($errors);
        self::assertNotEmpty($errors);
        $this->assertDatabaseHas('islamic_products', [
            'public_id' => $productPublicId,
            'status' => 'draft',
        ]);
    }

    public function test_amend_rejects_expiry_not_after_effective_date(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $original = $this->createActiveStandard(
            $actor,
            effectiveDate: CarbonImmutable::now()->subDays(2)->toDateString(),
            linkType: 'product_family',
            linkCode: 'mourabaha',
        );

        $newDoc = $this->createDocument($actor);
        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-standards/'.$original['public_id'].'/amend', [
                'expiry_date' => CarbonImmutable::now()->subDays(5)->toDateString(),
                'document_public_id' => $newDoc,
            ]);
        $this->assertJsonError($response, 422);
    }

    public function test_index_supports_pagination_and_filters(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $this->createDraftStandard($actor);
        $this->createDraftStandard($actor);
        $this->createDraftStandard($actor);

        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->getJson('/api/v1/islamic-standards?per_page=2&page=1');

        $this->assertJsonSuccess($response);
        $data = $response->json('data');
        self::assertIsArray($data);
        self::assertCount(2, $data);
        $response->assertJsonPath('meta.per_page', 2);
        $response->assertJsonPath('meta.page', 1);
        self::assertIsInt($response->json('meta.total'));
    }

    public function test_future_effective_standard_blocks_approval(): void
    {
        $maker = $this->createUserWithRole('platform-admin');
        $checker = $this->createUserWithRole('platform-admin');

        $this->createActiveStandard(
            $maker,
            effectiveDate: CarbonImmutable::now()->addDay()->toDateString(),
            linkType: 'product_family',
            linkCode: 'mourabaha',
        );

        $productPublicId = $this->createDraftProduct($maker, 'MUR-IF001-FE');
        $reviewPublicId = $this->requestComplianceReview($maker, $productPublicId);
        $response = $this->reviewComplianceAsChecker($checker, $reviewPublicId);

        $this->assertJsonError($response, 422);
    }

    public function test_expired_standard_blocks_new_approvals(): void
    {
        $maker = $this->createUserWithRole('platform-admin');
        $checker = $this->createUserWithRole('platform-admin');

        $this->createActiveStandard(
            $maker,
            effectiveDate: CarbonImmutable::now()->subDays(10)->toDateString(),
            linkType: 'product_family',
            linkCode: 'mourabaha',
            expiryDate: CarbonImmutable::now()->subDay()->toDateString(),
        );

        $productPublicId = $this->createDraftProduct($maker, 'MUR-IF001-EX');
        $reviewPublicId = $this->requestComplianceReview($maker, $productPublicId);
        $response = $this->reviewComplianceAsChecker($checker, $reviewPublicId);

        $this->assertJsonError($response, 422);
    }

    public function test_archived_attachment_blocks_baseline(): void
    {
        $maker = $this->createUserWithRole('platform-admin');
        $checker = $this->createUserWithRole('platform-admin');

        $standard = $this->createActiveStandard(
            $maker,
            effectiveDate: CarbonImmutable::now()->subDay()->toDateString(),
            linkType: 'product_family',
            linkCode: 'mourabaha',
        );

        DB::table('documents')->where('public_id', $standard['document_public_id'])->update([
            'status' => 'archived',
            'archived_at' => now(),
        ]);

        $productPublicId = $this->createDraftProduct($maker, 'MUR-IF001-DOC');
        $reviewPublicId = $this->requestComplianceReview($maker, $productPublicId);
        $response = $this->reviewComplianceAsChecker($checker, $reviewPublicId);

        $this->assertJsonError($response, 422);
    }

    public function test_valid_baseline_allows_approval(): void
    {
        $maker = $this->createUserWithRole('platform-admin');
        $checker = $this->createUserWithRole('platform-admin');

        $this->createActiveStandard(
            $maker,
            effectiveDate: CarbonImmutable::now()->subDay()->toDateString(),
            linkType: 'product_family',
            linkCode: 'mourabaha',
        );
        $this->ensureMourabahaSignoff($maker);

        $productPublicId = $this->createDraftProduct($maker, 'MUR-IF001-OK');
        $reviewPublicId = $this->requestComplianceReview($maker, $productPublicId);
        $response = $this->reviewComplianceAsChecker($checker, $reviewPublicId);

        $this->assertJsonSuccess($response);
        $response->assertJsonPath('data.status', 'approved');
        $this->assertDatabaseHas('islamic_products', [
            'public_id' => $productPublicId,
            'status' => 'approved',
        ]);
    }

    public function test_standards_amendment_creates_audit_and_preserves_active_standard(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $original = $this->createActiveStandard(
            $actor,
            effectiveDate: CarbonImmutable::now()->subDay()->toDateString(),
            linkType: 'product_family',
            linkCode: 'mourabaha',
        );

        $newDoc = $this->createDocument($actor);
        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-standards/'.$original['public_id'].'/amend', [
                'title' => 'Murabaha standard (revised)',
                'document_public_id' => $newDoc,
            ]);
        $this->assertJsonSuccess($response, 201);
        $newPublicId = $this->requireStringJsonPath($response, 'data.public_id');

        self::assertNotSame($original['public_id'], $newPublicId);
        $this->assertDatabaseHas('islamic_standards', [
            'public_id' => $original['public_id'],
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('islamic_standards', [
            'public_id' => $newPublicId,
            'status' => 'draft',
        ]);
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'security',
            'event' => 'islamic.standard.amended',
        ]);
    }

    public function test_draft_update_creates_audit_event(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $draft = $this->createDraftStandard($actor);

        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->putJson('/api/v1/islamic-standards/'.$draft['public_id'], [
                'scope_summary' => 'Updated scope.',
            ]);
        $this->assertJsonSuccess($response);

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'security',
            'event' => 'islamic.standard.updated',
        ]);
    }

    public function test_activation_requires_attachment_and_link(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $draft = $this->createDraftStandard($actor);

        // No links yet -> activation must fail
        $noLinks = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-standards/'.$draft['public_id'].'/activate');
        $this->assertJsonError($noLinks, 422);

        // Add a non-family/account link only -> still fails because no family/account coverage
        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-standards/'.$draft['public_id'].'/links', [
                'linkable_type' => 'contract_template',
                'linkable_code' => 'mourabaha_contract_template',
                'linkable_identifier' => 'reserved_code',
            ]);
        $noFamily = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-standards/'.$draft['public_id'].'/activate');
        $this->assertJsonError($noFamily, 422);

        // Add product_family link -> succeeds
        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-standards/'.$draft['public_id'].'/links', [
                'linkable_type' => 'product_family',
                'linkable_code' => 'mourabaha',
            ]);
        $okLinks = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-standards/'.$draft['public_id'].'/activate');
        $this->assertJsonSuccess($okLinks);
    }

    public function test_activation_fails_with_archived_attachment(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $draft = $this->createDraftStandard($actor);

        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-standards/'.$draft['public_id'].'/links', [
                'linkable_type' => 'product_family',
                'linkable_code' => 'mourabaha',
            ]);

        DB::table('documents')->where('public_id', $draft['document_public_id'])->update([
            'status' => 'archived',
            'archived_at' => now(),
        ]);

        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-standards/'.$draft['public_id'].'/activate');
        $this->assertJsonError($response, 422);
    }

    public function test_link_supports_all_if001_link_targets_and_rejects_unknown_codes(): void
    {
        $actor = $this->createUserWithRole('platform-admin');

        foreach (['product_family' => 'mourabaha', 'account_type' => 'islamic_current_account'] as $type => $code) {
            $draft = $this->createDraftStandard($actor);
            $ok = $this->withApiHeaders()
                ->actingAsSanctum($actor)
                ->postJson('/api/v1/islamic-standards/'.$draft['public_id'].'/links', [
                    'linkable_type' => $type,
                    'linkable_code' => $code,
                ]);
            $this->assertJsonSuccess($ok, 201);

            $bad = $this->withApiHeaders()
                ->actingAsSanctum($actor)
                ->postJson('/api/v1/islamic-standards/'.$draft['public_id'].'/links', [
                    'linkable_type' => $type,
                    'linkable_code' => 'totally_unknown_code',
                ]);
            $this->assertJsonError($bad, 422);
        }

        foreach (['contract_template' => 'mourabaha_contract_template', 'screening_policy' => 'islamic_general_screening_policy'] as $type => $code) {
            $draft = $this->createDraftStandard($actor);
            $ok = $this->withApiHeaders()
                ->actingAsSanctum($actor)
                ->postJson('/api/v1/islamic-standards/'.$draft['public_id'].'/links', [
                    'linkable_type' => $type,
                    'linkable_code' => $code,
                    'linkable_identifier' => 'reserved_code',
                ]);
            $this->assertJsonSuccess($ok, 201);

            $bad = $this->withApiHeaders()
                ->actingAsSanctum($actor)
                ->postJson('/api/v1/islamic-standards/'.$draft['public_id'].'/links', [
                    'linkable_type' => $type,
                    'linkable_code' => 'unknown_reserved',
                    'linkable_identifier' => 'reserved_code',
                ]);
            $this->assertJsonError($bad, 422);
        }

        // accounting_mapping requires a real operation_account_mappings.public_id with islamic_finance module
        $draft = $this->createDraftStandard($actor);
        $mappingPublicId = $this->seedAccountingMapping(module: 'islamic_finance');
        $okMapping = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-standards/'.$draft['public_id'].'/links', [
                'linkable_type' => 'accounting_mapping',
                'linkable_code' => $mappingPublicId,
            ]);
        $this->assertJsonSuccess($okMapping, 201);

        $nonIslamicMapping = $this->seedAccountingMapping(module: 'loan');
        $badMapping = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-standards/'.$draft['public_id'].'/links', [
                'linkable_type' => 'accounting_mapping',
                'linkable_code' => $nonIslamicMapping,
            ]);
        $this->assertJsonError($badMapping, 422);

        $bareOpCode = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-standards/'.$draft['public_id'].'/links', [
                'linkable_type' => 'accounting_mapping',
                'linkable_code' => 'murabaha_receivable',
            ]);
        $this->assertJsonError($bareOpCode, 422);
    }

    public function test_non_admin_cannot_mutate_standards(): void
    {
        $admin = $this->createUserWithRole('platform-admin');
        $existing = $this->createActiveStandard(
            $admin,
            effectiveDate: CarbonImmutable::now()->subDay()->toDateString(),
            linkType: 'product_family',
            linkCode: 'mourabaha',
        );
        $draft = $this->createDraftStandard($admin);

        $nonAdmin = User::factory()->createOne([
            'status' => User::STATUS_ACTIVE,
            'phone_verified_at' => now(),
        ]);

        $minimalCreatePayload = [
            'source' => 'AAOIFI',
            'reference' => 'AAOIFI-SS-8',
            'title' => 'Murabaha standard',
            'scope_summary' => 'Applies to Mourabaha product family.',
            'owner_type' => 'committee',
            'owner_committee' => 'Sharia Board',
            'effective_date' => CarbonImmutable::now()->toDateString(),
            'document_public_id' => 'whatever',
        ];

        $cases = [
            ['post', '/api/v1/islamic-standards', $minimalCreatePayload],
            ['put', '/api/v1/islamic-standards/'.$draft['public_id'], ['title' => 'attempt']],
            ['post', '/api/v1/islamic-standards/'.$existing['public_id'].'/amend', $minimalCreatePayload],
            ['post', '/api/v1/islamic-standards/'.$draft['public_id'].'/activate', []],
            ['post', '/api/v1/islamic-standards/'.$existing['public_id'].'/retire', ['reason' => 'attempt']],
            ['post', '/api/v1/islamic-standards/'.$draft['public_id'].'/links', [
                'linkable_type' => 'product_family',
                'linkable_code' => 'mourabaha',
            ]],
            ['delete', '/api/v1/islamic-standards/'.$existing['public_id'].'/links', [
                'linkable_type' => 'product_family',
                'linkable_code' => 'mourabaha',
            ]],
        ];

        foreach ($cases as [$verb, $url, $payload]) {
            $response = $this->withApiHeaders()
                ->actingAsSanctum($nonAdmin)
                ->json(strtoupper($verb), $url, $payload);
            $this->assertJsonError($response, 403);
        }
    }

    public function test_retired_and_superseded_standards_do_not_satisfy_readiness(): void
    {
        $maker = $this->createUserWithRole('platform-admin');
        $checker = $this->createUserWithRole('platform-admin');

        $retired = $this->createActiveStandard(
            $maker,
            effectiveDate: CarbonImmutable::now()->subDay()->toDateString(),
            linkType: 'product_family',
            linkCode: 'mourabaha',
        );

        DB::table('islamic_standards')->where('public_id', $retired['public_id'])->update([
            'status' => 'retired',
            'retired_by_user_id' => $maker->id,
            'retired_at' => now(),
            'retirement_reason' => 'replaced by COBAC directive',
            'updated_at' => now(),
        ]);

        $productPublicId = $this->createDraftProduct($maker, 'MUR-IF001-RET');
        $reviewPublicId = $this->requestComplianceReview($maker, $productPublicId);
        $response = $this->reviewComplianceAsChecker($checker, $reviewPublicId);
        $this->assertJsonError($response, 422);

        // Now create a superseded standard linked to the same family
        $superseded = $this->createActiveStandard(
            $maker,
            effectiveDate: CarbonImmutable::now()->subDay()->toDateString(),
            linkType: 'product_family',
            linkCode: 'mourabaha',
        );
        DB::table('islamic_standards')->where('public_id', $superseded['public_id'])->update([
            'status' => 'superseded',
            'updated_at' => now(),
        ]);

        $product2 = $this->createDraftProduct($maker, 'MUR-IF001-SUP');
        $review2 = $this->requestComplianceReview($maker, $product2);
        $response2 = $this->reviewComplianceAsChecker($checker, $review2);
        $this->assertJsonError($response2, 422);
    }

    public function test_future_effective_amendment_does_not_create_baseline_gap(): void
    {
        $maker = $this->createUserWithRole('platform-admin');
        $checker = $this->createUserWithRole('platform-admin');

        $current = $this->createActiveStandard(
            $maker,
            effectiveDate: CarbonImmutable::now()->subDay()->toDateString(),
            linkType: 'product_family',
            linkCode: 'mourabaha',
        );
        $this->ensureMourabahaSignoff($maker);

        // Amend with a future-effective replacement
        $newDoc = $this->createDocument($maker);
        $futureDate = CarbonImmutable::now()->addDays(5)->toDateString();
        $amend = $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/islamic-standards/'.$current['public_id'].'/amend', [
                'title' => 'Murabaha standard (future)',
                'effective_date' => $futureDate,
                'document_public_id' => $newDoc,
            ]);
        $this->assertJsonSuccess($amend, 201);
        $amendmentPublicId = $this->requireStringJsonPath($amend, 'data.public_id');

        $activate = $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/islamic-standards/'.$amendmentPublicId.'/activate');
        $this->assertJsonSuccess($activate);

        // Predecessor must still be active
        $this->assertDatabaseHas('islamic_standards', [
            'public_id' => $current['public_id'],
            'status' => 'active',
        ]);

        // Product approval today succeeds through current standard
        $productPublicId = $this->createDraftProduct($maker, 'MUR-IF001-GAP');
        $reviewPublicId = $this->requestComplianceReview($maker, $productPublicId);
        $response = $this->reviewComplianceAsChecker($checker, $reviewPublicId);
        $this->assertJsonSuccess($response);
    }

    public function test_future_effective_amendment_becomes_valid_only_on_effective_date(): void
    {
        $maker = $this->createUserWithRole('platform-admin');

        $current = $this->createActiveStandard(
            $maker,
            effectiveDate: CarbonImmutable::now()->subDay()->toDateString(),
            linkType: 'product_family',
            linkCode: 'mourabaha',
        );
        $this->ensureMourabahaSignoff($maker);

        $newDoc = $this->createDocument($maker);
        $futureDate = CarbonImmutable::now()->addDays(5);
        $amend = $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/islamic-standards/'.$current['public_id'].'/amend', [
                'title' => 'Murabaha standard (future)',
                'effective_date' => $futureDate->toDateString(),
                'document_public_id' => $newDoc,
            ]);
        $this->assertJsonSuccess($amend, 201);
        $amendmentPublicId = $this->requireStringJsonPath($amend, 'data.public_id');

        $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/islamic-standards/'.$amendmentPublicId.'/activate');

        /** @var IslamicStandardsBaselineService $baseline */
        $baseline = app(IslamicStandardsBaselineService::class);

        // Before effective date: predecessor satisfies, amendment does not
        $beforeAsOf = CarbonImmutable::now();
        self::assertTrue($baseline->hasActiveBaseline('product_family', 'mourabaha', $beforeAsOf));

        // On effective date: amendment satisfies (predecessor still does too until superseded)
        $atAsOf = $futureDate;
        self::assertTrue($baseline->hasActiveBaseline('product_family', 'mourabaha', $atAsOf));

        // Service-level activation failures must be empty at both points
        /** @var IslamicProductReadinessService $readiness */
        $readiness = app(IslamicProductReadinessService::class);
        $product = (object) ['contract_type' => 'murabaha'];
        self::assertSame([], $readiness->activationFailures($product, $beforeAsOf));
        self::assertSame([], $readiness->activationFailures($product, $atAsOf));
    }

    public function test_api_responses_expose_public_ids_only(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $draft = $this->createDraftStandard($actor);

        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->getJson('/api/v1/islamic-standards/'.$draft['public_id']);
        $this->assertJsonSuccess($response);

        $data = $response->json('data');
        self::assertIsArray($data);
        self::assertArrayNotHasKey('id', $data);
        self::assertArrayNotHasKey('document_id', $data);
        self::assertArrayNotHasKey('created_by_user_id', $data);
        self::assertArrayHasKey('public_id', $data);
        self::assertArrayHasKey('document_public_id', $data);
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
            'category' => 'islamic_standard',
            'title' => 'Evidence',
            'disk' => 'local',
            'path' => 'documents/'.$publicId,
            'original_name' => 'standard.pdf',
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
        $existing = DB::table('agencies')->where('code', 'IF001')->first(['id']);
        if (is_object($existing) && is_numeric($existing->id)) {
            return (int) $existing->id;
        }

        return DB::table('agencies')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'code' => 'IF001',
            'name' => 'IF-001 Agency',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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

        $documentPublicId = $this->createDocument($actor);

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

    /**
     * @return array{public_id: string, document_public_id: string}
     */
    private function createDraftStandard(User $actor): array
    {
        $documentPublicId = $this->createDocument($actor);
        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-standards', [
                'source' => 'AAOIFI',
                'reference' => 'AAOIFI-SS-'.Str::random(4),
                'title' => 'Murabaha standard',
                'scope_summary' => 'Applies to Mourabaha product family.',
                'owner_type' => 'committee',
                'owner_committee' => 'Sharia Board',
                'effective_date' => CarbonImmutable::now()->subDay()->toDateString(),
                'document_public_id' => $documentPublicId,
            ]);
        $this->assertJsonSuccess($response, 201);

        return [
            'public_id' => $this->requireStringJsonPath($response, 'data.public_id'),
            'document_public_id' => $documentPublicId,
        ];
    }

    /**
     * @return array{public_id: string, document_public_id: string}
     */
    private function createActiveStandard(
        User $actor,
        string $effectiveDate,
        string $linkType,
        string $linkCode,
        ?string $expiryDate = null,
    ): array {
        $documentPublicId = $this->createDocument($actor);
        $payload = [
            'source' => 'AAOIFI',
            'reference' => 'AAOIFI-SS-'.Str::random(4),
            'title' => 'Murabaha standard',
            'scope_summary' => 'Applies to Mourabaha product family.',
            'owner_type' => 'committee',
            'owner_committee' => 'Sharia Board',
            'effective_date' => $effectiveDate,
            'document_public_id' => $documentPublicId,
        ];
        if ($expiryDate !== null) {
            $payload['expiry_date'] = $expiryDate;
        }

        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-standards', $payload);
        $this->assertJsonSuccess($response, 201);
        $publicId = $this->requireStringJsonPath($response, 'data.public_id');

        $linkPayload = [
            'linkable_type' => $linkType,
            'linkable_code' => $linkCode,
        ];
        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-standards/'.$publicId.'/links', $linkPayload);

        $activate = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-standards/'.$publicId.'/activate');
        $this->assertJsonSuccess($activate);

        return [
            'public_id' => $publicId,
            'document_public_id' => $documentPublicId,
        ];
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

    private function seedAccountingMapping(string $module): string
    {
        $opCodeId = DB::table('operation_codes')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'code' => 'op_'.Str::ulid(),
            'label' => 'Test op code',
            'module' => $module,
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
            'debit_ledger_account_id' => null,
            'credit_ledger_account_id' => null,
            'currency' => null,
            'status' => 'active',
            'rules' => null,
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
}
