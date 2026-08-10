<?php

declare(strict_types=1);

namespace App\Support\Accounting;

use App\Models\InstitutionProfile;
use App\Models\JournalEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A settled exercise takes no more entries.
 *
 * Once the clôture is posted the exercise's figures have been reported — filed
 * with COBAC and put to the assemblée générale — so the ledger for that year must
 * stop moving. Otherwise the accounts on file and the accounts in the system drift
 * apart with nothing to show it, and any amount added afterwards is swept into the
 * following year's result by the next clôture, misstating that year too.
 *
 * Something found after the fact is not posted back. It goes into the current
 * exercise, which is what classes 67 and 77 are for — « pertes exceptionnelles et
 * sur exercices antérieurs » and « profits exceptionnels et sur exercices
 * antérieurs ». The chart already carries them.
 */
final class ClosedExerciseGuard
{
    /**
     * The fiscal year covering $businessDate when that exercise is settled for
     * $agencyId, or null when it is still open.
     *
     * Keyed on the clôture having been *posted*: one awaiting review has moved
     * nothing, and the exercise is still live until it does. That is also what
     * lets the clôture post its own entry, dated the last day of the exercise it
     * closes, without being refused by this guard.
     */
    public function settledExerciseFor(int $agencyId, string $businessDate): ?int
    {
        $date = Carbon::parse($businessDate);
        $fiscalYear = $date->month >= $this->fiscalYearStartMonth() ? $date->year : $date->year - 1;

        $settled = DB::table('exercise_closings')
            ->join('journal_entries', 'journal_entries.id', '=', 'exercise_closings.journal_entry_id')
            ->where('exercise_closings.agency_id', $agencyId)
            ->where('exercise_closings.fiscal_year', $fiscalYear)
            ->where('journal_entries.status', JournalEntry::STATUS_POSTED)
            ->exists();

        return $settled ? $fiscalYear : null;
    }

    private function fiscalYearStartMonth(): int
    {
        $startMonth = InstitutionProfile::query()->first()?->fiscal_year_start_month;

        return $startMonth === null || $startMonth < 1 || $startMonth > 12 ? 1 : $startMonth;
    }
}
