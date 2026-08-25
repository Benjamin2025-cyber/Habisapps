<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Everything a real installation needs, in dependency order.
 *
 * Each seeder here is idempotent and safe to re-run, so this is also how an
 * existing install picks up newly added reference data. Two of them are opt-in
 * through env (`SEED_BOOTSTRAP_ADMIN`, `SEED_DEFAULT_AGENCY`) because they mint
 * credentials and an unchangeable agency code respectively — they no-op unless
 * asked.
 *
 * Test-bench data is wired at the bottom and never runs in production.
 */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Reference data, no dependencies between them beyond roles first.
        $this->call(RolesAndPermissionsSeeder::class);

        $this->call(InstitutionProfileSeeder::class);

        $this->call(StandardReportDefinitionSeeder::class);

        $this->call(BatchProcedureSeeder::class);

        // The loan posting operations the application resolves by name. Fixed by
        // the application, not the institution: a code the code looks up but the
        // catalogue does not carry makes the matching charge uncollectable and
        // the loan undisbursable.
        $this->call(LoanOperationCodeSeeder::class);

        // The BEAC's notes and coins: fixed by the currency, not chosen by the
        // institution, and a till that requires denominations cannot be opened
        // without them.
        $this->call(DenominationSeeder::class);

        $this->call(BootstrapAdminSeeder::class);

        // Order matters from here: the chart puts every postable account under
        // the configured first agency. The local test bench below is deliberately
        // created afterwards and does not receive the installation chart.
        $this->call(DefaultAgencySeeder::class);

        $this->call(PcemfChartSeeder::class);

        // Agencies, staff accounts with known passwords and open accounting days
        // — a test bench, not installation data. Scoped to `local` rather than
        // "not production": under `testing` it would plant fixtures the suite
        // never asked for, and every test that seeds this class would pay for
        // building them.
        if (app()->environment('local')) {
            $this->call(ConsolidatedChartBenchSeeder::class);
        }

        // ConsolidatedChartDemoSeeder is deliberately not called: it posts
        // journal entries to reproduce the test guide's finished scenario, which
        // is something a tester asks for explicitly, not a side effect of
        // installing.
    }
}
