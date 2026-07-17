<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Agency;
use App\Models\StaffAgencyAssignment;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;
use Spatie\Permission\Models\Role;

/**
 * Creates a complete, deterministic staff test bench.
 *
 * Run explicitly with:
 *   php artisan db:seed --class=TestStaffAccountsSeeder
 *
 * The normal DatabaseSeeder intentionally does not call this seeder. It is
 * test data and must never be created accidentally during a production seed.
 * BootstrapAdminSeeder remains responsible for the platform-admin account.
 */
final class TestStaffAccountsSeeder extends Seeder
{
    private const string TEST_AGENCY_CODE = 'TEST-HABIS';

    private const string TEST_PASSWORD = 'password123';

    /** @var array<string, array{name: string, phone: string, job_title: string}> */
    private const array STAFF = [
        'user-admin' => [
            'name' => 'Test User Administrator',
            'phone' => '+237690000001',
            'job_title' => 'Test User Administrator',
        ],
        'agency-manager' => [
            'name' => 'Test Agency Manager',
            'phone' => '+237690000002',
            'job_title' => 'Test Agency Manager',
        ],
        'regional-manager' => [
            'name' => 'Test Regional Manager',
            'phone' => '+237690000003',
            'job_title' => 'Test Regional Manager',
        ],
        'teller' => [
            'name' => 'Test Teller',
            'phone' => '+237690000004',
            'job_title' => 'Test Teller',
        ],
        'loan-officer' => [
            'name' => 'Test Loan Officer',
            'phone' => '+237690000005',
            'job_title' => 'Test Loan Officer',
        ],
        'accountant' => [
            'name' => 'Test Accountant',
            'phone' => '+237690000006',
            'job_title' => 'Test Accountant',
        ],
        'kyc-officer' => [
            'name' => 'Test KYC Officer',
            'phone' => '+237690000007',
            'job_title' => 'Test KYC Officer',
        ],
        'compliance-officer' => [
            'name' => 'Test Compliance Officer',
            'phone' => '+237690000008',
            'job_title' => 'Test Compliance Officer',
        ],
        'auditor' => [
            'name' => 'Test Auditor',
            'phone' => '+237690000009',
            'job_title' => 'Test Auditor',
        ],
        'staff' => [
            'name' => 'Test General Staff',
            'phone' => '+237690000010',
            'job_title' => 'Test General Staff',
        ],
    ];

    public function run(): void
    {
        if (app()->environment('production') && ! (bool) env('ALLOW_TEST_STAFF_SEEDING', false)) {
            throw new LogicException(
                'Test staff seeding is disabled in production. Set ALLOW_TEST_STAFF_SEEDING=true only on an intentionally isolated test installation.'
            );
        }

        $this->call(RolesAndPermissionsSeeder::class);

        $agency = DB::transaction(function (): Agency {
            $agency = Agency::query()->updateOrCreate(
                ['code' => self::TEST_AGENCY_CODE],
                [
                    'name' => 'HABIS Test Agency',
                    'region' => 'Test Region',
                    'city' => 'Test City',
                    'branch_name' => 'HABIS Automated Test Branch',
                    'branch_type' => 'test',
                    'creation_date' => now()->toDateString(),
                    'status' => Agency::STATUS_ACTIVE,
                ]
            );

            $users = [];
            foreach (self::STAFF as $roleName => $definition) {
                $email = 'test.'.str_replace('-', '.', $roleName).'@example.test';
                $user = User::query()->updateOrCreate(
                    ['email' => $email],
                    [
                        'name' => $definition['name'],
                        'phone_number' => $definition['phone'],
                        'password' => self::TEST_PASSWORD,
                        'status' => User::STATUS_ACTIVE,
                        'job_title' => $definition['job_title'],
                        'agency_id' => $agency->id,
                        'agency_code' => $agency->code,
                        'agency_name' => $agency->name,
                    ]
                );

                $user->forceFill([
                    'email_verified_at' => now(),
                    'phone_verified_at' => now(),
                    'activated_at' => now(),
                    'status' => User::STATUS_ACTIVE,
                    'agency_id' => $agency->id,
                    'agency_code' => $agency->code,
                    'agency_name' => $agency->name,
                ])->save();

                $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();
                if (! $role instanceof Role) {
                    throw new LogicException("Role [{$roleName}] does not exist after seeding roles.");
                }
                $user->syncRoles([$role]);

                // LoanApprovalWorkflow also checks loans.update before the
                // stage-specific approval permission. Keep this test bench
                // usable without changing the production role definitions.
                if (in_array($roleName, ['accountant', 'compliance-officer'], true)) {
                    $user->givePermissionTo('loans.update');
                }

                StaffAgencyAssignment::query()->updateOrCreate(
                    [
                        'user_id' => $user->id,
                        'agency_id' => $agency->id,
                        'starts_on' => now()->toDateString(),
                    ],
                    [
                        'role_at_agency' => $roleName,
                        'ends_on' => null,
                        'is_primary' => true,
                        'status' => StaffAgencyAssignment::STATUS_ACTIVE,
                    ]
                );

                $users[$roleName] = $user->refresh();
            }

            $manager = $users['agency-manager'] ?? null;
            if ($manager instanceof User) {
                $agency->forceFill(['manager_id' => $manager->id])->save();
            }

            return $agency->refresh();
        });

        $this->command->info('Test staff accounts ready in agency '.$agency->code.'.');
        $this->command->info('Login password: '.self::TEST_PASSWORD);
        foreach (self::STAFF as $roleName => $definition) {
            $this->command->line(sprintf(
                '%-20s %s | %s',
                $roleName,
                'test.'.str_replace('-', '.', $roleName).'@example.test',
                $definition['phone']
            ));
        }
    }
}
