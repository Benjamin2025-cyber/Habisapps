<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Agency;
use App\Models\BatchProcedure;
use App\Models\BatchRun;
use App\Models\StaffAgencyAssignment;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
                'status' => Agency::STATUS_ACTIVE,
            ]);

        $this->assertJsonSuccess($createResponse, 201);
        $agencyPublicId = $this->requireStringJsonPath($createResponse, 'data.agency.public_id');
        $createResponse->assertJsonPath('data.agency.code', 'AG-M1');

        $agency = Agency::query()->where('public_id', $agencyPublicId)->firstOrFail();

        $managerResponse = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('test-token')->plainTextToken])
            ->putJson('/api/v1/agencies/'.$agency->public_id.'/manager', [
                'manager_public_id' => $manager->public_id,
                'role_at_agency' => 'agency-manager',
            ]);

        $this->assertJsonSuccess($managerResponse);
        $managerResponse->assertJsonPath('data.agency.manager_public_id', $manager->public_id);
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
        $archiveResponse->assertJsonPath('data.agency.status', Agency::STATUS_ARCHIVED);
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
        $ownResponse->assertJsonPath('data.agency.public_id', $agencyA['public_id']);

        $forbiddenResponse = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('test-token')->plainTextToken])
            ->getJson('/api/v1/agencies/'.$agencyB['public_id']);

        $forbiddenResponse->assertForbidden();
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
                'starts_on' => '2026-05-01',
                'is_primary' => true,
                'transfer_from_assignment_public_id' => $existingAssignment->public_id,
                'reason' => 'Transfer to new branch',
            ]);

        $this->assertJsonSuccess($response, 201);
        $newAssignmentPublicId = $this->requireStringJsonPath($response, 'data.assignment.public_id');
        $response->assertJsonPath('data.assignment.agency_code', $agencyB['code']);

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
                'permissions' => ['audit.view', 'users.view', 'documents.view'],
            ]);

        $this->assertJsonSuccess($updateResponse);
        $updateResponse->assertJsonPath('data.role.name', 'auditor');
        $this->assertDatabaseHas('role_has_permissions', [
            'permission_id' => DB::table('permissions')->where('name', 'documents.view')->value('id'),
        ]);

        $protectedPermissionResponse = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('test-token')->plainTextToken])
            ->putJson('/api/v1/roles/auditor/permissions', [
                'permissions' => ['audit.view', 'roles.manage'],
            ]);

        $this->assertJsonError($protectedPermissionResponse, 422, 'Protected permissions can only be granted to platform administrators.');
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
        $procedurePublicId = $this->requireStringJsonPath($procedureResponse, 'data.procedure.public_id');
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
        $runResponse->assertJsonPath('data.run.batch_procedure_public_id', $procedure->public_id);
        $runResponse->assertJsonPath('data.run.agency_code', $agency['code']);

        $runReplayResponse = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('test-token')->plainTextToken])
            ->postJson('/api/v1/batch-runs', $runPayload);

        $this->assertJsonSuccess($runReplayResponse);
        $runReplayResponse->assertJsonPath('data.run.public_id', $runResponse->json('data.run.public_id'));

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

        $run = BatchRun::query()->where('public_id', $this->requireStringJsonPath($runResponse, 'data.run.public_id'))->firstOrFail();

        $runningResponse = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('test-token')->plainTextToken])
            ->patchJson('/api/v1/batch-runs/'.$run->public_id.'/status', [
                'status' => BatchRun::STATUS_RUNNING,
            ]);

        $this->assertJsonSuccess($runningResponse);
        $runningResponse->assertJsonPath('data.run.status', BatchRun::STATUS_RUNNING);

        $successResponse = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('test-token')->plainTextToken])
            ->patchJson('/api/v1/batch-runs/'.$run->public_id.'/status', [
                'status' => BatchRun::STATUS_SUCCEEDED,
                'summary_payload' => ['rows' => 12, 'status' => 'ok'],
            ]);

        $this->assertJsonSuccess($successResponse);
        $successResponse->assertJsonPath('data.run.status', BatchRun::STATUS_SUCCEEDED);

        $overwriteResponse = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('test-token')->plainTextToken])
            ->patchJson('/api/v1/batch-runs/'.$run->public_id.'/status', [
                'status' => BatchRun::STATUS_RUNNING,
            ]);

        $this->assertJsonError($overwriteResponse, 422, 'Completed batch runs cannot be changed.');
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

        $procedurePublicId = $this->requireStringJsonPath($procedureResponse, 'data.procedure.public_id');

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
        $firstRunPublicId = $this->requireStringJsonPath($firstResponse, 'data.run.public_id');
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

        $procedurePublicId = $this->requireStringJsonPath($procedureResponse, 'data.procedure.public_id');
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
        $value = $response instanceof \Illuminate\Testing\TestResponse ? $response->json($path) : null;
        self::assertIsString($value);

        return $value;
    }
}
