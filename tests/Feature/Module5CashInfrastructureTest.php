<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class Module5CashInfrastructureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_platform_admin_can_manage_denominations_without_creating_cash_workflow_records(): void
    {
        $actor = $this->createUserWithRole('platform-admin');

        $create = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('denomination-create')->plainTextToken])
            ->postJson('/api/v1/denominations', [
                'code' => 'XAF-10000',
                'label' => 'Billet 10 000 XAF',
                'value_minor' => 10000,
                'currency' => 'xaf',
                'type' => 'banknote',
                'status' => 'active',
            ]);

        $this->assertJsonSuccess($create, 201);
        $create->assertJsonPath('data.public_id', fn (mixed $value): bool => is_string($value) && $value !== '');
        $create->assertJsonPath('data.code', 'XAF-10000');
        $create->assertJsonPath('data.currency', 'XAF');
        $create->assertJsonPath('data.value_minor', 10000);
        $create->assertJsonMissingPath('data.id');

        $publicId = $this->requireStringJsonPath($create, 'data.public_id');

        $show = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('denomination-show')->plainTextToken])
            ->getJson('/api/v1/denominations/'.$publicId);

        $this->assertJsonSuccess($show);
        $show->assertJsonPath('data.public_id', $publicId);

        $update = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('denomination-update')->plainTextToken])
            ->patchJson('/api/v1/denominations/'.$publicId, [
                'status' => 'inactive',
                'label' => 'Billet 10000 XAF inactive',
            ]);

        $this->assertJsonSuccess($update);
        $update->assertJsonPath('data.status', 'inactive');

        $this->assertDatabaseHas('activity_log', [
            'event' => 'cash.denomination.created',
        ]);
        $this->assertNoCashWorkflowRecords();
    }

    public function test_denomination_validation_and_authorization_are_fail_closed(): void
    {
        $admin = $this->createUserWithRole('platform-admin');
        $staff = $this->createUserWithRole('staff');

        $this->postJson('/api/v1/denominations', [
            'code' => 'XAF-500',
            'label' => 'Piece 500 XAF',
            'value_minor' => 500,
            'currency' => 'XAF',
            'type' => 'coin',
        ])->assertUnauthorized();

        $this->withApiHeaders(['Authorization' => 'Bearer '.$staff->createToken('denomination-staff')->plainTextToken])
            ->postJson('/api/v1/denominations', [
                'code' => 'XAF-500',
                'label' => 'Piece 500 XAF',
                'value_minor' => 500,
                'currency' => 'XAF',
                'type' => 'coin',
            ])->assertForbidden();

        $invalid = $this->actingAsSanctum($admin)
            ->postJson('/api/v1/denominations', [
                'code' => 'XAF-0',
                'label' => 'Invalid',
                'value_minor' => 0,
                'currency' => 'XAF',
                'type' => 'coin',
            ]);
        $invalid->assertStatus(422);
        $invalid->assertJsonValidationErrors(['value_minor']);

        $first = $this->withApiHeaders(['Authorization' => 'Bearer '.$admin->createToken('denomination-first')->plainTextToken])
            ->postJson('/api/v1/denominations', [
                'code' => 'XAF-1000',
                'label' => 'Billet 1000 XAF',
                'value_minor' => 1000,
                'currency' => 'XAF',
                'type' => 'banknote',
            ]);
        $this->assertJsonSuccess($first, 201);

        $duplicateCode = $this->withApiHeaders(['Authorization' => 'Bearer '.$admin->createToken('denomination-duplicate-code')->plainTextToken])
            ->postJson('/api/v1/denominations', [
                'code' => 'XAF-1000',
                'label' => 'Duplicate code',
                'value_minor' => 2000,
                'currency' => 'XAF',
                'type' => 'banknote',
            ]);
        $duplicateCode->assertStatus(422);
        $duplicateCode->assertJsonValidationErrors(['code']);

        $duplicateValue = $this->withApiHeaders(['Authorization' => 'Bearer '.$admin->createToken('denomination-duplicate-value')->plainTextToken])
            ->postJson('/api/v1/denominations', [
                'code' => 'XAF-1000-ALT',
                'label' => 'Duplicate value',
                'value_minor' => 1000,
                'currency' => 'XAF',
                'type' => 'banknote',
            ]);
        $duplicateValue->assertStatus(422);
        $duplicateValue->assertJsonValidationErrors(['value_minor']);
    }

    public function test_agency_manager_can_manage_tills_only_inside_active_agency_scope(): void
    {
        $agencyA = $this->createAgency('CASH-A');
        $agencyB = $this->createAgency('CASH-B');
        $actor = $this->createUserWithRole('agency-manager', $agencyA['code'], $agencyA['name']);
        $assignedUser = $this->createUserWithRole('teller', $agencyA['code'], $agencyA['name']);
        $otherAgencyUser = $this->createUserWithRole('teller', $agencyB['code'], $agencyB['name']);

        $create = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('till-create')->plainTextToken])
            ->postJson('/api/v1/tills', [
                'code' => 'TILL-01',
                'name' => 'Front Office Till',
                'type' => 'counter',
                'status' => 'active',
                'assigned_user_public_id' => $assignedUser->public_id,
            ]);

        $this->assertJsonSuccess($create, 201);
        $create->assertJsonPath('data.agency_public_id', $agencyA['public_id']);
        $create->assertJsonPath('data.assigned_user_public_id', $assignedUser->public_id);
        $create->assertJsonMissingPath('data.id');
        $create->assertJsonMissingPath('data.agency_id');
        $create->assertJsonMissingPath('data.assigned_user_id');

        $tillPublicId = $this->requireStringJsonPath($create, 'data.public_id');

        $show = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('till-show')->plainTextToken])
            ->getJson('/api/v1/tills/'.$tillPublicId);
        $this->assertJsonSuccess($show);

        $update = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('till-update')->plainTextToken])
            ->patchJson('/api/v1/tills/'.$tillPublicId, [
                'status' => 'inactive',
                'name' => 'Inactive Front Office Till',
            ]);
        $this->assertJsonSuccess($update);
        $update->assertJsonPath('data.status', 'inactive');

        $crossAgencyCreate = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('till-cross-agency')->plainTextToken])
            ->postJson('/api/v1/tills', [
                'agency_public_id' => $agencyB['public_id'],
                'code' => 'TILL-B',
                'name' => 'Other Agency Till',
            ]);
        $crossAgencyCreate->assertForbidden();

        $otherUserAssign = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('till-other-user')->plainTextToken])
            ->patchJson('/api/v1/tills/'.$tillPublicId, [
                'assigned_user_public_id' => $otherAgencyUser->public_id,
            ]);
        $otherUserAssign->assertStatus(422);
        $otherUserAssign->assertJsonValidationErrors(['assigned_user_public_id']);

        $this->assertDatabaseHas('activity_log', [
            'event' => 'cash.till.created',
        ]);
        $this->assertNoCashWorkflowRecords();
    }

    public function test_till_setup_rejects_duplicates_deferred_fields_and_cross_agency_access(): void
    {
        $agencyA = $this->createAgency('CASH-C');
        $agencyB = $this->createAgency('CASH-D');
        $actor = $this->createUserWithRole('agency-manager', $agencyA['code'], $agencyA['name']);
        $otherActor = $this->createUserWithRole('agency-manager', $agencyB['code'], $agencyB['name']);

        $create = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('till-create-a')->plainTextToken])
            ->postJson('/api/v1/tills', [
                'code' => 'TILL-DUP',
                'name' => 'Till Duplicate A',
            ]);
        $this->assertJsonSuccess($create, 201);
        $tillPublicId = $this->requireStringJsonPath($create, 'data.public_id');

        $duplicate = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('till-duplicate')->plainTextToken])
            ->postJson('/api/v1/tills', [
                'code' => 'TILL-DUP',
                'name' => 'Till Duplicate B',
            ]);
        $duplicate->assertStatus(422);
        $duplicate->assertJsonValidationErrors(['code']);

        $deferredField = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('till-deferred')->plainTextToken])
            ->postJson('/api/v1/tills', [
                'code' => 'TILL-BAL',
                'name' => 'Unsafe Balance Till',
                'opening_balance' => 1000,
            ]);
        $deferredField->assertStatus(422);
        $deferredField->assertJsonValidationErrors(['opening_balance']);

        $crossAgencyShow = $this->actingAsSanctum($otherActor)
            ->getJson('/api/v1/tills/'.$tillPublicId);
        $crossAgencyShow->assertForbidden();

        $crossAgencyUpdate = $this->actingAsSanctum($otherActor)
            ->patchJson('/api/v1/tills/'.$tillPublicId, [
                'name' => 'Compromised',
            ]);
        $crossAgencyUpdate->assertForbidden();

        $this->assertNoCashWorkflowRecords();
    }

    public function test_platform_admin_can_explicitly_manage_tills_across_agencies(): void
    {
        $agency = $this->createAgency('CASH-E');
        $actor = $this->createUserWithRole('platform-admin');
        $assignedUser = $this->createUserWithRole('teller', $agency['code'], $agency['name']);

        $create = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('platform-till')->plainTextToken])
            ->postJson('/api/v1/tills', [
                'agency_public_id' => $agency['public_id'],
                'code' => 'TILL-PLATFORM',
                'name' => 'Platform Managed Till',
                'assigned_user_public_id' => $assignedUser->public_id,
            ]);

        $this->assertJsonSuccess($create, 201);
        $create->assertJsonPath('data.agency_public_id', $agency['public_id']);
    }

    private function assertNoCashWorkflowRecords(): void
    {
        $this->assertDatabaseCount('teller_sessions', 0);
        $this->assertDatabaseCount('teller_transactions', 0);
        $this->assertDatabaseCount('till_reconciliations', 0);
        $this->assertDatabaseCount('till_reconciliation_lines', 0);
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertDatabaseCount('journal_lines', 0);
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
