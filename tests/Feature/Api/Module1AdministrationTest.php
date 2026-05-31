<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Application\Notifications\NotificationDeliveryRetryManager;
use App\Models\Agency;
use App\Models\BatchProcedure;
use App\Models\BatchRun;
use App\Models\JournalEntry;
use App\Models\StaffAgencyAssignment;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class Module1AdministrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_platform_admin_can_create_and_manage_agency_and_manager_assignment(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $manager = $this->createUserWithRole('staff');

        $createResponse = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('test-token')->plainTextToken])
            ->postJson('/api/v1/agencies', [
                'code' => 'AG-M1',
                'name' => 'Module One Agency',
                'region' => 'Center',
                'city' => 'Yaounde',
                'branch_name' => 'Messa Branch',
                'branch_type' => 'urban',
                'phone_number' => '+237600000001',
                'fax_number' => '+237222000001',
                'address_line_1' => 'Avenue Kennedy',
                'address_line_2' => 'Near central market',
                'po_box' => 'BP 100 Yaounde',
                'geographic_description' => 'Ground floor of the commercial building opposite the main roundabout.',
                'status' => Agency::STATUS_ACTIVE,
            ]);

        $this->assertJsonSuccess($createResponse, 201);
        $agencyPublicId = $this->requireStringJsonPath($createResponse, 'data.public_id');
        $createResponse->assertJsonPath('data.code', 'AG-M1');
        $createResponse->assertJsonPath('data.branch_type', 'urban');
        $createResponse->assertJsonPath('data.fax_number', '+237222000001');
        $createResponse->assertJsonPath('data.po_box', 'BP 100 Yaounde');
        $createResponse->assertJsonPath('data.geographic_description', 'Ground floor of the commercial building opposite the main roundabout.');

        $agency = Agency::query()->where('public_id', $agencyPublicId)->firstOrFail();

        $this->assertDatabaseHas('agencies', [
            'id' => $agency->id,
            'branch_type' => 'urban',
            'fax_number' => '+237222000001',
            'po_box' => 'BP 100 Yaounde',
        ]);

        $updateResponse = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('test-token')->plainTextToken])
            ->patchJson('/api/v1/agencies/'.$agency->public_id, [
                'branch_type' => 'rural',
                'fax_number' => null,
                'po_box' => 'BP 200 Yaounde',
                'geographic_description' => 'Inside the municipal complex.',
            ]);

        $this->assertJsonSuccess($updateResponse);
        $updateResponse->assertJsonPath('data.branch_type', 'rural');
        $updateResponse->assertJsonPath('data.fax_number', null);
        $updateResponse->assertJsonPath('data.po_box', 'BP 200 Yaounde');
        $updateResponse->assertJsonPath('data.geographic_description', 'Inside the municipal complex.');

        $managerResponse = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('test-token')->plainTextToken])
            ->putJson('/api/v1/agencies/'.$agency->public_id.'/manager', [
                'manager_public_id' => $manager->public_id,
                'role_at_agency' => 'agency-manager',
            ]);

        $this->assertJsonSuccess($managerResponse);
        $managerResponse->assertJsonPath('data.manager_public_id', $manager->public_id);
        $this->assertDatabaseHas('staff_agency_assignments', [
            'user_id' => $manager->id,
            'agency_id' => $agency->id,
            'role_at_agency' => 'agency-manager',
            'is_primary' => true,
            'status' => 'active',
        ]);

        $archiveResponse = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('test-token')->plainTextToken])
            ->deleteJson('/api/v1/agencies/'.$agency->public_id);

        $this->assertJsonSuccess($archiveResponse);
        $archiveResponse->assertJsonPath('data.status', Agency::STATUS_ARCHIVED);
    }

    public function test_staff_professional_profile_is_handled_through_hr_handoff_without_sensitive_leakage(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('AG-P1');
        $supervisor = $this->createUserWithRole('staff', $agency['code'], $agency['name']);

        $createResponse = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('test-token')->plainTextToken])
            ->postJson('/api/v1/staff-users', [
                'name' => 'Amina Credit Officer',
                'phone_number' => '+237699111222',
                'email' => 'amina.credit@example.test',
                'matricule' => 'STAFF-001',
                'job_title' => 'Credit Officer',
                'gender' => 'female',
                'birth_date' => '1990-03-14',
                'birth_place' => 'Yaounde',
                'service_name' => 'Credit',
                'supervisor_public_id' => $supervisor->public_id,
                'portfolio_code' => 'PF-CREDIT-01',
                'agency_code' => $agency['code'],
            ]);

        $this->assertJsonSuccess($createResponse, 201);
        $staffPublicId = $this->requireStringJsonPath($createResponse, 'data.public_id');
        $createResponse->assertJsonPath('data.professional_profile.gender', 'female');
        $createResponse->assertJsonPath('data.professional_profile.birth_date', '1990-03-14');
        $createResponse->assertJsonPath('data.professional_profile.birth_place', 'Yaounde');
        $createResponse->assertJsonPath('data.professional_profile.job_title', 'Credit Officer');
        $createResponse->assertJsonPath('data.professional_profile.service_name', 'Credit');
        $createResponse->assertJsonPath('data.professional_profile.supervisor_public_id', $supervisor->public_id);
        $createResponse->assertJsonPath('data.professional_profile.portfolio_code', 'PF-CREDIT-01');
        $createResponse->assertJsonPath('data.professional_profile.source', 'hr_handoff');
        $createResponse->assertJsonMissingPath('data.professional_profile.identity_number');
        $createResponse->assertJsonMissingPath('data.professional_profile.base_salary_minor');
        $createResponse->assertJsonMissingPath('data.professional_profile.emergency_contact_phone');
        $createResponse->assertJsonMissingPath('data.professional_profile.professional_history');

        $staff = User::query()->where('public_id', $staffPublicId)->firstOrFail();
        $this->assertDatabaseHas('hr_employees', [
            'user_id' => $staff->id,
            'agency_id' => $agency['id'],
            'supervisor_id' => $supervisor->id,
            'employee_number' => 'STAFF-001',
            'first_name' => 'Amina',
            'last_name' => 'Credit Officer',
            'gender' => 'female',
            'birth_place' => 'Yaounde',
            'job_title' => 'Credit Officer',
            'service_name' => 'Credit',
            'portfolio_code' => 'PF-CREDIT-01',
        ]);

        $updateResponse = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('test-token')->plainTextToken])
            ->patchJson('/api/v1/staff-users/'.$staffPublicId, [
                'service_name' => 'Recovery',
                'portfolio_code' => 'PF-REC-02',
            ]);

        $this->assertJsonSuccess($updateResponse);
        $updateResponse->assertJsonPath('data.professional_profile.service_name', 'Recovery');
        $updateResponse->assertJsonPath('data.professional_profile.portfolio_code', 'PF-REC-02');
    }

    public function test_agency_manager_can_only_view_own_agency(): void
    {
        $agencyA = $this->createAgency('AG-A1');
        $agencyB = $this->createAgency('AG-B1');
        $actor = $this->createUserWithRole('agency-manager', $agencyA['code'], $agencyA['name']);

        $ownResponse = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('test-token')->plainTextToken])
            ->getJson('/api/v1/agencies/'.$agencyA['public_id']);

        $this->assertJsonSuccess($ownResponse);
        $ownResponse->assertJsonPath('data.public_id', $agencyA['public_id']);

        $forbiddenResponse = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('test-token')->plainTextToken])
            ->getJson('/api/v1/agencies/'.$agencyB['public_id']);

        $forbiddenResponse->assertForbidden();
    }

    public function test_agency_index_supports_server_side_search(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $this->createAgency('AKWA-001', 'Akwa Douala Branch');
        $this->createAgency('BONA-002', 'Bonanjo Branch');
        $this->createAgency('YDE-003', 'Yaounde Centre');

        // Match by code.
        $byCode = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('search-1')->plainTextToken])
            ->getJson('/api/v1/agencies?search=AKWA');
        $this->assertJsonSuccess($byCode);
        $byCode->assertJsonPath('meta.pagination.total', 1);
        $byCode->assertJsonPath('data.agencies.0.code', 'AKWA-001');

        // Match by name (case-insensitive).
        $byName = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('search-2')->plainTextToken])
            ->getJson('/api/v1/agencies?search=bonanjo');
        $this->assertJsonSuccess($byName);
        $byName->assertJsonPath('meta.pagination.total', 1);
        $byName->assertJsonPath('data.agencies.0.code', 'BONA-002');

        // Blank search is ignored: all agencies returned, pagination stable.
        $blank = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('search-3')->plainTextToken])
            ->getJson('/api/v1/agencies?search=');
        $this->assertJsonSuccess($blank);
        $blank->assertJsonPath('meta.pagination.total', 3);
    }

    public function test_agency_search_never_leaks_outside_non_platform_scope(): void
    {
        $agencyA = $this->createAgency('SCOPE-AKWA', 'Scope Akwa');
        $this->createAgency('OTHER-AKWA', 'Other Akwa');
        $manager = $this->createUserWithRole('agency-manager', $agencyA['code'], $agencyA['name']);

        // A matching term spanning both agencies still only returns the
        // manager's own agency.
        $response = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$manager->createToken('scope-1')->plainTextToken])
            ->getJson('/api/v1/agencies?search=AKWA');
        $this->assertJsonSuccess($response);
        $response->assertJsonPath('meta.pagination.total', 1);
        $response->assertJsonPath('data.agencies.0.code', 'SCOPE-AKWA');
    }

    public function test_staff_assignment_transfer_preserves_history_and_replaces_primary_assignment(): void
    {
        $agencyA = $this->createAgency('AG-C1');
        $agencyB = $this->createAgency('AG-D1');
        $actor = $this->createUserWithRole('platform-admin');
        $staff = $this->createUserWithRole('staff', $agencyA['code'], $agencyA['name']);

        $existingAssignment = StaffAgencyAssignment::query()
            ->where('user_id', $staff->id)
            ->where('agency_id', $agencyA['id'])
            ->firstOrFail();

        $response = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('test-token')->plainTextToken])
            ->postJson('/api/v1/staff-users/'.$staff->public_id.'/assignments', [
                'agency_code' => $agencyB['code'],
                'role_at_agency' => 'cashier',
                'starts_on' => '2026-06-01',
                'is_primary' => true,
                'transfer_from_assignment_public_id' => $existingAssignment->public_id,
                'reason' => 'Transfer to new branch',
            ]);

        $this->assertJsonSuccess($response, 201);
        $newAssignmentPublicId = $this->requireStringJsonPath($response, 'data.public_id');
        $response->assertJsonPath('data.agency_code', $agencyB['code']);

        self::assertSame('ended', $existingAssignment->refresh()->status);
        $this->assertDatabaseHas('staff_agency_assignments', [
            'public_id' => $newAssignmentPublicId,
            'user_id' => $staff->id,
            'agency_id' => $agencyB['id'],
            'role_at_agency' => 'cashier',
            'is_primary' => true,
            'status' => 'active',
        ]);

        $historyResponse = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('test-token')->plainTextToken])
            ->getJson('/api/v1/staff-users/'.$staff->public_id.'/assignments');

        $this->assertJsonSuccess($historyResponse);
        $historyResponse->assertJsonCount(2, 'data.assignments');
    }

    public function test_agency_manager_cannot_access_assignment_history_or_mutate_other_agency_assignments(): void
    {
        $agencyA = $this->createAgency('AG-H1');
        $agencyB = $this->createAgency('AG-H2');
        $actor = $this->createUserWithRole('agency-manager', $agencyA['code'], $agencyA['name']);
        $staff = $this->createUserWithRole('staff', $agencyA['code'], $agencyA['name']);

        $otherAssignmentPublicId = (string) Str::ulid();
        DB::table('staff_agency_assignments')->insert([
            'public_id' => $otherAssignmentPublicId,
            'user_id' => $staff->id,
            'agency_id' => $agencyB['id'],
            'role_at_agency' => 'cashier',
            'starts_on' => '2026-01-01',
            'ends_on' => '2026-01-15',
            'is_primary' => false,
            'status' => 'ended',
        ]);

        $historyResponse = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('test-token')->plainTextToken])
            ->getJson('/api/v1/staff-users/'.$staff->public_id.'/assignments');

        $this->assertJsonSuccess($historyResponse);
        $historyResponse->assertJsonCount(1, 'data.assignments');
        $historyResponse->assertJsonMissing(['public_id' => $otherAssignmentPublicId]);

        $updateResponse = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('test-token')->plainTextToken])
            ->patchJson('/api/v1/staff-users/'.$staff->public_id.'/assignments/'.$otherAssignmentPublicId, [
                'ends_on' => '2026-01-31',
            ]);

        $updateResponse->assertForbidden();
    }

    public function test_agency_manager_cannot_transfer_from_an_assignment_in_another_agency(): void
    {
        $agencyA = $this->createAgency('AG-H5');
        $agencyB = $this->createAgency('AG-H6');
        $actor = $this->createUserWithRole('agency-manager', $agencyA['code'], $agencyA['name']);
        $staff = $this->createUserWithRole('staff', $agencyA['code'], $agencyA['name']);

        $otherAgencyAssignmentPublicId = (string) Str::ulid();
        DB::table('staff_agency_assignments')->insert([
            'public_id' => $otherAgencyAssignmentPublicId,
            'user_id' => $staff->id,
            'agency_id' => $agencyB['id'],
            'role_at_agency' => 'cashier',
            'starts_on' => '2026-01-01',
            'ends_on' => '2026-01-31',
            'is_primary' => false,
            'status' => 'ended',
        ]);

        $response = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('test-token')->plainTextToken])
            ->postJson('/api/v1/staff-users/'.$staff->public_id.'/assignments', [
                'agency_code' => $agencyA['code'],
                'role_at_agency' => 'cashier',
                'starts_on' => '2026-02-01',
                'is_primary' => false,
                'transfer_from_assignment_public_id' => $otherAgencyAssignmentPublicId,
            ]);

        $response->assertForbidden();
        $this->assertDatabaseHas('staff_agency_assignments', [
            'public_id' => $otherAgencyAssignmentPublicId,
            'agency_id' => $agencyB['id'],
            'status' => 'ended',
        ]);
    }

    public function test_same_day_primary_assignment_transfer_is_rejected_cleanly(): void
    {
        $agencyA = $this->createAgency('AG-H3');
        $agencyB = $this->createAgency('AG-H4');
        $actor = $this->createUserWithRole('platform-admin');
        $staff = $this->createUserWithRole('staff', $agencyA['code'], $agencyA['name']);

        $existingAssignment = StaffAgencyAssignment::query()
            ->where('user_id', $staff->id)
            ->where('agency_id', $agencyA['id'])
            ->firstOrFail();

        $response = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('test-token')->plainTextToken])
            ->postJson('/api/v1/staff-users/'.$staff->public_id.'/assignments', [
                'agency_code' => $agencyB['code'],
                'role_at_agency' => 'cashier',
                'starts_on' => now()->toDateString(),
                'is_primary' => true,
                'transfer_from_assignment_public_id' => $existingAssignment->public_id,
            ]);

        $this->assertJsonError($response, 422, 'Validation failed');
        $response->assertJsonPath('errors.starts_on.0', 'Primary assignment transfers must start after the current primary assignment starts.');
        self::assertSame('active', $existingAssignment->refresh()->status);
        $this->assertDatabaseMissing('staff_agency_assignments', [
            'agency_id' => $agencyB['id'],
            'user_id' => $staff->id,
        ]);
    }

    public function test_role_catalog_and_role_permissions_can_be_updated(): void
    {
        $actor = $this->createUserWithRole('platform-admin');

        $catalogResponse = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('test-token')->plainTextToken])
            ->getJson('/api/v1/roles');

        $this->assertJsonSuccess($catalogResponse);
        $catalogResponse->assertJsonFragment(['name' => 'platform-admin']);
        $catalogResponse->assertJsonFragment(['agencies.manage']);

        $updateResponse = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('test-token')->plainTextToken])
            ->putJson('/api/v1/roles/auditor/permissions', [
                'replace' => true,
                'permissions' => ['audit.view', 'users.view', 'documents.view'],
            ]);

        $this->assertJsonSuccess($updateResponse);
        $updateResponse->assertJsonPath('data.role.name', 'auditor');
        $this->assertDatabaseHas('role_has_permissions', [
            'permission_id' => DB::table('permissions')->where('name', 'documents.view')->value('id'),
        ]);

        $refreshedCatalogResponse = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('refreshed-token')->plainTextToken])
            ->getJson('/api/v1/roles');

        $this->assertJsonSuccess($refreshedCatalogResponse);
        // GET /roles must reflect persisted role_has_permissions, not config defaults.
        $refreshedCatalogResponse->assertJsonFragment([
            'name' => 'auditor',
            'permissions' => ['audit.view', 'documents.view', 'users.view'],
        ]);

        $tellerConfig = config('security.permissions.roles.teller', []);
        self::assertIsArray($tellerConfig);
        $tellerPermissions = array_values(array_filter($tellerConfig, 'is_string'));
        $tellerPermissions[] = 'cash.tills.manage';
        $tellerPermissions = array_values(array_unique($tellerPermissions));
        $tellerUpdateResponse = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('teller-update-token')->plainTextToken])
            ->putJson('/api/v1/roles/teller/permissions', [
                'replace' => true,
                'permissions' => $tellerPermissions,
            ]);

        $this->assertJsonSuccess($tellerUpdateResponse);

        $tellerCatalogResponse = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('teller-catalog-token')->plainTextToken])
            ->getJson('/api/v1/roles');

        $this->assertJsonSuccess($tellerCatalogResponse);
        $expectedTellerPermissions = $tellerPermissions;
        sort($expectedTellerPermissions);
        $tellerCatalogResponse->assertJsonFragment([
            'name' => 'teller',
            'permissions' => $expectedTellerPermissions,
        ]);

        $protectedPermissionResponse = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('test-token')->plainTextToken])
            ->putJson('/api/v1/roles/auditor/permissions', [
                'replace' => true,
                'permissions' => ['audit.view', 'roles.manage'],
            ]);

        $this->assertJsonError($protectedPermissionResponse, 422, 'Protected permissions can only be granted to platform administrators.');
    }

    public function test_protected_permission_delegation_is_policy_gated(): void
    {
        $actor = $this->createUserWithRole('platform-admin');

        // Default policy (delegation disabled): protected permission rejected.
        config(['security.permissions.allow_protected_delegation' => false]);
        $blocked = $this->withApiHeaders()->actingAsSanctum($actor)
            ->putJson('/api/v1/roles/auditor/permissions', [
                'replace' => true,
                'permissions' => ['audit.view', 'crm.pii.view'],
            ]);
        $this->assertJsonError($blocked, 422, 'Protected permissions can only be granted to platform administrators.');

        // Delegation enabled: a delegable protected permission can be granted
        // to a non-platform role, and GET /roles reflects it immediately.
        config(['security.permissions.allow_protected_delegation' => true]);
        $delegated = $this->withApiHeaders()->actingAsSanctum($actor)
            ->putJson('/api/v1/roles/auditor/permissions', [
                'replace' => true,
                'permissions' => ['audit.view', 'crm.pii.view'],
            ]);
        $this->assertJsonSuccess($delegated);
        $delegated->assertJsonPath('data.role.permissions', ['audit.view', 'crm.pii.view']);

        // GET /roles reflects the delegated permission immediately.
        $this->assertDatabaseHas('role_has_permissions', [
            'role_id' => DB::table('roles')->where('name', 'auditor')->value('id'),
            'permission_id' => DB::table('permissions')->where('name', 'crm.pii.view')->value('id'),
        ]);
        $catalog = $this->withApiHeaders()->actingAsSanctum($actor)->getJson('/api/v1/roles');
        $catalog->assertJsonFragment(['name' => 'auditor', 'permissions' => ['audit.view', 'crm.pii.view']]);

        // Even with delegation enabled, the institution-control floor can never
        // be delegated to a non-platform role.
        $floor = $this->withApiHeaders()->actingAsSanctum($actor)
            ->putJson('/api/v1/roles/auditor/permissions', [
                'replace' => true,
                'permissions' => ['audit.view', 'roles.manage'],
            ]);
        $floor->assertStatus(422);

        // Platform-admin can never drop its minimum administration permissions.
        $minimumPermissions = config('security.permissions.roles.platform-admin', []);
        self::assertIsArray($minimumPermissions);
        $withoutAgencies = array_values(array_filter($minimumPermissions, static fn ($p): bool => $p !== 'agencies.manage'));
        $stripped = $this->withApiHeaders()->actingAsSanctum($actor)
            ->putJson('/api/v1/roles/platform-admin/permissions', [
                'replace' => true,
                'permissions' => $withoutAgencies,
            ]);
        $this->assertJsonError($stripped, 422, 'Platform administrator must retain the minimum administration permissions.');
    }

    public function test_role_permission_replacement_reports_diff_and_supports_version_guard(): void
    {
        $actor = $this->createUserWithRole('platform-admin');

        // Establish a known baseline.
        $this->withApiHeaders()->actingAsSanctum($actor)
            ->putJson('/api/v1/roles/auditor/permissions', ['replace' => true, 'permissions' => ['audit.view', 'users.view']])
            ->assertOk();

        // Replacement mode must be explicit. This prevents old checkbox-style
        // clients from accidentally using PUT as a destructive partial update.
        $this->withApiHeaders()->actingAsSanctum($actor)
            ->putJson('/api/v1/roles/auditor/permissions', ['permissions' => ['audit.view']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['replace']);

        // A replacement reports exactly what changed instead of silently
        // revoking omitted permissions (AIR-001).
        $replace = $this->withApiHeaders()->actingAsSanctum($actor)
            ->putJson('/api/v1/roles/auditor/permissions', ['replace' => true, 'permissions' => ['audit.view', 'documents.view']]);
        $this->assertJsonSuccess($replace);
        $replace->assertJsonPath('data.role.previous_permissions', ['audit.view', 'users.view']);
        $replace->assertJsonPath('data.role.added_permissions', ['documents.view']);
        $replace->assertJsonPath('data.role.removed_permissions', ['users.view']);
        $replace->assertJsonPath('data.role.permissions', ['audit.view', 'documents.view']);

        $version = $replace->json('data.role.permissions_version');
        self::assertIsString($version);

        // A concurrent change moves the version forward.
        $this->withApiHeaders()->actingAsSanctum($actor)
            ->putJson('/api/v1/roles/auditor/permissions', ['replace' => true, 'permissions' => ['audit.view']])
            ->assertOk();

        // Replaying the now-stale baseline is rejected (409), not a misleading success.
        $stale = $this->withApiHeaders()->actingAsSanctum($actor)
            ->putJson('/api/v1/roles/auditor/permissions', [
                'replace' => true,
                'permissions' => ['audit.view', 'documents.view', 'users.view'],
                'expected_permissions_version' => $version,
            ]);
        $stale->assertStatus(409);
        $stale->assertJsonValidationErrors(['expected_permissions_version']);
    }

    public function test_role_permission_toggle_endpoints_do_not_revoke_other_permissions(): void
    {
        $actor = $this->createUserWithRole('platform-admin');

        $this->withApiHeaders()->actingAsSanctum($actor)
            ->putJson('/api/v1/roles/auditor/permissions', ['replace' => true, 'permissions' => ['audit.view', 'users.view']])
            ->assertOk();

        // Granting a single permission leaves the others intact.
        $grant = $this->withApiHeaders()->actingAsSanctum($actor)
            ->postJson('/api/v1/roles/auditor/permissions/documents.view');
        $this->assertJsonSuccess($grant);
        $grant->assertJsonPath('data.role.added_permissions', ['documents.view']);
        $grant->assertJsonPath('data.role.removed_permissions', []);
        $grant->assertJsonPath('data.role.permissions', ['audit.view', 'documents.view', 'users.view']);

        // Revoking a single permission removes only that one.
        $revoke = $this->withApiHeaders()->actingAsSanctum($actor)
            ->deleteJson('/api/v1/roles/auditor/permissions/users.view');
        $this->assertJsonSuccess($revoke);
        $revoke->assertJsonPath('data.role.removed_permissions', ['users.view']);
        $revoke->assertJsonPath('data.role.permissions', ['audit.view', 'documents.view']);
    }

    public function test_seeded_protected_role_permissions_can_be_resaved_but_new_ones_are_blocked(): void
    {
        $actor = $this->createUserWithRole('platform-admin');

        $kycPermissions = array_values(array_filter(
            DB::table('permissions')
                ->join('role_has_permissions', 'role_has_permissions.permission_id', '=', 'permissions.id')
                ->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')
                ->where('roles.name', 'kyc-officer')
                ->pluck('permissions.name')
                ->all(),
            'is_string',
        ));
        // Sanity: the seeded role genuinely carries a protected permission.
        self::assertContains('crm.pii.view', $kycPermissions);

        // Re-saving the role's own current set (which includes configured
        // protected permissions) must not fail under default policy (AIR-002).
        $resave = $this->withApiHeaders()->actingAsSanctum($actor)
            ->putJson('/api/v1/roles/kyc-officer/permissions', ['replace' => true, 'permissions' => $kycPermissions]);
        $this->assertJsonSuccess($resave);

        // Adding a brand-new non-delegable protected permission is still blocked.
        $escalate = $this->withApiHeaders()->actingAsSanctum($actor)
            ->putJson('/api/v1/roles/kyc-officer/permissions', [
                'replace' => true,
                'permissions' => array_values(array_unique([...$kycPermissions, 'roles.manage'])),
            ]);
        $escalate->assertStatus(422);
    }

    public function test_roles_index_exposes_permission_policy_metadata(): void
    {
        $actor = $this->createUserWithRole('platform-admin');

        $response = $this->withApiHeaders()->actingAsSanctum($actor)->getJson('/api/v1/roles');
        $this->assertJsonSuccess($response);
        $response->assertJsonPath('data.permission_policy.delegation_enabled', false);

        $protected = $response->json('data.permission_policy.protected');
        $nonDelegable = $response->json('data.permission_policy.non_delegable');
        self::assertIsArray($protected);
        self::assertIsArray($nonDelegable);
        self::assertContains('crm.pii.view', $protected);
        self::assertContains('roles.manage', $nonDelegable);

        // Each role carries a permissions_version for optimistic concurrency.
        $roles = $response->json('data.roles');
        self::assertIsArray($roles);
        foreach ($roles as $role) {
            self::assertIsArray($role);
            self::assertArrayHasKey('permissions_version', $role);
        }
    }

    public function test_batch_registry_and_run_idempotency_work(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('AG-E1');

        $procedureResponse = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('test-token')->plainTextToken])
            ->postJson('/api/v1/batch-procedures', [
                'code' => 'EOD_CLOSE',
                'name' => 'End Of Day Close',
                'description' => 'Daily close procedure',
                'schedule_type' => 'daily',
                'schedule_metadata' => ['time' => '23:59'],
            ]);

        $this->assertJsonSuccess($procedureResponse, 201);
        $procedurePublicId = $this->requireStringJsonPath($procedureResponse, 'data.public_id');
        $procedure = BatchProcedure::query()->where('public_id', $procedurePublicId)->firstOrFail();

        $runPayload = [
            'batch_procedure_public_id' => $procedurePublicId,
            'business_date' => '2026-05-02',
            'agency_code' => $agency['code'],
            'idempotency_key' => 'module1-eod-01',
            'summary_payload' => ['rows' => 12],
        ];

        $runResponse = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('test-token')->plainTextToken])
            ->postJson('/api/v1/batch-runs', $runPayload);

        $this->assertJsonSuccess($runResponse, 201);
        $runResponse->assertJsonPath('data.batch_procedure_public_id', $procedure->public_id);
        $runResponse->assertJsonPath('data.agency_code', $agency['code']);

        $runReplayResponse = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('test-token')->plainTextToken])
            ->postJson('/api/v1/batch-runs', $runPayload);

        $this->assertJsonSuccess($runReplayResponse);
        $runReplayResponse->assertJsonPath('data.public_id', $runResponse->json('data.public_id'));

        $conflictResponse = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('test-token')->plainTextToken])
            ->postJson('/api/v1/batch-runs', [
                'batch_procedure_public_id' => $procedurePublicId,
                'business_date' => '2026-05-02',
                'agency_code' => $agency['code'],
                'idempotency_key' => 'module1-eod-01',
                'summary_payload' => ['rows' => 13],
            ]);

        $this->assertJsonError($conflictResponse, 409, 'Idempotency-Key has already been used for a different request.');

        $run = BatchRun::query()->where('public_id', $this->requireStringJsonPath($runResponse, 'data.public_id'))->firstOrFail();

        $runningResponse = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('test-token')->plainTextToken])
            ->patchJson('/api/v1/batch-runs/'.$run->public_id.'/status', [
                'status' => BatchRun::STATUS_RUNNING,
            ]);

        $this->assertJsonSuccess($runningResponse);
        $runningResponse->assertJsonPath('data.status', BatchRun::STATUS_RUNNING);

        $successResponse = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('test-token')->plainTextToken])
            ->patchJson('/api/v1/batch-runs/'.$run->public_id.'/status', [
                'status' => BatchRun::STATUS_SUCCEEDED,
                'summary_payload' => ['rows' => 12, 'status' => 'ok'],
            ]);

        $this->assertJsonSuccess($successResponse);
        $successResponse->assertJsonPath('data.status', BatchRun::STATUS_SUCCEEDED);

        $overwriteResponse = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('test-token')->plainTextToken])
            ->patchJson('/api/v1/batch-runs/'.$run->public_id.'/status', [
                'status' => BatchRun::STATUS_RUNNING,
            ]);

        $this->assertJsonError($overwriteResponse, 422, 'Completed batch runs cannot be changed.');
    }

    public function test_batch_execution_rejects_missing_handler_and_inactive_procedure(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('AG-E4');

        $unsupportedProcedure = BatchProcedure::query()->create([
            'public_id' => (string) Str::ulid(),
            'code' => 'UNREGISTERED_CLOSE',
            'name' => 'Unregistered Close',
            'schedule_type' => 'daily',
            'status' => BatchProcedure::STATUS_ACTIVE,
        ]);
        $unsupportedRun = BatchRun::query()->create([
            'public_id' => (string) Str::ulid(),
            'batch_procedure_id' => $unsupportedProcedure->id,
            'agency_id' => $agency['id'],
            'business_date' => '2026-05-08',
            'status' => BatchRun::STATUS_PENDING,
            'operator_user_id' => $actor->id,
        ]);

        $unsupportedResponse = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('unsupported-batch')->plainTextToken])
            ->postJson('/api/v1/batch-runs/'.$unsupportedRun->public_id.'/execute');

        $this->assertJsonError($unsupportedResponse, 422, 'This batch procedure is not executable.');

        $inactiveProcedure = BatchProcedure::query()->create([
            'public_id' => (string) Str::ulid(),
            'code' => 'CASH_DAILY_CLOSE',
            'name' => 'Inactive Cash Daily Close',
            'schedule_type' => 'daily',
            'status' => BatchProcedure::STATUS_INACTIVE,
        ]);
        $inactiveRun = BatchRun::query()->create([
            'public_id' => (string) Str::ulid(),
            'batch_procedure_id' => $inactiveProcedure->id,
            'agency_id' => $agency['id'],
            'business_date' => '2026-05-08',
            'status' => BatchRun::STATUS_PENDING,
            'operator_user_id' => $actor->id,
        ]);

        $inactiveResponse = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('inactive-batch')->plainTextToken])
            ->postJson('/api/v1/batch-runs/'.$inactiveRun->public_id.'/execute');

        $this->assertJsonError($inactiveResponse, 422, 'Inactive batch procedures cannot be executed.');
    }

    public function test_loan_servicing_batch_hooks_queue_portfolio_reports_and_notifications(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('AG-E4B');

        DB::table('report_definitions')->insert([
            'public_id' => (string) Str::ulid(),
            'code' => 'CREDIT_PORTFOLIO_DAILY',
            'name' => 'Daily Credit Portfolio',
            'report_type' => 'credit_portfolio_outstanding',
            'module' => 'credit',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('notification_templates')->insert([
            'public_id' => (string) Str::ulid(),
            'code' => 'loan_servicing_batch_alert',
            'channel' => 'sms',
            'subject' => 'Loan servicing batch',
            'body_template' => 'Loan servicing batch {{batch_run_public_id}} queued for {{business_date}}.',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $portfolioProcedure = BatchProcedure::query()->create([
            'public_id' => (string) Str::ulid(),
            'code' => 'CREDIT_PORTFOLIO_REPORT_HOOK',
            'name' => 'Credit Portfolio Report Hook',
            'schedule_type' => 'daily',
            'schedule_metadata' => ['report_definition_code' => 'CREDIT_PORTFOLIO_DAILY'],
            'status' => BatchProcedure::STATUS_ACTIVE,
        ]);
        $portfolioRun = BatchRun::query()->create([
            'public_id' => (string) Str::ulid(),
            'batch_procedure_id' => $portfolioProcedure->id,
            'agency_id' => $agency['id'],
            'business_date' => '2026-05-08',
            'status' => BatchRun::STATUS_PENDING,
            'operator_user_id' => $actor->id,
        ]);

        $portfolioResponse = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('portfolio-hook')->plainTextToken])
            ->postJson('/api/v1/batch-runs/'.$portfolioRun->public_id.'/execute');

        $this->assertJsonSuccess($portfolioResponse);
        $portfolioResponse->assertJsonPath('data.status', BatchRun::STATUS_SUCCEEDED);
        $portfolioResponse->assertJsonPath('data.summary_payload.hook_status', 'queued');
        $portfolioRunPublicId = $this->requireStringJsonPath($portfolioResponse, 'data.summary_payload.report_run_public_id');
        $this->assertDatabaseHas('report_runs', [
            'public_id' => $portfolioRunPublicId,
            'agency_id' => $agency['id'],
            'status' => 'pending',
        ]);

        $notificationProcedure = BatchProcedure::query()->create([
            'public_id' => (string) Str::ulid(),
            'code' => 'LOAN_SERVICING_NOTIFICATION_HOOK',
            'name' => 'Loan Servicing Notification Hook',
            'schedule_type' => 'daily',
            'schedule_metadata' => ['notification_template_code' => 'loan_servicing_batch_alert'],
            'status' => BatchProcedure::STATUS_ACTIVE,
        ]);
        $notificationRun = BatchRun::query()->create([
            'public_id' => (string) Str::ulid(),
            'batch_procedure_id' => $notificationProcedure->id,
            'agency_id' => $agency['id'],
            'business_date' => '2026-05-08',
            'status' => BatchRun::STATUS_PENDING,
            'operator_user_id' => $actor->id,
        ]);

        $notificationResponse = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('notification-hook')->plainTextToken])
            ->postJson('/api/v1/batch-runs/'.$notificationRun->public_id.'/execute');

        $this->assertJsonSuccess($notificationResponse);
        $notificationResponse->assertJsonPath('data.summary_payload.hook_status', 'queued');
        $notificationDeliveryPublicId = $this->requireStringJsonPath($notificationResponse, 'data.summary_payload.notification_delivery_public_id');
        $this->assertDatabaseHas('notification_deliveries', [
            'public_id' => $notificationDeliveryPublicId,
            'recipient_type' => User::class,
            'recipient_id' => $actor->id,
            'destination' => $actor->phone_number,
            'status' => 'pending',
        ]);
    }

    public function test_notification_delivery_retry_manager_tracks_retry_and_permanent_failure_without_secret_leakage(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $templateId = DB::table('notification_templates')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'code' => 'batch_failure_alert',
            'channel' => 'sms',
            'subject' => 'Batch failure',
            'body_template' => 'Batch failed.',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $deliveryId = DB::table('notification_deliveries')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'notification_template_id' => $templateId,
            'recipient_type' => User::class,
            'recipient_id' => $actor->id,
            'channel' => 'sms',
            'destination' => $actor->phone_number,
            'subject' => 'Batch failure',
            'body' => 'Batch failed.',
            'status' => 'pending',
            'retry_count' => 0,
            'max_attempts' => 2,
            'scheduled_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $manager = app(NotificationDeliveryRetryManager::class);
        $firstFailure = $manager->recordFailure('notification_deliveries', $deliveryId, 'Provider rejected otp=123456 token=secret +237699999999');
        $firstFailureData = (array) $firstFailure;

        self::assertSame('failed', $firstFailureData['status'] ?? null);
        self::assertSame(1, (int) ($firstFailureData['retry_count'] ?? 0));
        self::assertNotNull($firstFailureData['next_attempt_at'] ?? null);
        self::assertStringNotContainsString('123456', (string) ($firstFailureData['failure_reason'] ?? ''));
        self::assertStringNotContainsString('secret', (string) ($firstFailureData['failure_reason'] ?? ''));
        self::assertStringNotContainsString('+237699999999', (string) ($firstFailureData['failure_reason'] ?? ''));

        $secondFailure = $manager->recordFailure('notification_deliveries', $deliveryId, 'Still failing password=Secret123');
        $secondFailureData = (array) $secondFailure;

        self::assertSame('permanently_failed', $secondFailureData['status'] ?? null);
        self::assertSame(2, (int) ($secondFailureData['retry_count'] ?? 0));
        self::assertNull($secondFailureData['next_attempt_at'] ?? null);

        $otpDeliveryId = DB::table('otp_deliveries')->insertGetId([
            'otp_challenge_id' => DB::table('otp_challenges')->insertGetId([
                'user_id' => $actor->id,
                'purpose' => 'password_reset',
                'phone_number' => $actor->phone_number,
                'code_hash' => 'hashed',
                'expires_at' => now()->addMinutes(10),
                'created_at' => now(),
                'updated_at' => now(),
            ]),
            'channel' => 'sms',
            'destination_hash' => hash('sha256', strtolower($actor->phone_number)),
            'destination_masked' => '*********'.substr($actor->phone_number, -4),
            'status' => 'failed',
            'retry_count' => 0,
            'max_attempts' => 3,
            'error_summary' => 'Initial password reset OTP failure',
            'failed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $otpFailure = $manager->recordFailure('otp_deliveries', $otpDeliveryId, 'otp: 654321 phone '.$actor->phone_number);
        $otpFailureData = (array) $otpFailure;
        self::assertSame('failed', $otpFailureData['status'] ?? null);
        self::assertStringNotContainsString('654321', (string) ($otpFailureData['error_summary'] ?? ''));
        self::assertStringNotContainsString($actor->phone_number, (string) ($otpFailureData['error_summary'] ?? ''));
    }

    public function test_batch_execution_rejects_duplicate_running_scope(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('AG-E5');
        $procedure = BatchProcedure::query()->create([
            'public_id' => (string) Str::ulid(),
            'code' => 'CASH_CLOSE_VERIFICATION',
            'name' => 'Cash Close Verification',
            'schedule_type' => 'daily',
            'status' => BatchProcedure::STATUS_ACTIVE,
        ]);

        BatchRun::query()->create([
            'public_id' => (string) Str::ulid(),
            'batch_procedure_id' => $procedure->id,
            'agency_id' => $agency['id'],
            'business_date' => '2026-05-08',
            'status' => BatchRun::STATUS_RUNNING,
            'operator_user_id' => $actor->id,
        ]);
        $pendingRun = BatchRun::query()->create([
            'public_id' => (string) Str::ulid(),
            'batch_procedure_id' => $procedure->id,
            'agency_id' => $agency['id'],
            'business_date' => '2026-05-08',
            'status' => BatchRun::STATUS_PENDING,
            'operator_user_id' => $actor->id,
        ]);

        $response = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('running-lock')->plainTextToken])
            ->postJson('/api/v1/batch-runs/'.$pendingRun->public_id.'/execute');

        $this->assertJsonError($response, 422, 'A batch run is already executing for this procedure, agency, and business date.');
    }

    public function test_batch_execution_blocks_until_prerequisites_succeed(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('AG-E6');

        $prerequisite = BatchProcedure::query()->create([
            'public_id' => (string) Str::ulid(),
            'code' => 'ACCOUNTING_CLOSE_VERIFICATION',
            'name' => 'Accounting Close Verification',
            'schedule_type' => 'daily',
            'status' => BatchProcedure::STATUS_ACTIVE,
        ]);
        $dependent = BatchProcedure::query()->create([
            'public_id' => (string) Str::ulid(),
            'code' => 'CASH_CLOSE_VERIFICATION',
            'name' => 'Cash Close Verification',
            'schedule_type' => 'daily',
            'schedule_metadata' => [
                'execution_priority' => 20,
                'prerequisite_procedure_codes' => ['ACCOUNTING_CLOSE_VERIFICATION'],
            ],
            'status' => BatchProcedure::STATUS_ACTIVE,
        ]);
        $dependentRun = BatchRun::query()->create([
            'public_id' => (string) Str::ulid(),
            'batch_procedure_id' => $dependent->id,
            'agency_id' => $agency['id'],
            'business_date' => '2026-05-09',
            'status' => BatchRun::STATUS_PENDING,
            'operator_user_id' => $actor->id,
        ]);

        $blocked = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('dependency-blocked')->plainTextToken])
            ->postJson('/api/v1/batch-runs/'.$dependentRun->public_id.'/execute');

        $this->assertJsonError($blocked, 422, 'Batch prerequisites are not satisfied.');
        self::assertSame(BatchRun::STATUS_FAILED, $dependentRun->refresh()->status);
        self::assertSame('Batch prerequisites are not satisfied.', $dependentRun->failure_reason);
        $summaryPayload = $dependentRun->summary_payload;
        self::assertIsArray($summaryPayload);
        $incompletePrerequisites = $summaryPayload['incomplete_prerequisites'] ?? null;
        self::assertIsArray($incompletePrerequisites);
        $firstIncompletePrerequisite = $incompletePrerequisites[0] ?? null;
        self::assertIsArray($firstIncompletePrerequisite);
        self::assertSame('accounting_close_verification', $firstIncompletePrerequisite['procedure_code'] ?? null);
        self::assertSame('missing_run', $firstIncompletePrerequisite['status'] ?? null);

        BatchRun::query()->create([
            'public_id' => (string) Str::ulid(),
            'batch_procedure_id' => $prerequisite->id,
            'agency_id' => $agency['id'],
            'business_date' => '2026-05-09',
            'status' => BatchRun::STATUS_SUCCEEDED,
            'operator_user_id' => $actor->id,
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        $succeeded = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('dependency-succeeded')->plainTextToken])
            ->postJson('/api/v1/batch-runs/'.$dependentRun->public_id.'/execute');

        $this->assertJsonSuccess($succeeded);
        $succeeded->assertJsonPath('data.status', BatchRun::STATUS_SUCCEEDED);
        $succeeded->assertJsonPath('data.summary_payload.open_sessions', 0);
    }

    public function test_accounting_close_batch_blocks_until_journals_are_final(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('AG-E7');
        $otherAgency = $this->createAgency('AG-E8');
        $procedure = BatchProcedure::query()->create([
            'public_id' => (string) Str::ulid(),
            'code' => 'ACCOUNTING_CLOSE_VERIFICATION',
            'name' => 'Accounting Close Verification',
            'schedule_type' => 'daily',
            'status' => BatchProcedure::STATUS_ACTIVE,
        ]);

        $draft = JournalEntry::query()->create([
            'public_id' => (string) Str::ulid(),
            'reference' => 'ACC-CLOSE-DRAFT',
            'business_date' => '2026-05-10',
            'agency_id' => $agency['id'],
            'status' => JournalEntry::STATUS_DRAFT,
            'created_by_user_id' => $actor->id,
        ]);
        $approved = JournalEntry::query()->create([
            'public_id' => (string) Str::ulid(),
            'reference' => 'ACC-CLOSE-APPROVED',
            'business_date' => '2026-05-10',
            'agency_id' => $agency['id'],
            'status' => JournalEntry::STATUS_APPROVED,
            'created_by_user_id' => $actor->id,
            'submitted_by_user_id' => $actor->id,
            'submitted_at' => now(),
            'reviewed_by_user_id' => $actor->id,
            'reviewed_at' => now(),
        ]);
        JournalEntry::query()->create([
            'public_id' => (string) Str::ulid(),
            'reference' => 'ACC-CLOSE-OTHER',
            'business_date' => '2026-05-10',
            'agency_id' => $otherAgency['id'],
            'status' => JournalEntry::STATUS_DRAFT,
            'created_by_user_id' => $actor->id,
        ]);
        $run = BatchRun::query()->create([
            'public_id' => (string) Str::ulid(),
            'batch_procedure_id' => $procedure->id,
            'agency_id' => $agency['id'],
            'business_date' => '2026-05-10',
            'status' => BatchRun::STATUS_PENDING,
            'operator_user_id' => $actor->id,
        ]);

        $blocked = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('accounting-close-blocked')->plainTextToken])
            ->postJson('/api/v1/batch-runs/'.$run->public_id.'/execute');

        $this->assertJsonSuccess($blocked);
        $blocked->assertJsonPath('data.status', BatchRun::STATUS_FAILED);
        $blocked->assertJsonPath('data.summary_payload.blocking_journals', 2);
        $blocked->assertJsonPath('data.summary_payload.blocking_status_counts.draft', 1);
        $blocked->assertJsonPath('data.summary_payload.blocking_status_counts.approved', 1);

        $draft->forceFill([
            'status' => JournalEntry::STATUS_SUBMITTED,
            'submitted_at' => now(),
            'submitted_by_user_id' => $actor->id,
        ])->save();
        $draft->forceFill([
            'status' => JournalEntry::STATUS_APPROVED,
            'reviewed_at' => now(),
            'reviewed_by_user_id' => $actor->id,
        ])->save();
        $draft->forceFill([
            'status' => JournalEntry::STATUS_POSTED,
            'posted_at' => now(),
            'posted_by_user_id' => $actor->id,
        ])->save();
        $approved->forceFill([
            'status' => JournalEntry::STATUS_POSTED,
            'posted_at' => now(),
            'posted_by_user_id' => $actor->id,
        ])->save();

        $succeeded = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('accounting-close-succeeded')->plainTextToken])
            ->postJson('/api/v1/batch-runs/'.$run->public_id.'/execute');

        $this->assertJsonSuccess($succeeded);
        $succeeded->assertJsonPath('data.status', BatchRun::STATUS_SUCCEEDED);
        $succeeded->assertJsonPath('data.summary_payload.blocking_journals', 0);
    }

    public function test_batch_run_monitoring_filters_and_enforces_agency_scope(): void
    {
        $admin = $this->createUserWithRole('platform-admin');
        $agencyA = $this->createAgency('AG-E9');
        $agencyB = $this->createAgency('AG-F1');
        $managerA = $this->createUserWithRole('agency-manager', $agencyA['code'], $agencyA['name']);
        $procedure = BatchProcedure::query()->create([
            'public_id' => (string) Str::ulid(),
            'code' => 'CASH_CLOSE_VERIFICATION',
            'name' => 'Cash Close Verification',
            'schedule_type' => 'daily',
            'status' => BatchProcedure::STATUS_ACTIVE,
        ]);
        $runA = BatchRun::query()->create([
            'public_id' => (string) Str::ulid(),
            'batch_procedure_id' => $procedure->id,
            'agency_id' => $agencyA['id'],
            'business_date' => '2026-05-11',
            'status' => BatchRun::STATUS_FAILED,
            'operator_user_id' => $admin->id,
            'failure_reason' => 'Cash close controls are not satisfied.',
        ]);
        $runB = BatchRun::query()->create([
            'public_id' => (string) Str::ulid(),
            'batch_procedure_id' => $procedure->id,
            'agency_id' => $agencyB['id'],
            'business_date' => '2026-05-12',
            'status' => BatchRun::STATUS_SUCCEEDED,
            'operator_user_id' => $admin->id,
        ]);

        $adminFiltered = $this
            ->withApiHeaders()
            ->actingAsSanctum($admin)
            ->getJson('/api/v1/batch-runs?status=failed&agency_code='.$agencyA['code'].'&business_date_from=2026-05-10&business_date_to=2026-05-11');

        $this->assertJsonSuccess($adminFiltered);
        $adminFiltered->assertJsonCount(1, 'data.runs');
        $adminFiltered->assertJsonPath('data.runs.0.public_id', $runA->public_id);
        $adminFiltered->assertJsonPath('data.runs.0.failure_reason', 'Cash close controls are not satisfied.');
        $adminFiltered->assertJsonPath('data.runs.0.operator_public_id', $admin->public_id);

        $managerList = $this
            ->withApiHeaders()
            ->actingAsSanctum($managerA)
            ->getJson('/api/v1/batch-runs?status=succeeded');

        $this->assertJsonSuccess($managerList);
        $managerList->assertJsonCount(0, 'data.runs');

        $managerOwnList = $this
            ->withApiHeaders()
            ->actingAsSanctum($managerA)
            ->getJson('/api/v1/batch-runs?status=failed');

        $this->assertJsonSuccess($managerOwnList);
        $managerOwnList->assertJsonCount(1, 'data.runs');
        $managerOwnList->assertJsonPath('data.runs.0.public_id', $runA->public_id);

        $crossAgencyShow = $this
            ->withApiHeaders()
            ->actingAsSanctum($managerA)
            ->getJson('/api/v1/batch-runs/'.$runB->public_id);

        $crossAgencyShow->assertForbidden();
    }

    public function test_batch_retry_and_cancel_controls_are_state_safe_and_audited(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('AG-F2');
        $procedure = BatchProcedure::query()->create([
            'public_id' => (string) Str::ulid(),
            'code' => 'CASH_CLOSE_VERIFICATION',
            'name' => 'Cash Close Verification',
            'schedule_type' => 'daily',
            'status' => BatchProcedure::STATUS_ACTIVE,
        ]);
        $pendingRun = BatchRun::query()->create([
            'public_id' => (string) Str::ulid(),
            'batch_procedure_id' => $procedure->id,
            'agency_id' => $agency['id'],
            'business_date' => '2026-05-13',
            'status' => BatchRun::STATUS_PENDING,
            'operator_user_id' => $actor->id,
        ]);

        $cancelled = $this
            ->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/batch-runs/'.$pendingRun->public_id.'/cancel');

        $this->assertJsonSuccess($cancelled);
        $cancelled->assertJsonPath('data.status', BatchRun::STATUS_CANCELLED);
        $this->assertDatabaseHas('activity_log', [
            'event' => 'batch.run.cancelled',
        ]);

        $retriedCancelled = $this
            ->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/batch-runs/'.$pendingRun->public_id.'/retry');

        $this->assertJsonSuccess($retriedCancelled);
        $retriedCancelled->assertJsonPath('data.status', BatchRun::STATUS_PENDING);
        $this->assertDatabaseHas('activity_log', [
            'event' => 'batch.run.retry_requested',
        ]);

        $retryPending = $this
            ->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/batch-runs/'.$pendingRun->public_id.'/retry');

        $this->assertJsonError($retryPending, 422, 'Only failed or cancelled batch runs can be retried.');

        $runningRun = BatchRun::query()->create([
            'public_id' => (string) Str::ulid(),
            'batch_procedure_id' => $procedure->id,
            'agency_id' => $agency['id'],
            'business_date' => '2026-05-14',
            'status' => BatchRun::STATUS_RUNNING,
            'started_at' => now(),
            'operator_user_id' => $actor->id,
        ]);

        $cancelRunning = $this
            ->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/batch-runs/'.$runningRun->public_id.'/cancel');

        $this->assertJsonError($cancelRunning, 422, 'Only pending batch runs that have not started can be cancelled.');

        $failedRun = BatchRun::query()->create([
            'public_id' => (string) Str::ulid(),
            'batch_procedure_id' => $procedure->id,
            'agency_id' => $agency['id'],
            'business_date' => '2026-05-15',
            'status' => BatchRun::STATUS_FAILED,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'failure_reason' => 'Previous failure',
            'operator_user_id' => $actor->id,
        ]);

        $retriedFailed = $this
            ->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/batch-runs/'.$failedRun->public_id.'/retry');

        $this->assertJsonSuccess($retriedFailed);
        $retriedFailed->assertJsonPath('data.status', BatchRun::STATUS_PENDING);
        $retriedFailed->assertJsonPath('data.failure_reason', null);
        self::assertNull($failedRun->refresh()->started_at);
    }

    public function test_batch_run_idempotency_is_scoped_to_the_actor(): void
    {
        $actorOne = $this->createUserWithRole('platform-admin');
        $actorTwo = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('AG-E2');

        $procedureResponse = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actorOne->createToken('token-one')->plainTextToken])
            ->postJson('/api/v1/batch-procedures', [
                'code' => 'NIGHT_CLOSE',
                'name' => 'Night Close',
                'schedule_type' => 'daily',
                'schedule_metadata' => ['time' => '23:00'],
            ]);

        $procedurePublicId = $this->requireStringJsonPath($procedureResponse, 'data.public_id');

        $firstResponse = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actorOne->createToken('token-two')->plainTextToken])
            ->postJson('/api/v1/batch-runs', [
                'batch_procedure_public_id' => $procedurePublicId,
                'business_date' => '2026-05-03',
                'agency_code' => $agency['code'],
                'idempotency_key' => 'shared-key-01',
                'summary_payload' => ['rows' => 7],
            ]);

        $secondResponse = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actorTwo->createToken('token-three')->plainTextToken])
            ->postJson('/api/v1/batch-runs', [
                'batch_procedure_public_id' => $procedurePublicId,
                'business_date' => '2026-05-04',
                'agency_code' => $agency['code'],
                'idempotency_key' => 'shared-key-01',
                'summary_payload' => ['rows' => 7],
            ]);

        $this->assertJsonSuccess($firstResponse, 201);
        $firstRunPublicId = $this->requireStringJsonPath($firstResponse, 'data.public_id');
        self::assertNotNull(DB::table('batch_runs')->where('public_id', $firstRunPublicId)->value('scope_hash'));
        $this->assertJsonError($secondResponse, 409, 'Idempotency-Key has already been used for a different request.');
    }

    public function test_legacy_batch_run_with_missing_fingerprint_fails_closed(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('AG-E3');

        $procedureResponse = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('token-four')->plainTextToken])
            ->postJson('/api/v1/batch-procedures', [
                'code' => 'LEGACY_CLOSE',
                'name' => 'Legacy Close',
                'schedule_type' => 'daily',
                'schedule_metadata' => ['time' => '22:00'],
            ]);

        $procedurePublicId = $this->requireStringJsonPath($procedureResponse, 'data.public_id');
        $procedure = BatchProcedure::query()->where('public_id', $procedurePublicId)->firstOrFail();

        DB::table('batch_runs')->insert([
            'public_id' => (string) Str::ulid(),
            'batch_procedure_id' => $procedure->id,
            'agency_id' => $agency['id'],
            'business_date' => '2026-05-05',
            'status' => 'succeeded',
            'operator_user_id' => $actor->id,
            'actor_context' => 'user:'.$actor->id,
            'scope_hash' => null,
            'idempotency_key' => 'legacy-key-01',
            'request_fingerprint' => null,
        ]);

        $response = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('token-five')->plainTextToken])
            ->postJson('/api/v1/batch-runs', [
                'batch_procedure_public_id' => $procedurePublicId,
                'business_date' => '2026-05-05',
                'agency_code' => $agency['code'],
                'idempotency_key' => 'legacy-key-01',
                'summary_payload' => ['rows' => 2],
            ]);

        $this->assertJsonError($response, 409, 'Idempotency-Key has already been used for a different request.');
    }

    public function test_non_platform_users_cannot_create_agencies(): void
    {
        $actor = $this->createUserWithRole('agency-manager', 'AG-Z1', 'Agency Z1');

        $response = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('test-token')->plainTextToken])
            ->postJson('/api/v1/agencies', [
                'code' => 'AG-Z9',
                'name' => 'Forbidden Agency',
            ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('agencies', [
            'code' => 'AG-Z9',
        ]);
    }

    private function createUserWithRole(string $role, ?string $agencyCode = null, ?string $agencyName = null): User
    {
        $agency = null;
        if ($agencyCode !== null) {
            $agency = DB::table('agencies')
                ->where('code', $agencyCode)
                ->first(['id', 'code', 'name']);

            if ($agency === null) {
                $agency = (object) $this->createAgency($agencyCode, $agencyName);
            }
        }

        $user = User::factory()->createOne([
            'status' => User::STATUS_ACTIVE,
            'phone_verified_at' => now(),
            'agency_id' => $agency->id ?? null,
            'agency_code' => $agency->code ?? null,
            'agency_name' => $agency->name ?? null,
        ]);

        $user->assignRole($role);

        if ($agency !== null) {
            DB::table('staff_agency_assignments')->insert([
                'public_id' => (string) Str::ulid(),
                'user_id' => $user->id,
                'agency_id' => $agency->id,
                'role_at_agency' => $role,
                'starts_on' => now()->toDateString(),
                'is_primary' => true,
                'status' => 'active',
            ]);
        }

        return $user;
    }

    /**
     * @return array{id:int, code:string, name:string, public_id:string}
     */
    private function createAgency(string $code, ?string $name = null): array
    {
        $name ??= $code.' Agency';
        $id = DB::table('agencies')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'code' => $code,
            'name' => $name,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $agency = DB::table('agencies')->where('id', $id)->first(['public_id']);

        return [
            'id' => $id,
            'code' => $code,
            'name' => $name,
            'public_id' => is_object($agency) && is_string($agency->public_id) ? $agency->public_id : '',
        ];
    }

    private function requireStringJsonPath(mixed $response, string $path): string
    {
        $value = $response instanceof TestResponse ? $response->json($path) : null;
        self::assertIsString($value);

        return $value;
    }
}
