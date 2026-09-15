<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Agency;
use Database\Seeders\LoanSetupLedgerAccountSeeder;
use Database\Seeders\PcemfChartSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CORRECTIONS HABISLOAN 04/09/2026, « INTERFACE DE MISE EN PLACE »: a loan's
 * accounts are 3282 at court terme, 3181 at moyen terme and 3081 at long
 * terme, and the fees taken at set-up need 4591 and 4611. The signed chart
 * extract carries class 3 only from 32 up, so the two missing families are
 * created here — and where they hang decides which balance-sheet line they
 * report on, which is what these assertions protect.
 */
final class LoanSetupLedgerAccountSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_the_medium_and_long_term_credit_families(): void
    {
        $agency = $this->seedAgencyWithChart('001');

        $this->seed(LoanSetupLedgerAccountSeeder::class);

        // Grouping accounts are institution-wide; only the detail accounts the
        // loans post to are opened per agency.
        foreach (['30' => 'CREDITS A LONG TERME', '31' => 'CREDITS A MOYEN TERME'] as $code => $label) {
            $row = DB::table('ledger_accounts')->whereNull('agency_id')->where('code', (string) $code)->first(['name', 'is_postable']);
            self::assertIsObject($row, "Grouping account {$code} is missing.");
            self::assertSame($label, $row->name);
            self::assertFalse((bool) $row->is_postable);
        }

        foreach (['3081', '3181'] as $code) {
            $row = DB::table('ledger_accounts')->where('agency_id', $agency->id)->where('code', $code)->first(['is_postable']);
            self::assertIsObject($row, "Credit account {$code} is missing.");
            self::assertTrue((bool) $row->is_postable, "{$code} must take entries.");
        }
    }

    /**
     * Each new account under the family it belongs to. 4591 and 4611 are the
     * interesting ones: their parents 459 and 461 are themselves postable
     * agency accounts, so a lookup that only searched the institution tree
     * would silently attach them one level too high — to 45 and 46 — and
     * report the dossier fee under the wrong heading.
     */
    public function test_each_account_hangs_off_its_own_family(): void
    {
        $agency = $this->seedAgencyWithChart('002');

        $this->seed(LoanSetupLedgerAccountSeeder::class);

        $expected = [
            '3081' => '308',
            '3181' => '318',
            '4591' => '459',
            '4611' => '461',
        ];

        foreach ($expected as $code => $parent) {
            $parentCode = DB::table('ledger_accounts as child')
                ->join('ledger_accounts as parent', 'parent.id', '=', 'child.parent_account_id')
                ->where('child.agency_id', $agency->id)
                ->where('child.code', (string) $code)
                ->value('parent.code');

            self::assertIsString($parentCode, "No parent resolved for {$code}.");
            self::assertSame($parent, $parentCode, "Wrong parent for {$code}.");
        }
    }

    /**
     * Credits to clients are assets, so the two new roots must take debit —
     * inheriting credit would report every outstanding loan as a negative.
     */
    public function test_the_new_credit_roots_are_debit_side(): void
    {
        $this->seedAgencyWithChart('003');

        $this->seed(LoanSetupLedgerAccountSeeder::class);

        foreach (['30', '31'] as $code) {
            self::assertSame(
                'debit',
                DB::table('ledger_accounts')->whereNull('agency_id')->where('code', $code)->value('normal_balance_side'),
            );
        }
    }

    public function test_it_is_idempotent(): void
    {
        $this->seedAgencyWithChart('004');

        $this->seed(LoanSetupLedgerAccountSeeder::class);
        $first = DB::table('ledger_accounts')->whereIn('code', ['30', '308', '3081', '31', '318', '3181', '4591', '4611'])->count();

        $this->seed(LoanSetupLedgerAccountSeeder::class);
        self::assertSame(
            $first,
            DB::table('ledger_accounts')->whereIn('code', ['30', '308', '3081', '31', '318', '3181', '4591', '4611'])->count(),
            'Re-running the seeder duplicated accounts.',
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

        // 459 and 461 come from the chart; the new accounts hang off them.
        $this->seed(PcemfChartSeeder::class);

        return $agency;
    }
}
