<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\LedgerAccount;
use Database\Seeders\PcemfChartSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PcemfChartSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_loads_the_institution_chart_with_agency_detail_accounts(): void
    {
        $agencyA = $this->createAgency('PCEMF-A');
        $agencyB = $this->createAgency('PCEMF-B');

        $this->seed(PcemfChartSeeder::class);

        // Grouping accounts exist once, at institution level: they are the
        // consolidating skeleton, so duplicating them per agency would mean the
        // institution total had nowhere single to live.
        $institution = DB::table('ledger_accounts')->whereNull('agency_id');
        self::assertGreaterThan(300, (clone $institution)->count());
        self::assertSame(0, (clone $institution)->where('is_postable', true)->count());

        // Detail accounts are repeated per agency under the *same* code — the
        // chart is one nomenclature, and 57/571 is the same account everywhere.
        foreach ([$agencyA, $agencyB] as $agency) {
            $detail = DB::table('ledger_accounts')->where('agency_id', $agency['id']);
            self::assertGreaterThan(1000, (clone $detail)->count());
            self::assertSame(0, (clone $detail)->where('is_postable', false)->count());
        }

        // `571 Billets et Monnaies` totalises `5710`/`5711`, so it is a grouping
        // account and exists only once, at institution level.
        self::assertSame(1, DB::table('ledger_accounts')->where('code', '571')->count());
        self::assertSame(0, DB::table('ledger_accounts')->where('code', '571')->where('is_postable', true)->count());

        // A leaf, by contrast, carries the same code in every agency — that is
        // what "one nomenclature, kept by each agency" means, and it is only
        // legal because the unique indexes are partitioned on agency_id.
        $leafRow = DB::table('ledger_accounts')->where('is_postable', true)->first(['code']);
        self::assertNotNull($leafRow);
        $leaf = (string) $leafRow->code;
        self::assertSame(2, DB::table('ledger_accounts')->where('code', $leaf)->count());
        self::assertSame(0, DB::table('ledger_accounts')->where('code', $leaf)->whereNull('agency_id')->count());
    }

    public function test_every_account_carries_the_class_its_code_implies(): void
    {
        $this->createAgency('PCEMF-C');
        $this->seed(PcemfChartSeeder::class);

        // The rule the API enforces on write must hold for seeded rows too,
        // otherwise the chart cannot be edited afterwards without first being
        // corrected.
        $wrong = [];
        foreach (DB::table('ledger_accounts')->get(['code', 'account_class']) as $row) {
            $code = (string) $row->code;
            if (LedgerAccount::classImpliedByCode($code) !== (string) $row->account_class) {
                $wrong[] = $code;
            }
        }
        self::assertSame([], $wrong);
    }

    public function test_the_hierarchy_is_connected_and_agency_leaves_hang_off_the_institution_tree(): void
    {
        $agency = $this->createAgency('PCEMF-D');
        $this->seed(PcemfChartSeeder::class);

        $institutionIds = DB::table('ledger_accounts')->whereNull('agency_id')->pluck('id')->all();

        // A detail account's parent is an *institution* account, which is what
        // makes one grouping account total every agency at once. Cross-agency
        // parenting is refused by the API, so anything else would be unmaintainable.
        $orphans = DB::table('ledger_accounts')
            ->where('agency_id', $agency['id'])
            ->whereNotNull('parent_account_id')
            ->whereNotIn('parent_account_id', $institutionIds)
            ->count();
        self::assertSame(0, $orphans);

        // Only the two-digit roots sit at the top; everything deeper is attached.
        $rootless = DB::table('ledger_accounts')
            ->whereNull('parent_account_id')
            ->whereRaw('length(code) > 2')
            ->count();
        self::assertSame(0, $rootless);
    }

    public function test_it_is_idempotent_and_backfills_a_new_agency(): void
    {
        $first = $this->createAgency('PCEMF-E');
        $this->seed(PcemfChartSeeder::class);
        $afterFirst = DB::table('ledger_accounts')->count();

        $this->seed(PcemfChartSeeder::class);
        self::assertSame($afterFirst, DB::table('ledger_accounts')->count(), 'Re-running must not duplicate.');

        $second = $this->createAgency('PCEMF-F');
        $this->seed(PcemfChartSeeder::class);

        $perAgency = DB::table('ledger_accounts')->where('agency_id', $first['id'])->count();
        self::assertSame($perAgency, DB::table('ledger_accounts')->where('agency_id', $second['id'])->count());
    }

    /**
     * @return array{id: int, public_id: string, code: string}
     */
    private function createAgency(string $code): array
    {
        $id = DB::table('agencies')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'code' => $code,
            'name' => $code.' Agency',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('agencies')->where('id', $id)->first(['public_id']);
        self::assertNotNull($row);

        return ['id' => $id, 'public_id' => (string) $row->public_id, 'code' => $code];
    }
}
