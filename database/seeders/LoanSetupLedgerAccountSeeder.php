<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Agency;
use App\Models\LedgerAccount;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The accounts a loan mise en place needs, from CORRECTIONS HABISLOAN
 * 04/09/2026, « INTERFACE DE MISE EN PLACE — MODULE CREDIT ».
 *
 * Two groups:
 *
 * 1. The medium- and long-term credit families. The accounting team names
 *    3282 for court terme, 3181 for moyen terme and 3081 for long terme, but
 *    database/data/pcemf-chart.json — a signed extract of the institution's
 *    own plan — carries class 3 only from 32 upwards. 30 and 31 are absent
 *    from the extract, not from the nomenclature, so their parents are created
 *    here by symmetry with 32 / 328 / 3282 rather than by editing the extract.
 *
 * 2. The two accounts the same document asks to create for the fees taken at
 *    mise en place: 4591 for the dossier fee and 4611 for the death solidarity
 *    fund. 3742 « Dépôts de garantie clients », which holds the guarantee
 *    deposit, is already in the chart.
 *
 * **4951 is created as 4592.** The document asks for « Virements pour mise en
 * place de prêts à Court terme » under the number 4951, but 49 is PROVISIONS
 * POUR DEPRECIATION DES COMPTES D'AUTRES TIERS and 495 is « Provisions pour
 * dépréciation des comptes de liaison ». An account is defined by where its
 * number puts it, and putting loan set-up transfers there would add every
 * disbursement in flight to the provisions line of the balance sheet —
 * overstating provisions and understating the result by the same amount.
 *
 * What the label describes is an internal transfer account, and the chart
 * already has that family: 459 « Mouvements de fonds et autres virements
 * internes », where the 4591 above also sits. 4592 is the next free number in
 * it. Reading 4951 as a transposition of 4592 makes the label, the family and
 * the sibling account all agree, where reading it literally makes none of
 * them agree. Carried as 4592.
 */
final class LoanSetupLedgerAccountSeeder extends Seeder
{
    /**
     * Code, label, postable. Parents first, so each is inserted before its
     * children and resolves by prefix.
     *
     * @var array<int, array{0: string, 1: string, 2: bool}>
     */
    private const array ACCOUNTS = [
        // Long terme — mirrors 32 / 328 / 3282.
        ['30', 'CREDITS A LONG TERME', false],
        ['308', 'Autres crédits à LT', false],
        ['3081', 'Autres crédits à LT aux clients', true],

        // Moyen terme.
        ['31', 'CREDITS A MOYEN TERME', false],
        ['318', 'Autres crédits à MT', false],
        ['3181', 'Autres crédits à MT aux clients', true],

        // Fees collected at mise en place.
        ['4591', 'Frais de dossier et fonds de solidarité décès', true],
        ['4592', 'Virements pour mise en place de prêts à court terme', true],
        ['4611', 'Fonds de Solidarité Décès sur prêts à court terme', true],
    ];

    /**
     * Class 3 credits are assets and 45/46 are third-party accounts; the
     * chart's own roots already fix the side for 45 and 46, and the two new
     * roots take debit like the 32 they mirror.
     */
    private const array SIDES = [
        '30' => LedgerAccount::NORMAL_BALANCE_DEBIT,
        '31' => LedgerAccount::NORMAL_BALANCE_DEBIT,
    ];

    public function run(): void
    {
        $agencies = Agency::query()->get(['id'])->all();

        $institutionIds = $this->seedNamespace(null);
        $created = count(array_filter($institutionIds, static fn (?int $id): bool => $id !== null));

        foreach ($agencies as $agency) {
            $created += count(array_filter($this->seedNamespace($agency->id), static fn (?int $id): bool => $id !== null));
        }

        $this->command?->info(sprintf(
            'Loan set-up chart: %d account%s created across %d agenc%s.',
            $created,
            $created === 1 ? '' : 's',
            count($agencies),
            count($agencies) === 1 ? 'y' : 'ies',
        ));
    }

    /**
     * Grouping accounts live at institution level and detail accounts per
     * agency, exactly as PcemfChartSeeder arranges the rest of the chart. A
     * detail account parents onto the institution tree, which is what lets one
     * grouping account total every agency at once.
     *
     * @return array<int, int|null> newly created ids, null where already present
     */
    private function seedNamespace(?int $agencyId): array
    {
        /** @var array<array-key, int> $existing */
        $existing = [];
        foreach (
            DB::table('ledger_accounts')
                ->when($agencyId === null,
                    fn ($q) => $q->whereNull('agency_id'),
                    fn ($q) => $q->where('agency_id', $agencyId))
                ->get(['id', 'code']) as $row
        ) {
            $existing[(string) $row->code] = (int) $row->id;
        }

        /** @var array<array-key, int> $institution */
        $institution = [];
        if ($agencyId !== null) {
            foreach (DB::table('ledger_accounts')->whereNull('agency_id')->get(['id', 'code']) as $row) {
                $institution[(string) $row->code] = (int) $row->id;
            }
        }

        $created = [];
        foreach (self::ACCOUNTS as [$code, $label, $postable]) {
            // Grouping accounts belong to the institution namespace only, detail
            // accounts to the agency ones.
            if ($postable === ($agencyId === null)) {
                continue;
            }

            if (isset($existing[$code])) {
                $created[] = null;

                continue;
            }

            // A detail account normally hangs off a grouping account in the
            // institution tree, but 4591 and 4611 sit under 459 and 461, which
            // are themselves postable agency accounts. Search both, with the
            // agency namespace winning, or 4591 skips 459 and lands on 45.
            // array_replace, not spread: every code is a numeric string, so PHP
            // holds them as int keys and `[...$a, ...$b]` would renumber them
            // 0,1,2… and silently destroy the code-to-id mapping.
            $parentPool = $agencyId === null ? $existing : array_replace($institution, $existing);
            $id = DB::table('ledger_accounts')->insertGetId([
                'public_id' => (string) Str::ulid(),
                'agency_id' => $agencyId,
                'parent_account_id' => $this->parentIdOf($code, $parentPool),
                'code' => $code,
                'name' => $label,
                'account_class' => LedgerAccount::classImpliedByCode($code),
                'is_postable' => $postable,
                'normal_balance_side' => $this->sideOf($code),
                'status' => LedgerAccount::STATUS_ACTIVE,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $existing[$code] = $id;
            $created[] = $id;
        }

        return $created;
    }

    private function sideOf(string $code): string
    {
        $root = substr($code, 0, 2);

        if (isset(self::SIDES[$root])) {
            return self::SIDES[$root];
        }

        // 45 and 46 already exist in the chart; inherit whatever the accounting
        // team confirmed for them rather than restating it here.
        $inherited = DB::table('ledger_accounts')
            ->whereNull('agency_id')
            ->where('code', $root)
            ->value('normal_balance_side');

        return is_string($inherited) ? $inherited : LedgerAccount::NORMAL_BALANCE_DEBIT;
    }

    /**
     * @param  array<array-key, int>  $pool
     */
    private function parentIdOf(string $code, array $pool): ?int
    {
        for ($length = strlen($code) - 1; $length >= 2; $length--) {
            $candidate = substr($code, 0, $length);
            if (isset($pool[$candidate])) {
                return $pool[$candidate];
            }
        }

        return null;
    }
}
