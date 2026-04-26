<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $response->assertJsonPath('data.user.phone_number', '+237699200001');
        $response->assertJsonPath('data.user.status', User::STATUS_PENDING_VERIFICATION);
        $response->assertJsonPath('data.user.roles.0', 'staff');
        $response->assertJsonMissingPath('data.user.id');

        $this->assertDatabaseHas('users', [
            'phone_number' => '+237699200001',
            'status' => User::STATUS_PENDING_VERIFICATION,
            'invited_by_user_id' => $admin->id,
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
            'provider_reference' => 'test-delivery',
        ]);
        $this->assertDatabaseMissing('otp_deliveries', [
            'provider_reference' => 'test-123456',
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
        $response->assertJsonPath('data.user.roles.0', 'auditor');
        self::assertTrue($target->refresh()->hasRole('auditor'));
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
        $response->assertJsonPath('data.user.status', User::STATUS_SUSPENDED);
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $target->id,
            'tokenable_type' => User::class,
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
}
