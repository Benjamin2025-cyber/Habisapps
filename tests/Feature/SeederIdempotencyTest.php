<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\StaffAgencyAssignment;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TestStaffAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SeederIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_seeding_survives_a_move_between_agencies(): void
    {
        // A staff member who has already served at an agency has a row occupying
        // (user_id, agency_id, starts_on). Repointing their open assignment onto
        // that agency collides with it -- uniq_staff_agency_start -- which is what
        // broke db:seed once the bench had moved somebody between agencies.
        $this->travelTo('2026-08-11 09:00:00');
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::factory()->createOne(['status' => User::STATUS_ACTIVE]);
        $agencyA = $this->makeAgency('SEQ-A');
        $agencyB = $this->makeAgency('SEQ-B');

        StaffAgencyAssignment::assignPrimary($user->id, $agencyA, 'accountant');
        StaffAgencyAssignment::assignPrimary($user->id, $agencyB, 'accountant');
        // Back to the first agency, whose historical row still holds today's key.
        StaffAgencyAssignment::assignPrimary($user->id, $agencyA, 'accountant');

        $open = DB::table('staff_agency_assignments')
            ->where('user_id', $user->id)
            ->whereNull('ends_on')
            ->get();

        self::assertCount(1, $open, 'Exactly one assignment may be open.');
        $row = $open->first();
        self::assertNotNull($row);
        self::assertSame($agencyA, (int) $row->agency_id);
    }

    private function makeAgency(string $code): int
    {
        return DB::table('agencies')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'code' => $code,
            'name' => $code.' Agency',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_staff_seeding_survives_being_re_run_on_a_later_day(): void
    {
        // The deployment runs `migrate --seed` on every push, so a seeder is
        // re-run on a different date than the one that first created its rows.
        // Keying a staff assignment on `starts_on => now()` matched nothing the
        // next day and inserted a second open-ended primary assignment, which
        // `staff_primary_assignment_no_overlap` refuses — so every deploy after
        // the first failed part-way through seeding.
        $this->travelTo('2026-08-09 10:00:00');
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(TestStaffAccountsSeeder::class);

        $firstRun = DB::table('staff_agency_assignments')
            ->where('is_primary', true)
            ->whereNull('ends_on')
            ->count();
        self::assertGreaterThan(0, $firstRun);

        // Same day: no change.
        $this->seed(TestStaffAccountsSeeder::class);

        // A later day, which is the case that broke.
        $this->travelTo('2026-08-10 21:33:00');
        $this->seed(TestStaffAccountsSeeder::class);
        $this->travelTo('2026-09-01 08:00:00');
        $this->seed(TestStaffAccountsSeeder::class);

        self::assertSame(
            $firstRun,
            DB::table('staff_agency_assignments')->where('is_primary', true)->whereNull('ends_on')->count(),
            'Re-seeding must move the open assignment, not add another.'
        );

        // And the original start date is kept: the staff member has been at the
        // agency since then, not since the most recent deployment.
        $starts = [];
        foreach (DB::table('staff_agency_assignments')->whereNull('ends_on')->distinct()->pluck('starts_on') as $date) {
            $starts[] = is_string($date) ? substr($date, 0, 10) : '';
        }
        self::assertSame(['2026-08-09'], $starts);
    }
}
