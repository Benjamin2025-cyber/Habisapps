<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Denomination;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The XAF notes and coins issued by the BEAC.
 *
 * Reference data, not an institution's choice: the CEMAC franc's denominations
 * are fixed by the central bank, so every EMF counts the same pieces. Without
 * them a till marked `requires_denominations` cannot be opened or reconciled at
 * all — TellerSessionWorkflow builds the count lines from this table.
 *
 * `value_minor` is the face value in **minor units at the account scale**
 * (`money.default_scale`, 2), because TellerSessionWorkflow compares
 * `value_minor * count` against the declared opening float, which is an ordinary
 * money amount. A 1 000 XAF note is therefore 100 000, not 1 000.
 * `money.physical_cash_scale` (0) is a separate rule — it makes
 * PhysicalCashAmount reject cash that is not a whole number of francs — and does
 * not change the unit stored here.
 *
 * Idempotent by code, and every field stays editable afterwards
 * (UpdateDenominationRequest accepts all of them), so an institution that does
 * not handle a piece can simply deactivate it.
 */
final class DenominationSeeder extends Seeder
{
    private const string CURRENCY = 'XAF';

    /**
     * Face value in francs => piece type, as issued by the BEAC for the CEMAC
     * zone (Cameroon included).
     *
     * Notes are the "gamme 2020": 500 to 10 000. Coins are the "Type 2024"
     * series put into circulation on 2 April 2025: 1 to 500, the 200 being the
     * newest. There is no 10-franc note — the 10 is a coin.
     *
     * One row per face value, because `denominations` is UNIQUE (currency,
     * value_minor): the schema identifies a denomination by what it is worth,
     * not by its physical form, so `type` is a label rather than a key. The 500
     * circulates as both a note and a coin and is listed once, as a note; a
     * teller counting 500s enters them on that single line either way.
     *
     * @var array<int, array{0: int, 1: string}>
     */
    private const array PIECES = [
        [10000, Denomination::TYPE_BANKNOTE],
        [5000, Denomination::TYPE_BANKNOTE],
        [2000, Denomination::TYPE_BANKNOTE],
        [1000, Denomination::TYPE_BANKNOTE],
        [500, Denomination::TYPE_BANKNOTE],
        [200, Denomination::TYPE_COIN],
        [100, Denomination::TYPE_COIN],
        [50, Denomination::TYPE_COIN],
        [25, Denomination::TYPE_COIN],
        [10, Denomination::TYPE_COIN],
        [5, Denomination::TYPE_COIN],
        [2, Denomination::TYPE_COIN],
        [1, Denomination::TYPE_COIN],
    ];

    public function run(): void
    {
        $scaleFactor = $this->minorUnitFactor();

        $existing = [];
        foreach (DB::table('denominations')->get(['code']) as $row) {
            $existing[(string) $row->code] = true;
        }

        $rows = [];
        foreach (self::PIECES as [$face, $type]) {
            $code = sprintf('%s-%d-%s', self::CURRENCY, $face, $type === Denomination::TYPE_COIN ? 'C' : 'B');
            if (isset($existing[$code])) {
                continue;
            }

            $rows[] = [
                'public_id' => (string) Str::ulid(),
                'code' => $code,
                'label' => sprintf('%s %s %s', number_format($face, 0, ',', ' '), self::CURRENCY, $type === Denomination::TYPE_COIN ? 'pièce' : 'billet'),
                'value_minor' => $face * $scaleFactor,
                'currency' => self::CURRENCY,
                'type' => $type,
                'status' => Denomination::STATUS_ACTIVE,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if ($rows !== []) {
            DB::table('denominations')->insert($rows);
        }

        $this->command->info(sprintf(
            'Denominations: %d created, %d already present.',
            count($rows),
            count(self::PIECES) - count($rows),
        ));
    }

    /**
     * Minor units per franc, from the configured account scale — so a project
     * running at a different scale still seeds the right magnitude.
     */
    private function minorUnitFactor(): int
    {
        $scale = config('money.default_scale', 2);
        $scale = is_int($scale) ? max(0, $scale) : 2;

        return 10 ** $scale;
    }
}
