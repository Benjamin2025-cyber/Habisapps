<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StaffUserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_platform_admin_can_create_staff_user_with_multi_channel_activation_otp(): void
    {
        $admin = $this->createUserWithRole('platform-admin');
        $agencyId = DB::table('agencies')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'code' => 'AKW',
            'name' => 'Akwa',
            'status' => 'active',
        ]);
        $agencyPublicId = DB::table('agencies')->where('id', $agencyId)->value('public_id');

        $response = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$admin->createToken('test-token')->plainTextToken])
            ->postJson('/api/v1/staff-users', [
                'name' => 'Branch Cashier',
                'phone_number' => '+237699200001',
                'email' => 'cashier@example.com',
                'matricule' => 'STF-001',
                'job_title' => 'Cashier',
                'agency_code' => 'AKW',
                'agency_name' => 'Akwa',
            ]);

        $this->assertJsonSuccess($response, 201);
        $response->assertJsonPath('message', 'Staff user created successfully');
        $response->assertJsonPath('data.phone_number', '+237699200001');
        $response->assertJsonPath('data.agency_public_id', $agencyPublicId);
        $response->assertJsonMissingPath('data.agency_id');
        $response->assertJsonPath('data.status', User::STATUS_PENDING_VERIFICATION);
        $response->assertJsonPath('data.roles.0', 'staff');
        $response->assertJsonMissingPath('data.id');

        $this->assertDatabaseHas('users', [
            'phone_number' => '+237699200001',
            'agency_id' => $agencyId,
            'status' => User::STATUS_PENDING_VERIFICATION,
            'invited_by_user_id' => $admin->id,
        ]);
        $this->assertDatabaseHas('staff_agency_assignments', [
            'agency_id' => $agencyId,
            'role_at_agency' => 'cashier',
            'is_primary' => true,
            'status' => 'active',
        ]);
        $this->assertDatabaseCount('otp_challenges', 1);
        $this->assertDatabaseHas('otp_deliveries', [
            'channel' => 'sms',
            'destination_masked' => '*********0001',
            'status' => 'sent',
            'provider_reference' => 'test-delivery',
        ]);
        $this->assertDatabaseHas('otp_deliveries', [
            'channel' => 'email',
            'destination_masked' => 'c***@example.com',
            'status' => 'sent',
            'provider_reference' => 'mail-delivery',
        ]);
        $this->assertDatabaseMissing('otp_deliveries', [
            'provider_reference' => 'test-123456',
        ]);
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'security',
            'event' => 'staff.created',
        ]);
    }

    public function test_staff_without_user_creation_permission_cannot_create_staff_user(): void
    {
        $staff = $this->createUserWithRole('staff');

        $response = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$staff->createToken('test-token')->plainTextToken])
            ->postJson('/api/v1/staff-users', [
                'name' => 'Unauthorized Staff',
                'phone_number' => '+237699200002',
            ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('users', [
            'phone_number' => '+237699200002',
        ]);
    }

    public function test_agency_manager_can_only_list_staff_in_their_current_agency(): void
    {
        $agencyA = $this->createAgency('AG-A');
        $agencyB = $this->createAgency('AG-B');
        $actor = $this->createUserWithRole('agency-manager', $agencyA);
        $sameAgencyStaff = $this->createUserWithRole('staff', $agencyA);
        $otherAgencyStaff = $this->createUserWithRole('staff', $agencyB);

        $response = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('test-token')->plainTextToken])
            ->getJson('/api/v1/staff-users');

        $this->assertJsonSuccess($response);
        $response->assertJsonFragment(['public_id' => $actor->public_id]);
        $response->assertJsonFragment(['public_id' => $sameAgencyStaff->public_id]);
        $response->assertJsonMissing(['public_id' => $otherAgencyStaff->public_id]);
    }

    public function test_staff_index_supports_server_side_search(): void
    {
        $agency = $this->createAgency('AG-SEARCH');
        $actor = $this->createUserWithRole('platform-admin');

        $target = $this->createUserWithRole('staff', $agency);
        $target->forceFill([
            'name' => 'Searchable Mbarga',
            'matricule' => 'MAT-7788',
            'phone_number' => '+237699123456',
            'email' => 'mbarga.search@example.test',
        ])->save();

        $other = $this->createUserWithRole('staff', $agency);
        $other->forceFill([
            'name' => 'Unrelated Person',
            'matricule' => 'MAT-0001',
            'phone_number' => '+237688000000',
            'email' => 'other.person@example.test',
        ])->save();

        foreach (['Mbarga', 'MAT-7788', '699123456', 'mbarga.search'] as $term) {
            $response = $this
                ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('staff-search')->plainTextToken])
                ->getJson('/api/v1/staff-users?search='.$term);
            $this->assertJsonSuccess($response);
            $response->assertJsonFragment(['public_id' => $target->public_id]);
            $response->assertJsonMissing(['public_id' => $other->public_id]);
        }

        // Blank search returns the unfiltered list (target still present).
        $blank = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('staff-search-blank')->plainTextToken])
            ->getJson('/api/v1/staff-users?search=');
        $this->assertJsonSuccess($blank);
        $blank->assertJsonFragment(['public_id' => $target->public_id]);
        $blank->assertJsonFragment(['public_id' => $other->public_id]);
    }

    public function test_staff_search_is_constrained_to_manager_agency(): void
    {
        $agencyA = $this->createAgency('AG-SRCH-A');
        $agencyB = $this->createAgency('AG-SRCH-B');
        $manager = $this->createUserWithRole('agency-manager', $agencyA);

        $foreign = $this->createUserWithRole('staff', $agencyB);
        $foreign->forceFill(['name' => 'Shared Name Token'])->save();
        $local = $this->createUserWithRole('staff', $agencyA);
        $local->forceFill(['name' => 'Shared Name Token'])->save();

        $response = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$manager->createToken('scoped-search')->plainTextToken])
            ->getJson('/api/v1/staff-users?search=Shared Name Token');
        $this->assertJsonSuccess($response);
        $response->assertJsonFragment(['public_id' => $local->public_id]);
        $response->assertJsonMissing(['public_id' => $foreign->public_id]);
    }

    public function test_staff_listing_uses_active_assignment_not_cached_user_agency(): void
    {
        $agencyA = $this->createAgency('AG-G');
        $agencyB = $this->createAgency('AG-H');
        $actor = $this->createUserWithRole('agency-manager', $agencyA);
        $visibleByAssignment = $this->createUserWithRole('staff', $agencyA);
        $hiddenByAssignment = $this->createUserWithRole('staff', $agencyB);

        $visibleByAssignment->forceFill(['agency_id' => $agencyB])->save();
        $hiddenByAssignment->forceFill(['agency_id' => $agencyA])->save();

        $response = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('test-token')->plainTextToken])
            ->getJson('/api/v1/staff-users');

        $this->assertJsonSuccess($response);
        $response->assertJsonFragment(['public_id' => $visibleByAssignment->public_id]);
        $response->assertJsonMissing(['public_id' => $hiddenByAssignment->public_id]);
    }

    public function test_agency_manager_cannot_update_staff_in_another_agency(): void
    {
        $agencyA = $this->createAgency('AG-C');
        $agencyB = $this->createAgency('AG-D');
        $actor = $this->createUserWithRole('agency-manager', $agencyA);
        $target = $this->createUserWithRole('staff', $agencyB);

        $response = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('test-token')->plainTextToken])
            ->patchJson('/api/v1/staff-users/'.$target->public_id, [
                'name' => 'Cross Agency Edit',
            ]);

        $response->assertForbidden();
        self::assertNotSame('Cross Agency Edit', $target->refresh()->name);
    }

    public function test_agency_manager_can_create_staff_only_inside_their_agency(): void
    {
        $agencyA = $this->createAgency('AG-E');
        $agencyB = $this->createAgency('AG-F');
        $actor = $this->createUserWithRole('agency-manager', $agencyA);

        $forbiddenResponse = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('test-token')->plainTextToken])
            ->postJson('/api/v1/staff-users', [
                'name' => 'Wrong Agency Staff',
                'phone_number' => '+237699200020',
                'agency_code' => 'AG-F',
            ]);

        $forbiddenResponse->assertForbidden();
        $this->assertDatabaseMissing('users', [
            'phone_number' => '+237699200020',
        ]);

        $allowedResponse = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('second-token')->plainTextToken])
            ->postJson('/api/v1/staff-users', [
                'name' => 'Same Agency Staff',
                'phone_number' => '+237699200021',
                'agency_code' => 'AG-E',
            ]);

        $this->assertJsonSuccess($allowedResponse, 201);
        $this->assertDatabaseHas('users', [
            'phone_number' => '+237699200021',
            'agency_id' => $agencyA,
        ]);
        $this->assertDatabaseMissing('users', [
            'phone_number' => '+237699200021',
            'agency_id' => $agencyB,
        ]);
    }

    public function test_unauthenticated_staff_routes_return_clean_json_without_debug_details(): void
    {
        $response = $this
            ->withHeaders([
                'Accept' => '*/*',
                'X-API-Version' => '1',
            ])
            ->get('/api/v1/staff-users');

        $this->assertJsonError($response, 401, 'Unauthenticated.');
        $response->assertJsonMissingPath('errors.exception');
        $response->assertJsonMissingPath('errors.file');
        $response->assertJsonMissingPath('errors.trace');
        $response->assertHeaderMissing('X-Powered-By');
    }

    public function test_user_admin_cannot_grant_platform_admin_role(): void
    {
        $actor = $this->createUserWithRole('user-admin');
        $target = $this->createUserWithRole('staff');

        $response = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('test-token')->plainTextToken])
            ->putJson('/api/v1/staff-users/'.$target->public_id.'/roles', [
                'roles' => ['platform-admin'],
            ]);

        $response->assertForbidden();
        self::assertFalse($target->refresh()->hasRole('platform-admin'));
    }

    public function test_platform_admin_can_update_staff_roles(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $target = $this->createUserWithRole('staff');

        $response = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('test-token')->plainTextToken])
            ->putJson('/api/v1/staff-users/'.$target->public_id.'/roles', [
                'roles' => ['auditor'],
            ]);

        $this->assertJsonSuccess($response);
        $response->assertJsonPath('data.roles.0', 'auditor');
        self::assertTrue($target->refresh()->hasRole('auditor'));
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'security',
            'event' => 'staff.roles_changed',
        ]);
    }

    public function test_staff_role_assignment_is_visible_to_subsequent_gets_without_restart(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agencyId = $this->createAgency('AG-I');

        $createResponse = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('create-token')->plainTextToken])
            ->postJson('/api/v1/staff-users', [
                'name' => 'Fresh Teller',
                'phone_number' => '+237699200022',
                'agency_code' => 'AG-I',
            ]);

        $this->assertJsonSuccess($createResponse, 201);
        $staffPublicId = $createResponse->json('data.public_id');
        self::assertIsString($staffPublicId);

        $roleResponse = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('role-token')->plainTextToken])
            ->putJson('/api/v1/staff-users/'.$staffPublicId.'/roles', [
                'roles' => ['teller'],
            ]);

        $this->assertJsonSuccess($roleResponse);
        $roleResponse->assertJsonPath('data.roles.0', 'teller');
        $rolePermissions = $roleResponse->json('data.permissions');
        self::assertIsArray($rolePermissions);
        self::assertContains('cash.transactions.manage', $rolePermissions);

        $indexResponse = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('index-token')->plainTextToken])
            ->getJson('/api/v1/staff-users');

        $this->assertJsonSuccess($indexResponse);
        $indexResponse->assertJsonFragment(['public_id' => $staffPublicId]);
        $indexResponse->assertJsonFragment(['roles' => ['teller']]);

        $showResponse = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('show-token')->plainTextToken])
            ->getJson('/api/v1/staff-users/'.$staffPublicId);

        $this->assertJsonSuccess($showResponse);
        $showResponse->assertJsonPath('data.roles.0', 'teller');
        $showPermissions = $showResponse->json('data.permissions');
        self::assertIsArray($showPermissions);
        self::assertContains('cash.transactions.manage', $showPermissions);
        self::assertSame($agencyId, User::query()->where('public_id', $staffPublicId)->value('agency_id'));
    }

    public function test_only_active_platform_admin_cannot_be_demoted_or_suspended(): void
    {
        $actor = $this->createUserWithRole('platform-admin');

        $demotionResponse = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('test-token')->plainTextToken])
            ->putJson('/api/v1/staff-users/'.$actor->public_id.'/roles', [
                'roles' => ['staff'],
            ]);

        $this->assertJsonError($demotionResponse, 422, 'At least one active platform administrator must remain.');
        self::assertTrue($actor->refresh()->hasRole('platform-admin'));

        $suspensionResponse = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('second-token')->plainTextToken])
            ->patchJson('/api/v1/staff-users/'.$actor->public_id.'/status', [
                'status' => User::STATUS_SUSPENDED,
            ]);

        $this->assertJsonError($suspensionResponse, 422, 'At least one active platform administrator must remain.');
        self::assertSame(User::STATUS_ACTIVE, $actor->refresh()->status);
    }

    public function test_user_admin_cannot_suspend_platform_admin(): void
    {
        $actor = $this->createUserWithRole('user-admin');
        $target = $this->createUserWithRole('platform-admin');

        $response = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('test-token')->plainTextToken])
            ->patchJson('/api/v1/staff-users/'.$target->public_id.'/status', [
                'status' => User::STATUS_SUSPENDED,
            ]);

        $response->assertForbidden();
        self::assertSame(User::STATUS_ACTIVE, $target->refresh()->status);
    }

    public function test_suspending_staff_revokes_existing_tokens(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $target = $this->createUserWithRole('staff');
        $target->createToken('mobile-device');

        $response = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('test-token')->plainTextToken])
            ->patchJson('/api/v1/staff-users/'.$target->public_id.'/status', [
                'status' => User::STATUS_SUSPENDED,
            ]);

        $this->assertJsonSuccess($response);
        $response->assertJsonPath('data.status', User::STATUS_SUSPENDED);
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $target->id,
            'tokenable_type' => User::class,
        ]);
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'security',
            'event' => 'staff.status_changed',
        ]);
    }

    public function test_staff_user_creation_is_not_blocked_by_the_accounting_day_lock(): void
    {
        // Identity & access administration must remain available even when the
        // institution has no open accounting day and auto-open is disabled
        // (production behaviour). The route is allowlisted out of the lock.
        config(['security.accounting_day.auto_open_on_missing' => false]);

        $this->createAgency('NLK');
        $admin = $this->createUserWithRole('platform-admin');

        $response = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$admin->createToken('test-token')->plainTextToken])
            ->postJson('/api/v1/staff-users', [
                'name' => 'Branch Cashier',
                'phone_number' => '+237699200099',
                'email' => 'no-lock@example.com',
                'matricule' => 'STF-099',
                'job_title' => 'Cashier',
                'agency_code' => 'NLK',
                'agency_name' => 'No Lock',
            ]);

        $this->assertJsonSuccess($response, 201);
        $response->assertJsonPath('data.phone_number', '+237699200099');

        // The lock never ran: no accounting day was consulted or auto-opened.
        self::assertSame(0, DB::table('accounting_days')->count());
        $this->assertDatabaseMissing('activity_log', [
            'event' => 'accounting_day.registration_blocked',
        ]);
    }

    public function test_platform_admin_receives_institution_wide_status_counts(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('SC-ADMIN');

        $this->createStaffWithStatus($agency, User::STATUS_PENDING_VERIFICATION);
        $this->createStaffWithStatus($agency, User::STATUS_PENDING_VERIFICATION);
        $this->createStaffWithStatus($agency, User::STATUS_SUSPENDED);
        $this->createStaffWithStatus($agency, User::STATUS_DEACTIVATED);
        $this->createStaffWithStatus($agency, User::STATUS_ACTIVE);
        $this->createStaffWithStatus($agency, User::STATUS_ACTIVE);

        $response = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('sc')->plainTextToken])
            ->getJson('/api/v1/staff-users?per_page=100');

        $this->assertJsonSuccess($response);
        // Six created staff plus the acting platform admin (active, no agency).
        $response->assertJsonPath('meta.status_counts.total', 7);
        $response->assertJsonPath('meta.status_counts.active', 3);
        $response->assertJsonPath('meta.status_counts.pending_verification', 2);
        $response->assertJsonPath('meta.status_counts.suspended', 1);
        $response->assertJsonPath('meta.status_counts.deactivated', 1);
    }

    public function test_agency_scoped_actor_receives_only_their_agency_status_counts(): void
    {
        $agencyA = $this->createAgency('SC-A');
        $agencyB = $this->createAgency('SC-B');
        $manager = $this->createUserWithRole('agency-manager', $agencyA);

        $this->createStaffWithStatus($agencyA, User::STATUS_ACTIVE);
        $this->createStaffWithStatus($agencyA, User::STATUS_SUSPENDED);
        $this->createStaffWithStatus($agencyB, User::STATUS_ACTIVE);

        $response = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$manager->createToken('sc')->plainTextToken])
            ->getJson('/api/v1/staff-users?per_page=100');

        $this->assertJsonSuccess($response);
        // Manager (active) + one active + one suspended in agency A; agency B excluded.
        $response->assertJsonPath('meta.status_counts.total', 3);
        $response->assertJsonPath('meta.status_counts.active', 2);
        $response->assertJsonPath('meta.status_counts.suspended', 1);
        $response->assertJsonPath('meta.status_counts.deactivated', 0);
    }

    public function test_status_filter_narrows_page_but_not_full_status_counts(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('SC-FILTER');

        $this->createStaffWithStatus($agency, User::STATUS_ACTIVE);
        $this->createStaffWithStatus($agency, User::STATUS_ACTIVE);
        $this->createStaffWithStatus($agency, User::STATUS_SUSPENDED);

        $response = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('sc')->plainTextToken])
            ->getJson('/api/v1/staff-users?filter[status]=suspended&per_page=100');

        $this->assertJsonSuccess($response);
        // Page is narrowed to the single suspended user.
        $response->assertJsonPath('meta.pagination.total', 1);
        // Full scoped counts are unaffected by the filter (3 created + acting admin).
        $response->assertJsonPath('meta.status_counts.total', 4);
        $response->assertJsonPath('meta.status_counts.active', 3);
        $response->assertJsonPath('meta.status_counts.suspended', 1);
    }

    public function test_invalid_status_filter_returns_validation_error(): void
    {
        $actor = $this->createUserWithRole('platform-admin');

        $response = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('sc')->plainTextToken])
            ->getJson('/api/v1/staff-users?filter[status]=not_a_real_status');

        $this->assertJsonError($response, 422);
    }

    private function createStaffWithStatus(int $agencyId, string $status): User
    {
        $user = User::factory()->createOne([
            'status' => $status,
            'phone_verified_at' => $status === User::STATUS_PENDING_VERIFICATION ? null : now(),
            'agency_id' => $agencyId,
        ]);
        $user->assignRole('staff');

        DB::table('staff_agency_assignments')->insert([
            'public_id' => (string) Str::ulid(),
            'user_id' => $user->id,
            'agency_id' => $agencyId,
            'role_at_agency' => 'staff',
            'starts_on' => now()->toDateString(),
            'is_primary' => true,
            'status' => 'active',
        ]);

        return $user;
    }

    private function createUserWithRole(string $role, ?int $agencyId = null): User
    {
        $user = User::factory()->createOne([
            'status' => User::STATUS_ACTIVE,
            'phone_verified_at' => now(),
            'agency_id' => $agencyId,
        ]);

        $user->assignRole($role);

        if ($agencyId !== null) {
            DB::table('staff_agency_assignments')->insert([
                'public_id' => (string) Str::ulid(),
                'user_id' => $user->id,
                'agency_id' => $agencyId,
                'role_at_agency' => $role,
                'starts_on' => now()->toDateString(),
                'is_primary' => true,
                'status' => 'active',
            ]);
        }

        return $user;
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
}
