<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers `GET /api/v1/me` (refresh the current authenticated user) and the
 * `permissions` / `direct_permissions` fields newly added to StaffUserResource.
 *
 * These fields let the frontend gate UI on the union of role-granted and
 * directly-granted permissions without re-implementing the role→permission
 * map from `config/security.php`.
 */
final class MeEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_me_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/me');

        $response->assertStatus(401);
    }

    public function test_me_returns_authenticated_user_with_roles_and_permissions(): void
    {
        $user = $this->createActiveUserWithRole('platform-admin');

        $response = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$user->createToken('test-token')->plainTextToken])
            ->getJson('/api/v1/me');

        $this->assertJsonSuccess($response);
        $response->assertJsonPath('data.user.public_id', $user->public_id);
        $response->assertJsonPath('data.user.roles.0', 'platform-admin');

        // platform-admin should carry a broad permission catalog; assert a couple
        // of canonical permissions sourced from config/security.php to prove the
        // union is populated, without coupling the test to the entire list.
        $response->assertJsonFragment([
            'agencies.view',
        ]);
        $response->assertJsonFragment([
            'loans.view',
        ]);
        $response->assertJsonFragment([
            'accounting.audit.view',
        ]);
    }

    public function test_me_reflects_role_specific_permission_scopes(): void
    {
        $teller = $this->createActiveUserWithRole('teller');

        $response = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$teller->createToken('test-token')->plainTextToken])
            ->getJson('/api/v1/me');

        $this->assertJsonSuccess($response);
        $response->assertJsonPath('data.user.roles.0', 'teller');

        $payload = $response->json('data.user.permissions');
        self::assertIsArray($payload);
        self::assertContains('cash.transactions.manage', $payload);
        self::assertContains('cash.sessions.manage', $payload);
        // A teller must NOT see loan / CRM / dashboard permissions.
        self::assertNotContains('loans.view', $payload);
        self::assertNotContains('crm.clients.view', $payload);
        self::assertNotContains('accounting.audit.view', $payload);
    }

    public function test_me_separates_direct_and_role_granted_permissions(): void
    {
        $user = $this->createActiveUserWithRole('teller');
        // Direct grant on top of a role — should appear in both arrays
        // (direct_permissions and union permissions).
        $user->givePermissionTo('crm.clients.view');

        $response = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$user->createToken('test-token')->plainTextToken])
            ->getJson('/api/v1/me');

        $this->assertJsonSuccess($response);

        $permissions = $response->json('data.user.permissions');
        $direct = $response->json('data.user.direct_permissions');
        self::assertIsArray($permissions);
        self::assertIsArray($direct);

        self::assertContains('crm.clients.view', $permissions, 'union includes the direct grant');
        self::assertContains('crm.clients.view', $direct, 'direct_permissions isolates the direct grant');
        // Role-derived permissions are in `permissions` but not in `direct_permissions`.
        self::assertContains('cash.transactions.manage', $permissions);
        self::assertNotContains('cash.transactions.manage', $direct);
    }

    public function test_login_response_carries_permissions_for_frontend_gating(): void
    {
        $user = User::factory()->createOne([
            'phone_number' => '+237699555001',
            'password' => 'Password123!',
            'status' => User::STATUS_ACTIVE,
            'phone_verified_at' => now(),
        ]);
        $user->assignRole('loan-officer');

        $response = $this->postJson('/api/v1/login', [
            'phone_number' => '+237699555001',
            'password' => 'Password123!',
        ]);

        $this->assertJsonSuccess($response);
        $response->assertJsonPath('data.user.roles.0', 'loan-officer');

        $permissions = $response->json('data.user.permissions');
        self::assertIsArray($permissions);
        self::assertContains('loans.view', $permissions);
        self::assertContains('loans.create', $permissions);
        // loan-officer must not have cash management
        self::assertNotContains('cash.transactions.manage', $permissions);
    }

    private function createActiveUserWithRole(string $role): User
    {
        $user = User::factory()->createOne([
            'status' => User::STATUS_ACTIVE,
            'phone_verified_at' => now(),
        ]);
        $user->assignRole($role);

        return $user;
    }
}
