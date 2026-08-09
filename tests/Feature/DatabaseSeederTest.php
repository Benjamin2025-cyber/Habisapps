<?php

declare(strict_types=1);

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DefaultAgencySeeder;
use Database\Seeders\DenominationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * What a real installation gets from `migrate:fresh --seed`.
 *
 * The point of these is the *order*: the chart hangs off an agency, so wiring
 * the two in the wrong sequence silently produces a chart with no postable
 * accounts rather than an error.
 */
final class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_fresh_install_is_usable_when_the_first_agency_is_configured(): void
    {
        config([
            'security.default_agency.enabled' => true,
            'security.default_agency.code' => '001',
            'security.default_agency.name' => 'Siège',
        ]);

        $this->seed(DatabaseSeeder::class);

        self::assertSame(1, DB::table('agencies')->count());
        self::assertGreaterThan(0, DB::table('roles')->count());
        self::assertSame(1, DB::table('institution_profile')->count());
        self::assertGreaterThan(0, DB::table('report_definitions')->count());
        self::assertGreaterThan(0, DB::table('batch_procedures')->count());

        // The chart ran *after* the agency, so it has postable accounts. Ordered
        // the other way this count is zero and nothing complains.
        $agencyId = DB::table('agencies')->value('id');
        self::assertGreaterThan(1000, DB::table('ledger_accounts')->where('agency_id', $agencyId)->count());
        self::assertGreaterThan(300, DB::table('ledger_accounts')->whereNull('agency_id')->count());
    }

    public function test_without_the_opt_in_no_agency_is_invented(): void
    {
        config(['security.default_agency.enabled' => false]);

        $this->seed(DatabaseSeeder::class);

        // An agency code cannot be changed once created, so a placeholder would
        // saddle the institution with an identifier it cannot correct.
        self::assertSame(0, DB::table('agencies')->count());

        // The institution skeleton is still loaded, ready for the chart to be
        // completed per agency once the network is created.
        self::assertGreaterThan(300, DB::table('ledger_accounts')->whereNull('agency_id')->count());
        self::assertSame(0, DB::table('ledger_accounts')->whereNotNull('agency_id')->count());
    }

    public function test_it_seeds_the_beac_notes_and_coins_at_the_account_scale(): void
    {
        $this->seed(DenominationSeeder::class);

        // Gamme 2020: five notes, 500–10 000. Type 2024: nine coins, 1–500,
        // the 200 being the newest (circulating since 2 April 2025).
        self::assertSame(5, DB::table('denominations')->where('type', 'banknote')->count());
        self::assertSame(9, DB::table('denominations')->where('type', 'coin')->count());

        // The 500 exists in both forms, so a drawer of 500 coins is counted as
        // coins rather than misrecorded as notes.
        self::assertSame(2, DB::table('denominations')->where('value_minor', 500 * 100)->count());
        self::assertSame(1, DB::table('denominations')->where('type', 'coin')->where('value_minor', 200 * 100)->count());

        // There is no 10-franc note: the 10 is a coin.
        self::assertSame(0, DB::table('denominations')->where('type', 'banknote')->where('value_minor', 10 * 100)->count());

        // Face value is stored in minor units at the account scale, because
        // TellerSessionWorkflow compares `value_minor * count` against the
        // declared opening float. A 1 000 note counted once must be 1 000 francs.
        $note1000 = DB::table('denominations')->where('code', 'XAF-1000-B')->first(['value_minor']);
        self::assertNotNull($note1000);
        self::assertSame(100000, (int) $note1000->value_minor);

        // Idempotent: re-running adds nothing.
        $this->seed(DenominationSeeder::class);
        self::assertSame(14, DB::table('denominations')->count());
    }

    public function test_the_first_agency_seeder_never_adds_to_an_existing_network(): void
    {
        config([
            'security.default_agency.enabled' => true,
            'security.default_agency.code' => 'ZZZ',
            'security.default_agency.name' => 'Should not appear',
        ]);
        DB::table('agencies')->insert([
            'public_id' => (string) Str::ulid(),
            'code' => 'EXISTING',
            'name' => 'Existing agency',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->seed(DefaultAgencySeeder::class);

        self::assertSame(1, DB::table('agencies')->count());
        self::assertSame('EXISTING', DB::table('agencies')->value('code'));
    }
}
