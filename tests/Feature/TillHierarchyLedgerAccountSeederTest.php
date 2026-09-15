<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Agency;
use Database\Seeders\PcemfChartSeeder;
use Database\Seeders\TillHierarchyLedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CORRECTIONS HABISLOAN 04/09/2026, « INTERFACE DES CAISSES »: the accounting
 * team's four-level caisse model needs a ledger account behind every caisse,
 * and gave the exact codes. This pins them, because a caisse whose account is
 * missing cannot be opened and one whose parent is wrong reports its cash in
 * the wrong place.
 */
final class TillHierarchyLedgerAccountSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_every_caisse_account_the_accounting_team_listed(): void
    {
        $agency = $this->seedAgencyWithChart('001');

        $this->seed(TillHierarchyLedgerAccountSeeder::class);

        $expected = [
            '57101' => "Caisse générale d'agence",
            '571011' => 'Caisse de guichet 1',
            '571012' => 'Caisse de guichet 2',
            '5710112' => 'Caisse de fonctionnement — guichet 1',
            '5710113' => 'Caisse des opérations courantes — guichet 1',
            '5710114' => 'Caisse des opérations Campost — guichet 1',
            '5710115' => 'Caisse des opérations de bancassurance — guichet 1',
            '5710116' => 'Caisse des opérations de transferts nationaux — guichet 1',
            '5710117' => 'Caisse des opérations de transferts internationaux — guichet 1',
            '5710118' => 'Caisse des opérations Orange Money — guichet 1',
            '5710119' => 'Caisse des opérations MTN Mobile Money — guichet 1',
            '5710122' => 'Caisse de fonctionnement — guichet 2',
            '5710123' => 'Caisse des opérations courantes — guichet 2',
            '5710124' => 'Caisse des opérations Campost — guichet 2',
            '5710125' => 'Caisse des opérations de bancassurance — guichet 2',
            '5710126' => 'Caisse des opérations de transferts nationaux — guichet 2',
            '5710127' => 'Caisse des opérations de transferts internationaux — guichet 2',
            '5710128' => 'Caisse des opérations Orange Money — guichet 2',
            '5710129' => 'Caisse des opérations MTN Mobile Money — guichet 2',
        ];

        foreach ($expected as $code => $label) {
            $row = DB::table('ledger_accounts')
                ->where('agency_id', $agency->id)
                ->where('code', (string) $code)
                ->first(['name', 'is_postable']);

            self::assertIsObject($row, "Caisse account {$code} was not created.");
            self::assertSame($label, $row->name);
            // Every level holds real cash, so every level takes entries.
            self::assertTrue((bool) $row->is_postable, "Caisse account {$code} must be postable.");
        }
    }

    /**
     * The tree the accounting team drew: each sub-caisse under its guichet,
     * each guichet under the caisse générale, and that under 5710.
     */
    public function test_each_caisse_hangs_off_the_level_above_it(): void
    {
        $agency = $this->seedAgencyWithChart('002');

        $this->seed(TillHierarchyLedgerAccountSeeder::class);

        $parents = [
            '57101' => '5710',
            '571011' => '57101',
            '571012' => '57101',
            '5710113' => '571011',
            '5710129' => '571012',
        ];

        foreach ($parents as $code => $expectedParent) {
            $parentCode = DB::table('ledger_accounts as child')
                ->join('ledger_accounts as parent', 'parent.id', '=', 'child.parent_account_id')
                ->where('child.agency_id', $agency->id)
                ->where('child.code', (string) $code)
                ->value('parent.code');

            self::assertIsString($parentCode, "No parent resolved for {$code}.");
            self::assertSame($expectedParent, $parentCode, "Wrong parent for {$code}.");
        }
    }

    /**
     * Deployment re-runs seeders on every push, and adding an agency must
     * backfill only that agency.
     */
    public function test_it_is_idempotent_and_backfills_a_new_agency(): void
    {
        $this->seedAgencyWithChart('003');
        $this->seed(TillHierarchyLedgerAccountSeeder::class);

        $countAfterFirstRun = DB::table('ledger_accounts')->where('code', 'like', '5710%')->count();

        $this->seed(TillHierarchyLedgerAccountSeeder::class);
        self::assertSame(
            $countAfterFirstRun,
            DB::table('ledger_accounts')->where('code', 'like', '5710%')->count(),
            'Re-running the seeder duplicated caisse accounts.',
        );

        $second = $this->seedAgencyWithChart('004');
        $this->seed(TillHierarchyLedgerAccountSeeder::class);

        self::assertSame(
            19,
            DB::table('ledger_accounts')
                ->where('agency_id', $second->id)
                ->where('code', 'like', '5710%')
                ->where('code', '!=', '5710')
                ->count(),
            'The new agency did not get its own caisse hierarchy.',
        );
    }

    private function seedAgencyWithChart(string $code): Agency
    {
        $agency = Agency::query()->create([
            'public_id' => (string) Str::ulid(),
            'code' => $code,
            'name' => 'Agence '.$code,
            'status' => 'active',
        ]);

        // The hierarchy hangs off the chart's 5710, so the chart goes first.
        $this->seed(PcemfChartSeeder::class);

        return $agency;
    }
}
