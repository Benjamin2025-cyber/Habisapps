<?php

declare(strict_types=1);

namespace App\Application\Loans;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Delinquency projections for loan list/dashboard consumers.
 *
 * PAR list-filter bands are non-cumulative (each bucket is a half-open range):
 *   - par_bucket=30 → days in [30, 60)
 *   - par_bucket=60 → days in [60, 90)
 *   - par_bucket=90 → days >= 90
 *
 * Stats cumulative PAR counts use the standard definitions (par30 >= 30 days, etc.).
 */
final class LoanDelinquencyProjection
{
    /**
     * @param  list<int>  $loanIds
     * @return array<int, array{days_in_arrears:int, overdue_amount_minor:int, par_bucket:int|null}>
     */
    public function forLoanIds(array $loanIds, string $asOfDate): array
    {
        if ($loanIds === []) {
            return [];
        }

        $rows = DB::table('loan_schedule_lines as lsl')
            ->join('loan_schedule_snapshots as snap', 'snap.id', '=', 'lsl.loan_schedule_snapshot_id')
            ->leftJoin('loan_repayment_allocations as alloc', function ($join): void {
                $join->on('alloc.loan_schedule_line_id', '=', 'lsl.id')
                    ->whereIn('alloc.component', ['principal', 'interest', 'penalty']);
            })
            ->leftJoin('loan_repayments as r', function ($join): void {
                $join->on('r.id', '=', 'alloc.loan_repayment_id')
                    ->where('r.status', '=', 'posted');
            })
            ->whereIn('snap.loan_id', $loanIds)
            ->where('snap.status', 'active')
            ->whereDate('lsl.due_date', '<', $asOfDate)
            ->groupBy('snap.loan_id', 'lsl.id', 'lsl.due_date', 'lsl.principal_minor', 'lsl.interest_minor', 'lsl.penalty_minor')
            ->havingRaw('(lsl.principal_minor + lsl.interest_minor + lsl.penalty_minor) - COALESCE(SUM(CASE WHEN r.id IS NOT NULL THEN alloc.amount_minor ELSE 0 END), 0) > 0')
            ->select([
                'snap.loan_id',
                'lsl.due_date',
                DB::raw('(lsl.principal_minor + lsl.interest_minor + lsl.penalty_minor) - COALESCE(SUM(CASE WHEN r.id IS NOT NULL THEN alloc.amount_minor ELSE 0 END), 0) AS unpaid_minor'),
            ])
            ->get();

        $byLoan = [];
        foreach ($rows as $row) {
            if (! is_numeric($row->loan_id ?? null)) {
                continue;
            }

            $loanId = (int) $row->loan_id;
            $dueDate = is_string($row->due_date) ? $row->due_date : null;
            $unpaid = is_numeric($row->unpaid_minor ?? null) ? (int) $row->unpaid_minor : 0;
            if ($dueDate === null || $unpaid <= 0) {
                continue;
            }

            if (! isset($byLoan[$loanId])) {
                $byLoan[$loanId] = [
                    'oldest_due_date' => $dueDate,
                    'overdue_amount_minor' => 0,
                ];
            }

            if ($dueDate < $byLoan[$loanId]['oldest_due_date']) {
                $byLoan[$loanId]['oldest_due_date'] = $dueDate;
            }
            $byLoan[$loanId]['overdue_amount_minor'] += $unpaid;
        }

        $projections = [];
        foreach ($byLoan as $loanId => $data) {
            $days = $this->daysBetween($data['oldest_due_date'], $asOfDate);
            $projections[$loanId] = [
                'days_in_arrears' => $days,
                'overdue_amount_minor' => $data['overdue_amount_minor'],
                'par_bucket' => $this->parBucketLabel($days),
            ];
        }

        return $projections;
    }

    public function matchesNonCumulativeParBucket(int $daysInArrears, int $bucket): bool
    {
        return match ($bucket) {
            30 => $daysInArrears >= 30 && $daysInArrears < 60,
            60 => $daysInArrears >= 60 && $daysInArrears < 90,
            90 => $daysInArrears >= 90,
            default => false,
        };
    }

    public function matchesCumulativeParBucket(int $daysInArrears, int $bucket): bool
    {
        return $daysInArrears >= $bucket;
    }

    private function daysBetween(string $fromDate, string $asOfDate): int
    {
        $from = CarbonImmutable::parse($fromDate)->startOfDay();
        $asOf = CarbonImmutable::parse($asOfDate)->startOfDay();

        return max(0, (int) $from->diffInDays($asOf, false));
    }

    private function parBucketLabel(int $daysInArrears): ?int
    {
        if ($daysInArrears >= 90) {
            return 90;
        }

        if ($daysInArrears >= 60) {
            return 60;
        }

        if ($daysInArrears >= 30) {
            return 30;
        }

        return null;
    }
}
