<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Agency;
use App\Models\LedgerAccount;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Loads the institution's chart of accounts from `database/data/pcemf-chart.json`,
 * extracted from "Plan des Comptes HF 2026 — extrait du manuel de procédures
 * comptables".
 *
 * Shape, which follows that document rather than the sketch the first design was
 * built from (see docs/domain/consolidated-chart-of-accounts.md §2b):
 *
 *  - The chart is **one nomenclature**. It contains no agency-specific code;
 *    agencies appear only as counterparties, through the liaison accounts 451,
 *    452 and 485. So each agency keeps the *same* numbers.
 *  - **Grouping accounts** (`postable: false`) are created once, at institution
 *    level (`agency_id` null). They are the accounts the manual calls "comptes
 *    parents": non-imputable, their balance the sum of their children.
 *  - **Detail accounts** are created once per agency, each parented to its
 *    institution-level parent. The two partial unique indexes make an
 *    institution `571` and an agency `571` coexist.
 *
 * Idempotent: an account already present in a namespace is left untouched, so
 * re-running after adding an agency backfills only that agency.
 */
final class PcemfChartSeeder extends Seeder
{
    public function run(): void
    {
        $chart = $this->chart();

        /** @var array<string, array{label: string, side: string|null, confirmed: bool}> $rootSides */
        $rootSides = $chart['root_sides'];
        /** @var array<int, array{code: string, label: string, postable: bool}> $accounts */
        $accounts = $chart['accounts'];

        $byCode = [];
        foreach ($accounts as $account) {
            $byCode[$account['code']] = $account;
        }

        $agencies = Agency::query()->get(['id', 'code'])->all();
        if ($agencies === []) {
            $this->command->warn('No agency exists: only the institution-level grouping accounts will be created.');
        }

        // Parents first: a detail account references its institution parent, and
        // the parent of a parent is resolved the same way.
        $institutionIds = $this->seedInstitutionGroupingAccounts($accounts, $byCode, $rootSides);
        $created = $this->seedAgencyDetailAccounts($accounts, $byCode, $rootSides, $agencies, $institutionIds);

        $provisional = count(array_filter($rootSides, static fn (array $r): bool => ! $r['confirmed']));
        $this->command->info(sprintf(
            'PCEMF chart: %d institution grouping accounts, %d agency detail accounts across %d agenc%s.',
            count($institutionIds),
            $created,
            count($agencies),
            count($agencies) === 1 ? 'y' : 'ies',
        ));
        if ($provisional > 0) {
            $this->command->warn(sprintf(
                '%d of %d root accounts carry a PROVISIONAL normal balance side, pending confirmation by the '
                .'accounting team. Correct them in database/data/pcemf-chart.json before any entry is posted; '
                .'the API keeps the side editable only while an account has no movements.',
                $provisional,
                count($rootSides),
            ));
        }
    }

    /**
     * @param  array<int, array{code: string, label: string, postable: bool}>  $accounts
     * @param  array<string, array{code: string, label: string, postable: bool}>  $byCode
     * @param  array<string, array{label: string, side: string|null, confirmed: bool}>  $rootSides
     * @return array<string, int> ledger_accounts.id keyed by code
     */
    private function seedInstitutionGroupingAccounts(array $accounts, array $byCode, array $rootSides): array
    {
        /** @var array<string, int> $ids */
        $ids = [];
        foreach (DB::table('ledger_accounts')->whereNull('agency_id')->get(['id', 'code']) as $row) {
            $ids[(string) $row->code] = (int) $row->id;
        }

        // Shortest code first, so a parent is always inserted before its child.
        $grouping = array_values(array_filter($accounts, static fn (array $a): bool => ! $a['postable']));
        usort($grouping, static fn (array $a, array $b): int => strlen($a['code']) <=> strlen($b['code']));

        foreach ($grouping as $account) {
            if (isset($ids[$account['code']])) {
                continue;
            }

            $parentCode = $this->parentCodeOf($account['code'], $byCode);
            $ids[$account['code']] = DB::table('ledger_accounts')->insertGetId([
                'public_id' => (string) Str::ulid(),
                'agency_id' => null,
                'parent_account_id' => $parentCode === null ? null : ($ids[$parentCode] ?? null),
                'code' => $account['code'],
                'name' => $account['label'],
                'account_class' => $this->classOf($account['code']),
                'is_postable' => false,
                // Inherited like a leaf's, because the side is exactly what
                // orients the consolidated total this account exists to carry:
                // forcing debit on `35 Comptes de dépôts` would report every
                // agency's deposits as a negative balance.
                'normal_balance_side' => $this->sideOf($account['code'], $rootSides),
                'status' => LedgerAccount::STATUS_ACTIVE,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $ids;
    }

    /**
     * @param  array<int, array{code: string, label: string, postable: bool}>  $accounts
     * @param  array<string, array{code: string, label: string, postable: bool}>  $byCode
     * @param  array<string, array{label: string, side: string|null, confirmed: bool}>  $rootSides
     * @param  array<int, Agency>  $agencies
     * @param  array<string, int>  $institutionIds
     */
    private function seedAgencyDetailAccounts(
        array $accounts,
        array $byCode,
        array $rootSides,
        array $agencies,
        array $institutionIds,
    ): int {
        $detail = array_values(array_filter($accounts, static fn (array $a): bool => $a['postable']));
        $created = 0;

        foreach ($agencies as $agency) {
            $taken = [];
            foreach (DB::table('ledger_accounts')->where('agency_id', $agency->id)->get(['code']) as $row) {
                $taken[(string) $row->code] = true;
            }

            $rows = [];
            foreach ($detail as $account) {
                if (isset($taken[$account['code']])) {
                    continue;
                }

                $parentCode = $this->parentCodeOf($account['code'], $byCode);
                $rows[] = [
                    'public_id' => (string) Str::ulid(),
                    'agency_id' => $agency->id,
                    // Detail accounts hang off the institution tree, which is what
                    // makes one grouping account total every agency at once.
                    'parent_account_id' => $parentCode === null ? null : ($institutionIds[$parentCode] ?? null),
                    'code' => $account['code'],
                    'name' => $account['label'],
                    'account_class' => $this->classOf($account['code']),
                    'is_postable' => true,
                    'normal_balance_side' => $this->sideOf($account['code'], $rootSides),
                    'status' => LedgerAccount::STATUS_ACTIVE,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('ledger_accounts')->insert($chunk);
            }
            $created += count($rows);
        }

        return $created;
    }

    /**
     * The nearest ancestor present in the chart — its longest proper prefix.
     *
     * @param  array<string, array{code: string, label: string, postable: bool}>  $byCode
     */
    private function parentCodeOf(string $code, array $byCode): ?string
    {
        for ($length = strlen($code) - 1; $length >= 2; $length--) {
            $candidate = substr($code, 0, $length);
            if (isset($byCode[$candidate])) {
                return $candidate;
            }
        }

        return null;
    }

    private function classOf(string $code): string
    {
        $class = LedgerAccount::classImpliedByCode($code);
        if ($class === null) {
            throw new RuntimeException("Chart code {$code} does not start with a PCEMF class digit.");
        }

        return $class;
    }

    /**
     * The normal side is not in the source document, so it is carried by the
     * two-digit roots and inherited: a code's side is its root's side.
     *
     * Null is a value, not a gap — the accounting team marked eight roots as
     * bivalent (45, 47, 52, 56, 94, 97, 98, 99), meaning no side is imposed and
     * the balance reports whichever side it actually lands on.
     *
     * @param  array<string, array{label: string, side: string|null, confirmed: bool}>  $rootSides
     */
    private function sideOf(string $code, array $rootSides): ?string
    {
        $root = substr($code, 0, 2);
        if (! array_key_exists($root, $rootSides)) {
            return LedgerAccount::NORMAL_BALANCE_DEBIT;
        }

        return $rootSides[$root]['side'];
    }

    /**
     * @return array{root_sides: array<string, array{label: string, side: string|null, confirmed: bool}>, accounts: array<int, array{code: string, label: string, postable: bool}>}
     */
    private function chart(): array
    {
        $path = database_path('data/pcemf-chart.json');
        $raw = is_file($path) ? file_get_contents($path) : false;
        if ($raw === false) {
            throw new RuntimeException("Chart data file is missing: {$path}");
        }

        /** @var array{root_sides: array<string, array{label: string, side: string|null, confirmed: bool}>, accounts: array<int, array{code: string, label: string, postable: bool}>} $chart */
        $chart = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        return $chart;
    }
}
