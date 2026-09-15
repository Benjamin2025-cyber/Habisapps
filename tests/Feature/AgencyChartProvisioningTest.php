<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\Agencies\CreateAgency;
use App\Models\Agency;
use Database\Seeders\LoanOperationCodeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * An agency created through the application must come up usable.
 *
 * It did not: the chart of accounts was only ever written by the seeders, run
 * by hand on the server. An agency opened between two deployments had no
 * accounts at all — no till could be opened, no operation posted — and nothing
 * on screen said why. Provisioning now happens inside agency creation, and
 * these tests are what stop it drifting back out.
 */
final class AgencyChartProvisioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_agency_comes_up_with_its_chart_of_accounts(): void
    {
        $agency = app(CreateAgency::class)->execute([
            'code' => '007',
            'name' => 'Agence Tsinga',
        ]);

        $accounts = DB::table('ledger_accounts')->where('agency_id', $agency->id);

        // The PCEMF detail accounts, not a handful of stragglers.
        self::assertGreaterThan(1000, $accounts->count(), 'The agency came up without its chart.');

        // And the three things this institution's own documents add on top:
        // the cash account the tills hang off, the caisse hierarchy, and the
        // credit families a mise en place posts to.
        foreach (['5710', '57101', '571011', '5710119', '3282', '3181', '3081', '4591', '4592', '4611'] as $code) {
            self::assertTrue(
                DB::table('ledger_accounts')->where('agency_id', $agency->id)->where('code', $code)->exists(),
                "Account {$code} is missing from the new agency.",
            );
        }
    }

    /**
     * The guichet's fonds de solidarité décès has to be pointed at 4611, or the
     * first disbursement in the new agency stops on an unmapped operation.
     */
    public function test_a_new_agency_comes_up_with_its_loan_setup_mappings(): void
    {
        // Operation codes are institution-wide and seeded once at install, not
        // per agency; a real installation already has them.
        $this->seed(LoanOperationCodeSeeder::class);

        $agency = app(CreateAgency::class)->execute([
            'code' => '008',
            'name' => 'Agence Mvog-Ada',
        ]);

        $mapped = DB::table('operation_account_mappings as m')
            ->join('operation_codes as c', 'c.id', '=', 'm.operation_code_id')
            ->join('ledger_accounts as l', 'l.id', '=', 'm.credit_ledger_account_id')
            ->where('m.agency_id', $agency->id)
            ->where('c.code', 'loan_setup_death_fund')
            ->value('l.code');

        self::assertSame('4611', $mapped);
    }

    /**
     * Existing agencies keep the accounts they already had; provisioning a new
     * one must not touch them, and must not duplicate anything.
     */
    public function test_provisioning_leaves_existing_agencies_alone(): void
    {
        $first = app(CreateAgency::class)->execute(['code' => '009', 'name' => 'Première']);
        $before = DB::table('ledger_accounts')->where('agency_id', $first->id)->count();

        app(CreateAgency::class)->execute(['code' => '010', 'name' => 'Seconde']);

        self::assertSame(
            $before,
            DB::table('ledger_accounts')->where('agency_id', $first->id)->count(),
            'Creating a second agency changed the first one\'s chart.',
        );

        // No code appears twice inside one agency.
        $duplicates = DB::table('ledger_accounts')
            ->where('agency_id', $first->id)
            ->select('code')
            ->groupBy('code')
            ->havingRaw('count(*) > 1')
            ->count();

        self::assertSame(0, $duplicates);
    }

    /**
     * Provisioning is part of creating the agency, not a step after it: if the
     * chart cannot be written, there must be no agency left behind either.
     */
    public function test_the_agency_and_its_chart_are_written_together(): void
    {
        $before = DB::table('agencies')->count();

        try {
            app(CreateAgency::class)->execute([
                'code' => '011',
                'name' => 'Agence Rollback',
                'manager_public_id' => 'does-not-exist',
            ]);
            self::fail('Expected the creation to be refused.');
        } catch (\Throwable) {
            // Expected: the manager lookup fails inside the transaction.
        }

        self::assertSame($before, DB::table('agencies')->count(), 'A refused creation left an agency behind.');
        self::assertFalse(
            DB::table('ledger_accounts')
                ->join('agencies', 'agencies.id', '=', 'ledger_accounts.agency_id')
                ->where('agencies.code', '011')
                ->exists(),
            'A refused creation left a chart behind.',
        );
    }
}
