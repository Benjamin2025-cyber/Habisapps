<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Agency;
use App\Models\StaffAgencyAssignment;
use App\Models\User;
use Database\Seeders\TestStaffAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class TestStaffAccountsSeederTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string> */
    private const array STAFF_ROLES = [
        'user-admin',
        'agency-manager',
        'regional-manager',
        'teller',
        'loan-officer',
        'accountant',
        'kyc-officer',
        'compliance-officer',
        'auditor',
        'staff',
    ];

    public function test_seeder_creates_active_accounts_with_roles_permissions_and_agency_assignments(): void
    {
        self::assertSame(0, Artisan::call('db:seed', [
            '--class' => TestStaffAccountsSeeder::class,
        ]));

        $agency = Agency::query()->where('code', 'TEST-HABIS')->first();
        self::assertInstanceOf(Agency::class, $agency);
        self::assertSame('HABIS Test Agency', $agency->name);

        foreach (self::STAFF_ROLES as $roleName) {
            $email = 'test.'.str_replace('-', '.', $roleName).'@example.test';
            $user = User::query()->where('email', $email)->first();

            self::assertInstanceOf(User::class, $user, "Missing seeded account for {$roleName}.");
            self::assertSame(User::STATUS_ACTIVE, $user->status);
            self::assertNotNull($user->email_verified_at);
            self::assertNotNull($user->phone_verified_at);
            self::assertNotNull($user->activated_at);
            self::assertSame($agency->id, $user->agency_id);
            self::assertTrue($user->hasRole($roleName));
            self::assertTrue(Hash::check('password123', (string) $user->password));

            $assignment = StaffAgencyAssignment::query()
                ->where('user_id', $user->id)
                ->where('agency_id', $agency->id)
                ->first();

            self::assertInstanceOf(StaffAgencyAssignment::class, $assignment);
            self::assertSame($roleName, $assignment->role_at_agency);
            self::assertTrue($assignment->is_primary);
            self::assertSame(StaffAgencyAssignment::STATUS_ACTIVE, $assignment->status);
            self::assertNotEmpty($assignment->public_id);
        }

        $accountant = User::query()->where('email', 'test.accountant@example.test')->firstOrFail();
        $complianceOfficer = User::query()->where('email', 'test.compliance.officer@example.test')->firstOrFail();
        self::assertTrue($accountant->can('loans.update'));
        self::assertTrue($complianceOfficer->can('loans.update'));
        self::assertSame(
            User::query()->where('email', 'test.agency.manager@example.test')->value('id'),
            $agency->manager_id
        );
    }

    public function test_seeder_is_idempotent_and_does_not_create_a_platform_admin_account(): void
    {
        Artisan::call('db:seed', ['--class' => TestStaffAccountsSeeder::class]);
        Artisan::call('db:seed', ['--class' => TestStaffAccountsSeeder::class]);

        self::assertSame(10, DB::table('users')->where('email', 'like', 'test.%@example.test')->count());
        self::assertSame(10, DB::table('staff_agency_assignments')->count());
        self::assertDatabaseMissing('users', ['email' => 'test.platform.admin@example.test']);
    }
}
