<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Repairs denominations whose `value_minor` holds the face value in francs
 * instead of minor units.
 *
 * Reported from the cash-session billetage: 100 notes of 10 000 totalled
 * 10 000 FCFA instead of 1 000 000, and every other line was short by the same
 * factor of 100 — the signature of `count × face` being formatted as minor
 * units. The counter and the API are correct; the rows were.
 *
 * `DenominationSeeder` stores `face * 100` and is right, but it is skipped on
 * deployments (it is in DatabaseSeeder, which the deploy workflow does not
 * run), so the pieces were keyed in by hand — and `value_minor` was validated
 * only as `integer|min:1`, which cannot tell a 10 000 F note from a 100 F coin.
 *
 * Repair keyed on self-describing data: the seeded code format is
 * `XAF-<face>-B|C`, so a row whose code declares a face value while its
 * `value_minor` says something else contradicts itself. Only those are touched,
 * and only when the stored value is exactly the un-scaled face — a row an
 * operator deliberately set to something else is left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('denominations')->orderBy('id')->get(['id', 'code', 'value_minor']) as $row) {
            $face = $this->faceFromCode(is_string($row->code) ? $row->code : '');
            if ($face === null) {
                continue;
            }

            // Only the exact off-by-scale case: the value equals the face in
            // francs where it should be the face in minor units.
            if ((int) $row->value_minor !== $face) {
                continue;
            }

            DB::table('denominations')->where('id', $row->id)->update([
                'value_minor' => $face * 100,
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Irreversible: putting the face value back would restore the defect.
     */
    public function down(): void {}

    private function faceFromCode(string $code): ?int
    {
        if (preg_match('/^XAF-(\d+)-[BC]$/', $code, $matches) !== 1) {
            return null;
        }

        $face = (int) $matches[1];

        return $face > 0 ? $face : null;
    }
};
