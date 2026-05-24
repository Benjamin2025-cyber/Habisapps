<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class IslamicShariaAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_unauthorized_staff_cannot_approve(): void
    {
        // Checker is platform-admin but has no Sharia mandate.
        $maker = $this->createUserWithRole('platform-admin');
        $checker = $this->createUserWithRole('platform-admin');
        $this->ensureMourabahaReadinessBaseline($maker);

        $productPublicId = $this->createDraftProduct($maker, 'MUR-IF010-UNAUTH');
        $reviewPublicId = $this->requestComplianceReview($maker, $productPublicId);
        $response = $this->reviewComplianceAsChecker($checker, $reviewPublicId);

        $this->assertJsonError($response, 422);
        $errors = $response->json('errors.islamic_sharia_authority');
        self::assertIsArray($errors);
        self::assertNotEmpty($errors);

        $this->assertDatabaseHas('islamic_products', [
            'public_id' => $productPublicId,
            'status' => 'draft',
        ]);
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'security',
            'event' => 'islamic.sharia_authority.decision_blocked',
        ]);
    }

    public function test_user_with_active_approver_mandate_can_approve(): void
    {
        $maker = $this->createUserWithRole('platform-admin');
        $checker = $this->createUserWithRole('platform-admin');
        $chair = $this->createUserWithRole('platform-admin');
        $this->ensureMourabahaReadinessBaseline($maker);
        $this->createActiveAuthority($maker, [
            ['user' => $checker, 'role' => 'approver'],
            ['user' => $chair, 'role' => 'chair'],
        ]);

        $productPublicId = $this->createDraftProduct($maker, 'MUR-IF010-OK');
        $reviewPublicId = $this->requestComplianceReview($maker, $productPublicId);
        $response = $this->reviewComplianceAsChecker($checker, $reviewPublicId);

        $this->assertJsonSuccess($response);
        $response->assertJsonPath('data.status', 'approved');
    }

    public function test_expired_mandate_cannot_approve(): void
    {
        $maker = $this->createUserWithRole('platform-admin');
        $checker = $this->createUserWithRole('platform-admin');
        $chair = $this->createUserWithRole('platform-admin');
        $this->ensureMourabahaReadinessBaseline($maker);
        $startsOn = CarbonImmutable::now()->subDays(2)->toDateString();
        $authority = $this->createActiveAuthority($maker, [
            ['user' => $checker, 'role' => 'approver', 'starts_on' => $startsOn, 'ends_on' => CarbonImmutable::now()->subDay()->toDateString()],
            ['user' => $chair, 'role' => 'chair'],
        ]);

        $productPublicId = $this->createDraftProduct($maker, 'MUR-IF010-EXP');
        $reviewPublicId = $this->requestComplianceReview($maker, $productPublicId);
        $response = $this->reviewComplianceAsChecker($checker, $reviewPublicId);

        $this->assertJsonError($response, 422);
        $this->assertDatabaseHas('islamic_sharia_authorities', [
            'public_id' => $authority['public_id'],
        ]);
    }

    public function test_suspended_member_cannot_approve(): void
    {
        $maker = $this->createUserWithRole('platform-admin');
        $checker = $this->createUserWithRole('platform-admin');
        $chair = $this->createUserWithRole('platform-admin');
        $admin = $this->createUserWithRole('platform-admin');
        $this->ensureMourabahaReadinessBaseline($maker);
        $authority = $this->createActiveAuthority($admin, [
            ['user' => $checker, 'role' => 'approver'],
            ['user' => $chair, 'role' => 'chair'],
        ]);
        $checkerMemberPublicId = $this->memberPublicId($authority['public_id'], $checker->id);

        $suspend = $this->withApiHeaders()
            ->actingAsSanctum($admin)
            ->postJson('/api/v1/islamic-sharia-authorities/'.$authority['public_id'].'/members/'.$checkerMemberPublicId.'/suspend', [
                'reason' => 'pending review',
            ]);
        $this->assertJsonSuccess($suspend);

        $productPublicId = $this->createDraftProduct($maker, 'MUR-IF010-SUSP');
        $reviewPublicId = $this->requestComplianceReview($maker, $productPublicId);
        $response = $this->reviewComplianceAsChecker($checker, $reviewPublicId);
        $this->assertJsonError($response, 422);
    }

    public function test_revoked_mandate_cannot_approve(): void
    {
        $maker = $this->createUserWithRole('platform-admin');
        $checker = $this->createUserWithRole('platform-admin');
        $chair = $this->createUserWithRole('platform-admin');
        $admin = $this->createUserWithRole('platform-admin');
        $this->ensureMourabahaReadinessBaseline($maker);
        $authority = $this->createActiveAuthority($admin, [
            ['user' => $checker, 'role' => 'approver'],
            ['user' => $chair, 'role' => 'chair'],
        ]);

        $revoke = $this->withApiHeaders()
            ->actingAsSanctum($admin)
            ->postJson('/api/v1/islamic-sharia-authorities/'.$authority['public_id'].'/revoke', [
                'reason' => 'mandate withdrawn',
            ]);
        $this->assertJsonSuccess($revoke);

        $productPublicId = $this->createDraftProduct($maker, 'MUR-IF010-REV');
        $reviewPublicId = $this->requestComplianceReview($maker, $productPublicId);
        $response = $this->reviewComplianceAsChecker($checker, $reviewPublicId);
        $this->assertJsonError($response, 422);
    }

    public function test_self_approval_is_rejected(): void
    {
        $maker = $this->createUserWithRole('platform-admin');
        $chair = $this->createUserWithRole('platform-admin');
        $this->ensureMourabahaReadinessBaseline($maker);
        // Maker is also an approver member. Existing requester-self-check should fire first.
        $this->createActiveAuthority($maker, [
            ['user' => $maker, 'role' => 'approver'],
            ['user' => $chair, 'role' => 'chair'],
        ]);

        $productPublicId = $this->createDraftProduct($maker, 'MUR-IF010-SELF');
        $reviewPublicId = $this->requestComplianceReview($maker, $productPublicId);
        $response = $this->reviewComplianceAsChecker($maker, $reviewPublicId);

        $this->assertJsonError($response, 422);
        $this->assertDatabaseHas('islamic_products', [
            'public_id' => $productPublicId,
            'status' => 'draft',
        ]);
    }

    public function test_reviewer_only_member_cannot_approve(): void
    {
        $maker = $this->createUserWithRole('platform-admin');
        $checker = $this->createUserWithRole('platform-admin');
        $chair = $this->createUserWithRole('platform-admin');
        $admin = $this->createUserWithRole('platform-admin');
        $this->ensureMourabahaReadinessBaseline($maker);
        $this->createActiveAuthority($admin, [
            ['user' => $checker, 'role' => 'reviewer'],
            ['user' => $chair, 'role' => 'chair'],
            ['user' => $admin, 'role' => 'approver'],
        ]);

        $productPublicId = $this->createDraftProduct($maker, 'MUR-IF010-REVR');
        $reviewPublicId = $this->requestComplianceReview($maker, $productPublicId);
        $response = $this->reviewComplianceAsChecker($checker, $reviewPublicId);
        $this->assertJsonError($response, 422);
    }

    public function test_scope_mismatched_member_cannot_approve_out_of_scope(): void
    {
        $maker = $this->createUserWithRole('platform-admin');
        $checker = $this->createUserWithRole('platform-admin');
        $chair = $this->createUserWithRole('platform-admin');
        $this->ensureMourabahaReadinessBaseline($maker);
        // Authority scope only covers ijara, not mourabaha.
        $this->createActiveAuthority($maker, [
            ['user' => $checker, 'role' => 'approver'],
            ['user' => $chair, 'role' => 'chair'],
        ], scope: ['type' => 'product_family', 'codes' => ['ijara']]);

        $productPublicId = $this->createDraftProduct($maker, 'MUR-IF010-SCOPE');
        $reviewPublicId = $this->requestComplianceReview($maker, $productPublicId);
        $response = $this->reviewComplianceAsChecker($checker, $reviewPublicId);
        $this->assertJsonError($response, 422);
    }

    public function test_authority_activation_fails_without_required_members(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $approverOnly = $this->createUserWithRole('platform-admin');
        $documentPublicId = $this->createDocument($actor);

        $created = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-sharia-authorities', $this->authorityPayload(['document_public_id' => $documentPublicId]));
        $this->assertJsonSuccess($created, 201);
        $authorityPublicId = $this->requireStringJsonPath($created, 'data.public_id');

        // No members at all: activate should fail.
        $noMembers = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-sharia-authorities/'.$authorityPublicId.'/activate');
        $this->assertJsonError($noMembers, 422);

        // Add approver only: chair/administrator still missing.
        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-sharia-authorities/'.$authorityPublicId.'/members', [
                'user_public_id' => $approverOnly->public_id,
                'member_role' => 'approver',
                'starts_on' => CarbonImmutable::now()->subDay()->toDateString(),
            ]);
        $missingChair = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-sharia-authorities/'.$authorityPublicId.'/activate');
        $this->assertJsonError($missingChair, 422);

        // Add chair: now activation should succeed.
        $chair = $this->createUserWithRole('platform-admin');
        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-sharia-authorities/'.$authorityPublicId.'/members', [
                'user_public_id' => $chair->public_id,
                'member_role' => 'chair',
                'starts_on' => CarbonImmutable::now()->subDay()->toDateString(),
            ]);
        $ok = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-sharia-authorities/'.$authorityPublicId.'/activate');
        $this->assertJsonSuccess($ok);
    }

    public function test_authority_activation_fails_with_archived_document(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $approver = $this->createUserWithRole('platform-admin');
        $chair = $this->createUserWithRole('platform-admin');
        $documentPublicId = $this->createDocument($actor);

        $created = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-sharia-authorities', $this->authorityPayload(['document_public_id' => $documentPublicId]));
        $authorityPublicId = $this->requireStringJsonPath($created, 'data.public_id');

        foreach ([['user' => $approver, 'role' => 'approver'], ['user' => $chair, 'role' => 'chair']] as $assignment) {
            $this->withApiHeaders()
                ->actingAsSanctum($actor)
                ->postJson('/api/v1/islamic-sharia-authorities/'.$authorityPublicId.'/members', [
                    'user_public_id' => $assignment['user']->public_id,
                    'member_role' => $assignment['role'],
                    'starts_on' => CarbonImmutable::now()->subDay()->toDateString(),
                ]);
        }

        DB::table('documents')->where('public_id', $documentPublicId)->update([
            'status' => 'archived',
            'archived_at' => now(),
        ]);

        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-sharia-authorities/'.$authorityPublicId.'/activate');
        $this->assertJsonError($response, 422);
    }

    public function test_membership_role_validation_rejects_unknown_role(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $member = $this->createUserWithRole('platform-admin');
        $documentPublicId = $this->createDocument($actor);

        $created = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-sharia-authorities', $this->authorityPayload(['document_public_id' => $documentPublicId]));
        $authorityPublicId = $this->requireStringJsonPath($created, 'data.public_id');

        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-sharia-authorities/'.$authorityPublicId.'/members', [
                'user_public_id' => $member->public_id,
                'member_role' => 'approvr',
                'starts_on' => CarbonImmutable::now()->subDay()->toDateString(),
            ]);
        $this->assertJsonError($response, 422);
    }

    public function test_membership_date_validation_rejects_inverted_range(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $member = $this->createUserWithRole('platform-admin');
        $documentPublicId = $this->createDocument($actor);

        $created = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-sharia-authorities', $this->authorityPayload(['document_public_id' => $documentPublicId]));
        $authorityPublicId = $this->requireStringJsonPath($created, 'data.public_id');

        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-sharia-authorities/'.$authorityPublicId.'/members', [
                'user_public_id' => $member->public_id,
                'member_role' => 'approver',
                'starts_on' => CarbonImmutable::now()->toDateString(),
                'ends_on' => CarbonImmutable::now()->subDay()->toDateString(),
            ]);
        $this->assertJsonError($response, 422);
    }

    public function test_observer_and_approver_cannot_coexist_for_same_user(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $member = $this->createUserWithRole('platform-admin');
        $documentPublicId = $this->createDocument($actor);

        $created = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-sharia-authorities', $this->authorityPayload(['document_public_id' => $documentPublicId]));
        $authorityPublicId = $this->requireStringJsonPath($created, 'data.public_id');

        $first = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-sharia-authorities/'.$authorityPublicId.'/members', [
                'user_public_id' => $member->public_id,
                'member_role' => 'observer',
                'starts_on' => CarbonImmutable::now()->subDay()->toDateString(),
            ]);
        $this->assertJsonSuccess($first, 201);

        $second = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-sharia-authorities/'.$authorityPublicId.'/members', [
                'user_public_id' => $member->public_id,
                'member_role' => 'approver',
                'starts_on' => CarbonImmutable::now()->subDay()->toDateString(),
            ]);
        $this->assertJsonError($second, 422);
    }

    public function test_lifecycle_changes_create_audit_records(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $approver = $this->createUserWithRole('platform-admin');
        $chair = $this->createUserWithRole('platform-admin');
        $authority = $this->createActiveAuthority($actor, [
            ['user' => $approver, 'role' => 'approver'],
            ['user' => $chair, 'role' => 'chair'],
        ]);

        foreach (['created', 'activated', 'member_added'] as $event) {
            $this->assertDatabaseHas('activity_log', [
                'log_name' => 'security',
                'event' => 'islamic.sharia_authority.'.$event,
            ]);
        }

        $approverMemberPublicId = $this->memberPublicId($authority['public_id'], $approver->id);
        $suspend = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-sharia-authorities/'.$authority['public_id'].'/members/'.$approverMemberPublicId.'/suspend', [
                'reason' => 'audit trail test',
            ]);
        $this->assertJsonSuccess($suspend);
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'security',
            'event' => 'islamic.sharia_authority.member_suspended',
        ]);
    }

    public function test_non_admin_cannot_mutate_authority_setup(): void
    {
        $admin = $this->createUserWithRole('platform-admin');
        $documentPublicId = $this->createDocument($admin);
        $created = $this->withApiHeaders()
            ->actingAsSanctum($admin)
            ->postJson('/api/v1/islamic-sharia-authorities', $this->authorityPayload(['document_public_id' => $documentPublicId]));
        $authorityPublicId = $this->requireStringJsonPath($created, 'data.public_id');

        $nonAdmin = User::factory()->createOne([
            'status' => User::STATUS_ACTIVE,
            'phone_verified_at' => now(),
        ]);

        $cases = [
            ['post', '/api/v1/islamic-sharia-authorities', $this->authorityPayload(['document_public_id' => 'x'])],
            ['put', '/api/v1/islamic-sharia-authorities/'.$authorityPublicId, ['name' => 'attempt']],
            ['post', '/api/v1/islamic-sharia-authorities/'.$authorityPublicId.'/activate', []],
            ['post', '/api/v1/islamic-sharia-authorities/'.$authorityPublicId.'/members', ['user_public_id' => 'x', 'member_role' => 'approver', 'starts_on' => '2026-01-01']],
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
        $approver = $this->createUserWithRole('platform-admin');
        $chair = $this->createUserWithRole('platform-admin');
        $authority = $this->createActiveAuthority($actor, [
            ['user' => $approver, 'role' => 'approver'],
            ['user' => $chair, 'role' => 'chair'],
        ]);

        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->getJson('/api/v1/islamic-sharia-authorities/'.$authority['public_id']);
        $this->assertJsonSuccess($response);
        $data = $response->json('data');
        self::assertIsArray($data);
        self::assertArrayNotHasKey('id', $data);
        self::assertArrayNotHasKey('document_id', $data);
        self::assertArrayNotHasKey('created_by_user_id', $data);
        $members = $data['active_members'] ?? null;
        self::assertIsArray($members);
        foreach ($members as $m) {
            self::assertIsArray($m);
            self::assertArrayNotHasKey('id', $m);
            self::assertArrayNotHasKey('user_id', $m);
            self::assertArrayHasKey('user_public_id', $m);
        }
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

    private function createDocument(User $uploader): string
    {
        $publicId = (string) Str::ulid();
        DB::table('documents')->insert([
            'public_id' => $publicId,
            'agency_id' => $this->agencyId(),
            'uploaded_by_user_id' => $uploader->id,
            'category' => 'sharia_authority',
            'title' => 'Evidence',
            'disk' => 'local',
            'path' => 'documents/'.$publicId,
            'original_name' => 'mandate.pdf',
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
        $existing = DB::table('agencies')->where('code', 'IF010')->first(['id']);
        if (is_object($existing) && is_numeric($existing->id)) {
            return (int) $existing->id;
        }

        return DB::table('agencies')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'code' => 'IF010',
            'name' => 'IF-010 Agency',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function authorityPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Habis Sharia Board',
            'authority_type' => 'board',
            'jurisdiction' => 'institution',
            'mandate_scope' => ['type' => 'institution'],
            'mandate_summary' => 'Governs Sharia compliance across all Islamic products.',
            'effective_date' => CarbonImmutable::now()->subDays(2)->toDateString(),
        ], $overrides);
    }

    /**
     * @param  array<int, array{user: User, role: string, starts_on?: string, ends_on?: string|null}>  $members
     * @param  array<string, mixed>|null  $scope
     * @return array{public_id: string, document_public_id: string}
     */
    private function createActiveAuthority(User $actor, array $members, ?array $scope = null): array
    {
        $documentPublicId = $this->createDocument($actor);
        $payload = $this->authorityPayload(['document_public_id' => $documentPublicId]);
        if ($scope !== null) {
            $payload['mandate_scope'] = $scope;
        }

        $created = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-sharia-authorities', $payload);
        $this->assertJsonSuccess($created, 201);
        $authorityPublicId = $this->requireStringJsonPath($created, 'data.public_id');

        foreach ($members as $m) {
            $memberPayload = [
                'user_public_id' => $m['user']->public_id,
                'member_role' => $m['role'],
                'starts_on' => $m['starts_on'] ?? CarbonImmutable::now()->subDay()->toDateString(),
            ];
            if (array_key_exists('ends_on', $m)) {
                $memberPayload['ends_on'] = $m['ends_on'];
            }
            $this->withApiHeaders()
                ->actingAsSanctum($actor)
                ->postJson('/api/v1/islamic-sharia-authorities/'.$authorityPublicId.'/members', $memberPayload);
        }

        $activate = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/islamic-sharia-authorities/'.$authorityPublicId.'/activate');
        $this->assertJsonSuccess($activate);

        return ['public_id' => $authorityPublicId, 'document_public_id' => $documentPublicId];
    }

    private function memberPublicId(string $authorityPublicId, int $userId): string
    {
        $row = DB::table('islamic_sharia_authority_members as m')
            ->join('islamic_sharia_authorities as a', 'a.id', '=', 'm.islamic_sharia_authority_id')
            ->where('a.public_id', $authorityPublicId)
            ->where('m.user_id', $userId)
            ->where('m.status', 'active')
            ->orderByDesc('m.id')
            ->value('m.public_id');
        self::assertIsString($row);

        return $row;
    }

    private function ensureMourabahaReadinessBaseline(User $actor): void
    {
        // Set up standards + sign-off baselines so the readiness gate passes and
        // we can specifically isolate the authority gate behavior.
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
