<?php

declare(strict_types=1);

namespace App\Application\Agencies;

use App\Models\Agency;
use Database\Seeders\LoanSetupLedgerAccountSeeder;
use Database\Seeders\LoanSetupMappingSeeder;
use Database\Seeders\PcemfChartSeeder;
use Database\Seeders\TillHierarchyLedgerAccountSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Gives an agency the accounts it needs to function.
 *
 * A new agency used to come up with an empty chart. Nothing said so: the
 * screens simply offered no accounts, tills could not be opened, and no
 * operation could be posted. The accounts only appeared the next time someone
 * ran the seeders on the server by hand — which is not a thing anyone should
 * have to know, or remember.
 *
 * So provisioning happens where the agency is created, inside the same
 * transaction: an agency without a chart is not a usable agency, and it should
 * not be possible to end up with one. Roughly a thousand accounts are written,
 * which measures well under a second — cheap for something done a handful of
 * times in the life of an institution, and far more predictable than a queued
 * job that can fail quietly and leave the agency broken with nobody looking.
 *
 * The work is delegated to the seeders rather than restated here. They are the
 * definition of the chart, they are idempotent, and they already skip what an
 * agency has: one description of the chart, used at install, at deploy and
 * here.
 */
final class ProvisionAgencyLedgerAccounts
{
    /**
     * Seeders run in dependency order: the PCEMF chart first because the caisse
     * hierarchy hangs off its 5710 and the loan accounts off its 459 and 461;
     * the mapping seeder last because it needs both an operation code and the
     * 4611 the seeder above it creates.
     *
     * @var array<int, class-string<Seeder>>
     */
    private const array CHART_SEEDERS = [
        PcemfChartSeeder::class,
        TillHierarchyLedgerAccountSeeder::class,
        LoanSetupLedgerAccountSeeder::class,
        LoanSetupMappingSeeder::class,
    ];

    /**
     * @return int the number of ledger accounts the agency now holds
     */
    public function execute(Agency $agency): int
    {
        foreach (self::CHART_SEEDERS as $seeder) {
            app($seeder)->run();
        }

        return DB::table('ledger_accounts')->where('agency_id', $agency->id)->count();
    }
}
