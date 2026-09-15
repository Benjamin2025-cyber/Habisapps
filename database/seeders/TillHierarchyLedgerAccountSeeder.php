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
 * The four-level caisse hierarchy of CORRECTIONS HABISLOAN 04/09/2026,
 * « INTERFACE DES CAISSES » — « En amont, cela nécessite la création des
 * comptes comptables correspondants à chaque caisse ».
 *
 *   Niveau 1  5710       caisse principale, à la direction générale
 *   Niveau 2  57101      caisse générale d'agence
 *   Niveau 3  571011     caisse de guichet 1
 *             571012     caisse de guichet 2
 *   Niveau 4  571011X    sous-caisses par module, X = 2…9
 *             571012X
 *
 * These are **not** PCEMF codes. The regulator's nomenclature stops at 5710
 * « Caisse FCFA »; everything below it is Habibi Finance's own analytical
 * breakdown of that one account. So they are seeded here rather than added to
 * database/data/pcemf-chart.json, which is a signed extract of the official
 * plan confirmed by the accounting team on 2026-08-09 and should keep saying
 * exactly what the manual says.
 *
 * Every level is postable, because every level holds real cash: a transfer
 * from the caisse générale to a guichet is an entry between two accounts that
 * both carry a balance. The consolidated position of a caisse is its own
 * balance plus its children's — which is what LedgerAccountHierarchy already
 * returns, its subtree being inclusive of the root.
 *
 * Idempotent: an account already present for an agency is left untouched, so
 * re-running after adding an agency backfills only that agency.
 */
final class TillHierarchyLedgerAccountSeeder extends Seeder
{
    /**
     * The level-4 modules, keyed by the digit they take in 571011X / 571012X.
     * Digit 1 is deliberately unused: the accounting team's list starts at 2.
     *
     * @var array<int, string>
     */
    private const array MODULES = [
        2 => 'Caisse de fonctionnement',
        3 => 'Caisse des opérations courantes',
        4 => 'Caisse des opérations Campost',
        5 => 'Caisse des opérations de bancassurance',
        6 => 'Caisse des opérations de transferts nationaux',
        7 => 'Caisse des opérations de transferts internationaux',
        8 => 'Caisse des opérations Orange Money',
        9 => 'Caisse des opérations MTN Mobile Money',
    ];

    /** The guichets each agency opens with; level-3 codes are 57101 + this. */
    private const array GUICHETS = [1, 2];

    private const string ROOT_CODE = '5710';

    public function run(): void
    {
        $agencies = Agency::query()->get(['id', 'code'])->all();
        if ($agencies === []) {
            $this->command?->warn('No agency exists: nothing to seed.');

            return;
        }

        $created = 0;
        foreach ($agencies as $agency) {
            $created += $this->seedAgency($agency->id);
        }

        $this->command?->info(sprintf(
            'Till hierarchy: %d caisse account%s created across %d agenc%s.',
            $created,
            $created === 1 ? '' : 's',
            count($agencies),
            count($agencies) === 1 ? 'y' : 'ies',
        ));
    }

    private function seedAgency(int $agencyId): int
    {
        // Keyed by code. PHP turns a numeric string key into an int, and every
        // account code is numeric, so the key type is array-key rather than
        // string — reads and writes cast identically, so lookups still match.
        /** @var array<array-key, int> $existing */
        $existing = [];
        foreach (DB::table('ledger_accounts')->where('agency_id', $agencyId)->get(['id', 'code']) as $row) {
            $existing[(string) $row->code] = (int) $row->id;
        }

        if (! isset($existing[self::ROOT_CODE])) {
            throw new RuntimeException(sprintf(
                'Agency %d has no %s account: run the PCEMF chart seeder first.',
                $agencyId,
                self::ROOT_CODE,
            ));
        }

        $created = 0;
        // Shortest code first, so each account's parent already exists when it
        // is inserted and $existing can resolve it by prefix.
        foreach ($this->plan() as [$code, $label]) {
            if (isset($existing[$code])) {
                continue;
            }

            $existing[$code] = DB::table('ledger_accounts')->insertGetId([
                'public_id' => (string) Str::ulid(),
                'agency_id' => $agencyId,
                'parent_account_id' => $this->parentIdOf($code, $existing),
                'code' => $code,
                'name' => $label,
                'account_class' => LedgerAccount::ACCOUNT_CLASS_TRESORERIE_INTERBANCAIRE,
                'is_postable' => true,
                'normal_balance_side' => LedgerAccount::NORMAL_BALANCE_DEBIT,
                'status' => LedgerAccount::STATUS_ACTIVE,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $created++;
        }

        return $created;
    }

    /**
     * Codes in insertion order — level 2, then each guichet and its modules.
     * A list of pairs rather than a code-keyed map, so the codes stay strings.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private function plan(): array
    {
        $plan = [[self::ROOT_CODE.'1', "Caisse générale d'agence"]];

        foreach (self::GUICHETS as $guichet) {
            $guichetCode = self::ROOT_CODE.'1'.$guichet;
            $plan[] = [$guichetCode, sprintf('Caisse de guichet %d', $guichet)];

            foreach (self::MODULES as $digit => $label) {
                $plan[] = [$guichetCode.$digit, sprintf('%s — guichet %d', $label, $guichet)];
            }
        }

        return $plan;
    }

    /**
     * The nearest ancestor already seeded — the code's longest proper prefix.
     *
     * @param  array<array-key, int>  $existing
     */
    private function parentIdOf(string $code, array $existing): ?int
    {
        for ($length = strlen($code) - 1; $length >= 2; $length--) {
            $candidate = substr($code, 0, $length);
            if (isset($existing[$candidate])) {
                return $existing[$candidate];
            }
        }

        return null;
    }
}
