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

        $this->call(BootstrapAdminSeeder::class);

        // Order matters from here: the chart puts every postable account under
        // an agency, so with none it can only create the institution-level
        // grouping accounts and would need re-running after the first agency.
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
