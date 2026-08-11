<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\AccountingDay;
use App\Models\Agency;
use App\Models\StaffAgencyAssignment;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Spatie\Permission\Models\Role;

/**
 * Prepares the test bench the consolidated chart-of-accounts guide assumes.
 *
 * Run explicitly with:
 *   php artisan db:seed --class=ConsolidatedChartBenchSeeder
 *
 * docs/domain/consolidated-chart-of-accounts-test-guide.md §1.2 lists two
 * agencies, three open accounting days and two named users as "already
 * present". None of that exists on a fresh database — the second agency and the
 * head-office user in particular were built by hand. This seeder creates them so
 * the guide can be followed from `migrate:fresh --seed` without guesswork.
 *
 * It stops at the bench: no ledger accounts, no entries. Building the chart by
 * hand is the point of the guide's §3. Use ConsolidatedChartDemoSeeder to skip
 * ahead to the numbers instead.
 *
 * Test data, called from DatabaseSeeder only after the installation chart. It
 * remains chart-free when run on its own for the manual guide.
 */
final class ConsolidatedChartBenchSeeder extends Seeder
{
    public const string HEAD_OFFICE_EMAIL = 'test.chief.accountant@example.test';

    public const string AGENCY_ACCOUNTANT_EMAIL = 'test.cookbook.accountant@example.test';

    public const string PRIMARY_AGENCY_CODE = 'TEST-HABIS';

    public const string SECOND_AGENCY_CODE = 'AG-COOK-01';

    private const string TEST_PASSWORD = 'password123';

    public function run(): void
    {
        if (app()->environment('production') && ! (bool) env('ALLOW_TEST_STAFF_SEEDING', false)) {
            throw new LogicException(
                'Consolidated-chart bench seeding is disabled in production. Set ALLOW_TEST_STAFF_SEEDING=true only on an intentionally isolated test installation.'
            );
        }

        $this->call(TestStaffAccountsSeeder::class);

        DB::transaction(function (): void {
            $primary = Agency::query()->where('code', self::PRIMARY_AGENCY_CODE)->firstOrFail();
            $second = $this->secondAgency();

            // The guide needs an agency accountant in the *other* agency, so that
            // a cross-agency refusal is a real refusal and not a same-agency pass.
            // This is a dedicated user rather than a relocation of
            // TestStaffAccountsSeeder's accountant: staff_agency_assignments
            // excludes overlapping date ranges per user, so moving a shared
            // fixture between agencies makes that seeder non-idempotent.
            $accountant = $this->agencyAccountant($second);

            $chief = $this->headOfficeUser();

            $openedBy = User::query()->whereNotNull('email')->orderBy('id')->first();
            $businessDate = now()->toDateString();
            $this->openDay(AccountingDay::SCOPE_INSTITUTION, null, $businessDate, $openedBy);
            $this->openDay(AccountingDay::SCOPE_AGENCY, $primary->id, $businessDate, $openedBy);
            $this->openDay(AccountingDay::SCOPE_AGENCY, $second->id, $businessDate, $openedBy);

            $this->report($primary, $second, $accountant, $chief, $businessDate);
        });
    }

    private function secondAgency(): Agency
    {
        return Agency::query()->updateOrCreate(
            ['code' => self::SECOND_AGENCY_CODE],
            [
                'name' => 'Cookbook Test Agency',
                'region' => 'Test Region',
                'city' => 'Test City',
                'branch_name' => 'Cookbook Automated Test Branch',
                'branch_type' => 'test',
                'creation_date' => now()->toDateString(),
                'status' => Agency::STATUS_ACTIVE,
            ]
        );
    }

    /**
     * The head office (chef comptable) carries **no** agency assignment — that is
     * the point of the role and something the guide checks explicitly.
     */
    private function headOfficeUser(): User
    {
        $user = $this->testUser(
            self::HEAD_OFFICE_EMAIL,
            'Test Chief Accountant',
            '+237690000011',
            'chief-accountant',
            null,
        );

        // Head office is attached to no branch, and the guide checks that the
        // role works without one.
        StaffAgencyAssignment::query()->where('user_id', $user->id)->delete();

        return $user->refresh();
    }

    private function testUser(string $email, string $name, string $phone, string $roleName, ?Agency $agency): User
    {
        $user = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'phone_number' => $phone,
                'password' => self::TEST_PASSWORD,
                'status' => User::STATUS_ACTIVE,
                'job_title' => $name,
                'agency_id' => $agency?->id,
                'agency_code' => $agency?->code,
                'agency_name' => $agency?->name,
            ]
        );

        $user->forceFill([
            'email_verified_at' => now(),
            'phone_verified_at' => now(),
            'activated_at' => now(),
            'status' => User::STATUS_ACTIVE,
            'agency_id' => $agency?->id,
            'agency_code' => $agency?->code,
            'agency_name' => $agency?->name,
        ])->save();

        $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();
        if (! $role instanceof Role) {
            throw new LogicException("Role [{$roleName}] does not exist. Run RolesAndPermissionsSeeder first.");
        }
        $user->syncRoles([$role]);

        return $user->refresh();
    }

    private function agencyAccountant(Agency $agency): User
    {
        $user = $this->testUser(
            self::AGENCY_ACCOUNTANT_EMAIL,
            'Test Cookbook Accountant',
            '+237690000012',
            'accountant',
            $agency,
        );

        StaffAgencyAssignment::assignPrimary($user->id, $agency->id, 'accountant');

        return $user->refresh();
    }

    private function openDay(string $scopeType, ?int $agencyId, string $businessDate, ?User $openedBy): AccountingDay
    {
        $existing = AccountingDay::query()
            ->where('scope_type', $scopeType)
            ->where('agency_id', $agencyId)
            ->where('status', AccountingDay::STATUS_OPEN)
            ->first();

        if ($existing instanceof AccountingDay) {
            return $existing;
        }

        return AccountingDay::query()->create([
            'public_id' => (string) Str::ulid(),
            'scope_type' => $scopeType,
            'agency_id' => $agencyId,
            'business_date' => $businessDate,
            'calendar_opened_at' => now(),
            'status' => AccountingDay::STATUS_OPEN,
            'is_holiday' => false,
            'opened_by_user_id' => $openedBy?->id,
            'origin' => 'manual',
        ]);
    }

    private function report(Agency $primary, Agency $second, User $accountant, User $chief, string $businessDate): void
    {
        $this->command->info('Consolidated-chart test bench ready.');
        $this->command->line(sprintf('%-22s %s — %s', 'Agency', $primary->code, $primary->name));
        $this->command->line(sprintf('%-22s %s — %s', 'Agency', $second->code, $second->name));
        $this->command->line(sprintf('%-22s %s (institution + both agencies)', 'Open accounting day', $businessDate));
        $this->command->line(sprintf('%-22s %s (no agency)', 'chief-accountant', $chief->email));
        $this->command->line(sprintf('%-22s %s (agency %s)', 'accountant', $accountant->email, $second->code));
        $this->command->line(sprintf('%-22s %s', 'Password', self::TEST_PASSWORD));
        $this->command->line('Next: follow the guide from §3, or run ConsolidatedChartDemoSeeder to jump to §5.');
    }
}
