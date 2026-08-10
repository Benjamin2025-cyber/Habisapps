<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Accounting\SoldesIntermediairesDeGestion as Sig;
use Database\Seeders\PcemfChartSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SoldesIntermediairesDeGestionTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_code_the_formulas_reference_exists_in_the_chart(): void
    {
        $this->createAgency('SIG-REF');
        $this->seed(PcemfChartSeeder::class);

        // A formula term naming a prefix no account carries does not fail: it
        // sums nothing and contributes zero, quietly, for as long as the report
        // exists. 6611 is the case in point — it had to be created for solde 86
        // to mean anything, and before that this test would have caught its
        // absence rather than the compte de résultat being silently short.
        $missing = [];
        foreach (Sig::referencedCodePrefixes() as $prefix) {
            $exists = DB::table('ledger_accounts')
                ->whereRaw('left(code, ?) = ?', [strlen($prefix), $prefix])
                ->exists();

            if (! $exists) {
                $missing[] = $prefix;
            }
        }

        self::assertSame([], $missing, 'A solde intermédiaire references a code the chart does not have.');
    }

    public function test_the_corporate_income_tax_is_isolated_from_the_other_direct_taxes(): void
    {
        $this->createAgency('SIG-TAX');
        $this->seed(PcemfChartSeeder::class);

        // The whole point of the 2026-08-10 correction: 661 carries the corporate
        // income tax *and* other direct taxes. Solde 86 must hold only the
        // former, and solde 82 must keep the latter, so the two cannot share an
        // account. 661 is therefore a grouping with the tax split out.
        $parent = DB::table('ledger_accounts')->where('code', '661')->first(['is_postable']);
        self::assertNotNull($parent);
        self::assertFalse((bool) $parent->is_postable, '661 consolidates its children and takes no entries of its own.');

        // Both children are postable, in every agency. Without 6612 the other
        // direct taxes would have had nowhere to go, since their parent refuses
        // entries — which would have made the team's own requirement, that they
        // stay in the résultat d'exploitation, impossible to satisfy.
        foreach (['6611', '6612'] as $code) {
            $rows = DB::table('ledger_accounts')->where('code', $code)->get(['agency_id', 'is_postable']);
            self::assertGreaterThan(0, $rows->count(), "Account {$code} is missing.");
            foreach ($rows as $row) {
                self::assertTrue((bool) $row->is_postable, "Account {$code} must accept entries.");
                self::assertNotNull($row->agency_id, "Account {$code} is a detail account, held per agency.");
            }
        }

        // 86 takes the isolated tax and nothing else; 82 subtracts all of 66 and
        // adds that same tax back, so only 6611 leaves the résultat
        // d'exploitation and 6612 stays in it.
        $solde86 = $this->solde('86');
        $solde82 = $this->solde('82');
        self::assertSame(['6611'], $solde86['plus']);
        self::assertContains('66', $solde82['minus']);
        self::assertContains('6611', $solde82['plus']);
    }

    public function test_the_result_carries_to_the_profit_or_loss_account_by_its_sign(): void
    {
        // The chart keeps 131 Bénéfice and 132 Perte as two accounts rather than
        // one signed account, so the sign of solde 87 chooses the destination.
        self::assertSame('131', Sig::resultAccountFor(4_500_00));
        self::assertSame('132', Sig::resultAccountFor(-4_500_00));

        // Zero is not a loss. An exercise that breaks even is reported as a
        // bénéfice of nil, not as a perte, and putting it in 132 would show the
        // institution as loss-making for the year.
        self::assertSame('131', Sig::resultAccountFor(0));
    }

    public function test_every_solde_only_refers_to_soldes_already_computed(): void
    {
        // The soldes build on one another — 87 needs 85, which needs 83, which
        // needs 82. Declaration order is what makes a single pass enough, so a
        // definition that referred forward would read a solde that had not been
        // computed yet and silently treat it as zero.
        $soldeCodes = array_column(Sig::definitions(), 'code');

        $seen = [];
        foreach (Sig::definitions() as $definition) {
            $code = $definition['code'];
            foreach ($definition['from'] as $source) {
                self::assertContains($source, $seen, "Solde {$code} refers to {$source} before it is computed.");
            }
            // 87 subtracts 86, which is a solde and not a chart prefix.
            foreach ($definition['minus'] as $term) {
                if (in_array($term, $soldeCodes, true)) {
                    self::assertContains($term, $seen, "Solde {$code} subtracts {$term} before it is computed.");
                }
            }
            $seen[] = $code;
        }

        self::assertSame(['80', '81', '82', '83', '84', '85', '86', '87'], $seen);
    }

    public function test_the_courant_result_equals_the_operating_result(): void
    {
        // Confirmed 2026-08-10, and worth pinning rather than leaving as an
        // apparent oversight: 83 carries 82 forward untouched because the
        // financial part is already counted in 80. Someone finding these equal
        // would otherwise reasonably assume a term had been forgotten.
        $courant = $this->solde('83');

        self::assertSame(['82'], $courant['from']);
        self::assertSame([], $courant['plus']);
        self::assertSame([], $courant['minus']);
    }

    public function test_classes_six_and_seven_are_covered_exactly_once(): void
    {
        // The table the accounting team returned gives eight formulas but never
        // says they partition classes 6 and 7 — that is a property of the answer
        // rather than a line in it, and it is the property a typo breaks. A root
        // left out is a charge or a produit the compte de résultat silently
        // ignores; a root used twice counts the same money in two soldes. Either
        // way the report still prints, still looks orderly, and is wrong.
        $usage = [];
        foreach (Sig::definitions() as $definition) {
            foreach ([...$definition['plus'], ...$definition['minus']] as $term) {
                $usage[] = $term;
            }
        }

        $roots = [];
        foreach (range(60, 69) as $root) {
            $roots[] = (string) $root;
        }
        foreach (range(70, 79) as $root) {
            $roots[] = (string) $root;
        }

        $missing = [];
        $repeated = [];
        foreach ($roots as $root) {
            $count = count(array_filter($usage, static fn (string $term): bool => $term === $root));
            if ($count === 0) {
                $missing[] = $root;
            }
            if ($count > 1) {
                $repeated[] = $root;
            }
        }

        self::assertSame([], $missing, 'A class 6 or 7 root never reaches the compte de résultat.');
        self::assertSame([], $repeated, 'A class 6 or 7 root is counted in more than one solde.');

        // Only two terms are not roots: 6611, added back to 82 so that solde 86
        // may hold the corporate tax alone, and 86 itself, which 87 subtracts.
        $soldeCodes = array_column(Sig::definitions(), 'code');
        $extras = array_values(array_unique(array_diff($usage, $roots)));
        // SORT_STRING, or PHP compares these numerically and puts 86 before 6611.
        sort($extras, SORT_STRING);
        self::assertSame(['6611', '86'], $extras);
        self::assertContains('86', $soldeCodes);
    }

    /**
     * @return array{code: string, label: string, from: array<int, string>, plus: array<int, string>, minus: array<int, string>}
     */
    private function solde(string $code): array
    {
        foreach (Sig::definitions() as $definition) {
            if ($definition['code'] === $code) {
                return $definition;
            }
        }

        self::fail("No solde {$code} is defined.");
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
