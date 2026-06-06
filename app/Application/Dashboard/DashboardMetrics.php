<?php

declare(strict_types=1);

namespace App\Application\Dashboard;

use App\Models\Agency;
use App\Models\Loan;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Shared, query-only dashboard metric helpers.
 *
 * Centralizes the credit-portfolio calculations (reportable loans, outstanding,
 * PAR, collections, delinquency) and the period-window resolution so the
 * operational dashboard, the timeseries endpoint, the agency-performance
 * endpoint, and the loan in-arrears filter all read from one source instead of
 * duplicating the policy.
 */
final class DashboardMetrics
{
    /**
     * Loan statuses that count as reportable (live) portfolio.
     *
     * @var list<string>
     */
    public const array REPORTABLE_LOAN_STATUSES = [
        Loan::STATUS_DISBURSED,
        Loan::STATUS_ACTIVE,
        Loan::STATUS_RESCHEDULED,
    ];

    public function numericValue(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    public function loanProductIdByPublicId(mixed $publicId): ?int
    {
        if (! is_string($publicId) || $publicId === '') {
            return null;
        }

        $id = DB::table('loan_products')->where('public_id', $publicId)->value('id');

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * @return list<int>
     */
    public function reportableLoanIds(?Agency $agency, string $currency, ?string $loanStatus, ?int $loanProductId): array
    {
        $query = DB::table('loans')
            ->where('currency', $currency)
            ->whereIn('status', self::REPORTABLE_LOAN_STATUSES);
        if ($agency instanceof Agency) {
            $query->where('agency_id', $agency->id);
        }
        if ($loanStatus !== null) {
            $query->where('status', $loanStatus);
        }
        if ($loanProductId !== null) {
            $query->where('loan_product_id', $loanProductId);
        }

        $values = $query->pluck('id')->all();
        $ids = [];
        foreach ($values as $value) {
            if (is_numeric($value)) {
                $ids[] = (int) $value;
            }
        }

        return array_values(array_unique($ids));
    }

    public function reportableLoanCount(?Agency $agency, string $currency, ?string $loanStatus, ?int $loanProductId): int
    {
        return count($this->reportableLoanIds($agency, $currency, $loanStatus, $loanProductId));
    }

    public function portfolioOutstanding(?Agency $agency, string $currency, string $asOfDate, ?string $loanStatus, ?int $loanProductId): int
    {
        $loanIds = $this->reportableLoanIds($agency, $currency, $loanStatus, $loanProductId);
        if ($loanIds === []) {
            return 0;
        }

        return $this->outstandingForLoanIds($loanIds, $asOfDate);
    }

    /**
     * Outstanding (due minus paid) for the given loans.
     *
     * `$paidAsOfDate` bounds the posted-payment side to `paid_on <= date`, which
     * is required for a true historical (per-bucket) balance. Leave it null for
     * the current point-in-time snapshot (operational/executive dashboards),
     * where all posted payments count.
     *
     * @param  list<int>  $loanIds
     */
    public function outstandingForLoanIds(array $loanIds, string $asOfDate, ?string $paidAsOfDate = null): int
    {
        if ($loanIds === []) {
            return 0;
        }

        $due = $this->numericValue(DB::table('loan_schedule_lines')
            ->join('loan_schedule_snapshots', 'loan_schedule_snapshots.id', '=', 'loan_schedule_lines.loan_schedule_snapshot_id')
            ->whereIn('loan_schedule_snapshots.loan_id', $loanIds)
            ->where('loan_schedule_snapshots.status', 'active')
            ->whereDate('loan_schedule_lines.due_date', '<=', $asOfDate)
            ->selectRaw('COALESCE(SUM(loan_schedule_lines.principal_minor + loan_schedule_lines.interest_minor + loan_schedule_lines.penalty_minor), 0) AS total_minor')
            ->value('total_minor'));

        $paidQuery = DB::table('loan_repayment_allocations')
            ->join('loan_repayments', 'loan_repayments.id', '=', 'loan_repayment_allocations.loan_repayment_id')
            ->whereIn('loan_repayments.loan_id', $loanIds)
            ->where('loan_repayments.status', 'posted')
            ->whereIn('loan_repayment_allocations.component', ['principal', 'interest', 'penalty']);
        if ($paidAsOfDate !== null) {
            $paidQuery->whereDate('loan_repayments.paid_on', '<=', $paidAsOfDate);
        }
        $paid = $this->numericValue($paidQuery->sum('loan_repayment_allocations.amount_minor'));

        return max(0, $due - $paid);
    }

    /**
     * Historical portfolio balance as of a date: due lines on/before the date
     * minus posted payments on/before the date. Used for timeseries buckets so
     * later payments do not retroactively shrink earlier buckets.
     */
    public function portfolioBalanceAsOf(?Agency $agency, string $currency, string $asOfDate, ?string $loanStatus, ?int $loanProductId): int
    {
        $loanIds = $this->reportableLoanIds($agency, $currency, $loanStatus, $loanProductId);

        return $this->outstandingForLoanIds($loanIds, $asOfDate, $asOfDate);
    }

    /**
     * @return array{
     *     par30_outstanding_at_risk_minor:int,
     *     par60_outstanding_at_risk_minor:int,
     *     par90_outstanding_at_risk_minor:int,
     * }
     */
    public function parBuckets(?Agency $agency, string $currency, string $asOfDate, ?string $loanStatus, ?int $loanProductId): array
    {
        return [
            'par30_outstanding_at_risk_minor' => $this->portfolioAtRiskOutstanding($agency, $currency, $asOfDate, 30, $loanStatus, $loanProductId),
            'par60_outstanding_at_risk_minor' => $this->portfolioAtRiskOutstanding($agency, $currency, $asOfDate, 60, $loanStatus, $loanProductId),
            'par90_outstanding_at_risk_minor' => $this->portfolioAtRiskOutstanding($agency, $currency, $asOfDate, 90, $loanStatus, $loanProductId),
        ];
    }

    public function portfolioAtRiskOutstanding(?Agency $agency, string $currency, string $asOfDate, int $daysPastDue, ?string $loanStatus, ?int $loanProductId): int
    {
        $loanIds = $this->reportableLoanIds($agency, $currency, $loanStatus, $loanProductId);
        if ($loanIds === []) {
            return 0;
        }
        $cutoff = CarbonImmutable::parse($asOfDate)->subDays($daysPastDue)->toDateString();

        $overdueLoanIds = $this->loanIdsWithActiveLinesDueBefore($loanIds, $cutoff);
        if ($overdueLoanIds === []) {
            return 0;
        }

        return $this->outstandingForLoanIds($overdueLoanIds, $asOfDate);
    }

    /**
     * Distinct reportable loans that have overdue, not-fully-paid schedule
     * exposure as of the given date. This is the arrears/PAR loan-identification
     * definition with an explicit unpaid-balance check, used for delinquent
     * counts and amounts.
     *
     * @return list<int>
     */
    public function delinquentLoanIds(?Agency $agency, string $currency, string $asOfDate, ?string $loanStatus, ?int $loanProductId, int $daysPastDue = 0): array
    {
        $loanIds = $this->reportableLoanIds($agency, $currency, $loanStatus, $loanProductId);

        return $this->delinquentLoanIdsWithin($loanIds, $asOfDate, $daysPastDue);
    }

    /**
     * Subset of the given loans that have overdue, not-fully-paid schedule
     * exposure as of the date. Lets callers (e.g. the loan in-arrears filter)
     * apply the same delinquency definition to an already-scoped loan set.
     *
     * @param  list<int>  $loanIds
     * @return list<int>
     */
    public function delinquentLoanIdsWithin(array $loanIds, string $asOfDate, int $daysPastDue = 0): array
    {
        if ($loanIds === []) {
            return [];
        }

        $cutoff = CarbonImmutable::parse($asOfDate)->subDays($daysPastDue)->toDateString();

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
            ->whereDate('lsl.due_date', '<', $cutoff)
            ->groupBy('snap.loan_id', 'lsl.id', 'lsl.principal_minor', 'lsl.interest_minor', 'lsl.penalty_minor')
            ->havingRaw('(lsl.principal_minor + lsl.interest_minor + lsl.penalty_minor) - COALESCE(SUM(CASE WHEN r.id IS NOT NULL THEN alloc.amount_minor ELSE 0 END), 0) > 0')
            ->select('snap.loan_id')
            ->pluck('snap.loan_id');

        $delinquent = [];
        foreach ($rows as $value) {
            if (is_numeric($value)) {
                $delinquent[] = (int) $value;
            }
        }

        return array_values(array_unique($delinquent));
    }

    public function delinquentLoanCount(?Agency $agency, string $currency, string $asOfDate, ?string $loanStatus, ?int $loanProductId, int $daysPastDue = 0): int
    {
        return count($this->delinquentLoanIds($agency, $currency, $asOfDate, $loanStatus, $loanProductId, $daysPastDue));
    }

    /**
     * @return array{
     *     expected_collection_minor:int,
     *     actual_collection_minor:int,
     *     performance_ratio:?float,
     * }
     */
    public function collections(?Agency $agency, string $currency, ?string $from, ?string $to, ?string $loanStatus, ?int $loanProductId): array
    {
        $expectedQuery = DB::table('loan_schedule_lines')
            ->join('loan_schedule_snapshots', 'loan_schedule_snapshots.id', '=', 'loan_schedule_lines.loan_schedule_snapshot_id')
            ->join('loans', 'loans.id', '=', 'loan_schedule_snapshots.loan_id')
            ->where('loan_schedule_snapshots.status', 'active')
            ->where('loans.currency', $currency)
            ->whereIn('loans.status', self::REPORTABLE_LOAN_STATUSES);
        $this->applyLoanScopeFilters($expectedQuery, $agency, $loanStatus, $loanProductId);
        if ($from !== null) {
            $expectedQuery->whereDate('loan_schedule_lines.due_date', '>=', $from);
        }
        if ($to !== null) {
            $expectedQuery->whereDate('loan_schedule_lines.due_date', '<=', $to);
        }
        $expected = $this->numericValue($expectedQuery
            ->selectRaw('COALESCE(SUM(loan_schedule_lines.principal_minor + loan_schedule_lines.interest_minor + loan_schedule_lines.penalty_minor), 0) AS total_minor')
            ->value('total_minor'));

        $actual = $this->postedCollectionMinor($agency, $currency, $from, $to, $loanStatus, $loanProductId);

        return [
            'expected_collection_minor' => $expected,
            'actual_collection_minor' => $actual,
            'performance_ratio' => $expected > 0 ? round($actual / $expected, 6) : null,
        ];
    }

    /**
     * Posted repayment allocation total (principal+interest+penalty) for a
     * half-open timestamp window [startTimestamp, endTimestamp). Because
     * `paid_on` is a date, midnight-aligned bucket boundaries cleanly attribute
     * a day's collections to the bucket that contains that midnight.
     */
    public function windowedCollectionMinor(?Agency $agency, string $currency, string $startTimestamp, string $endTimestamp, ?string $loanStatus, ?int $loanProductId): int
    {
        $query = $this->postedAllocationQuery($agency, $currency, $loanStatus, $loanProductId)
            ->where('loan_repayments.paid_on', '>=', $startTimestamp)
            ->where('loan_repayments.paid_on', '<', $endTimestamp);

        return $this->numericValue($query->sum('loan_repayment_allocations.amount_minor'));
    }

    /**
     * Posted collections total for an inclusive [from, to] date window
     * (operational-dashboard policy).
     */
    public function postedCollectionMinor(?Agency $agency, string $currency, ?string $from, ?string $to, ?string $loanStatus, ?int $loanProductId): int
    {
        $query = $this->postedAllocationQuery($agency, $currency, $loanStatus, $loanProductId);
        if ($from !== null) {
            $query->whereDate('loan_repayments.paid_on', '>=', $from);
        }
        if ($to !== null) {
            $query->whereDate('loan_repayments.paid_on', '<=', $to);
        }

        return $this->numericValue($query->sum('loan_repayment_allocations.amount_minor'));
    }

    /**
     * Resolve a [from, to] date window for a named period anchored to today.
     *
     * @return array{from:string, to:string}
     */
    public function periodWindow(?string $period): array
    {
        $now = CarbonImmutable::now();

        return match ($period) {
            'today' => ['from' => $now->toDateString(), 'to' => $now->toDateString()],
            'week' => ['from' => $now->startOfWeek()->toDateString(), 'to' => $now->endOfWeek()->toDateString()],
            'year' => ['from' => $now->startOfYear()->toDateString(), 'to' => $now->endOfYear()->toDateString()],
            default => ['from' => $now->startOfMonth()->toDateString(), 'to' => $now->endOfMonth()->toDateString()],
        };
    }

    /**
     * Default bucket granularity for a period. `day` is the finest default
     * because repayments and schedule lines are date-granular: `hour` is still
     * accepted on request, but sub-day collection buckets carry a full day's
     * collections in the midnight bucket only (see {@see windowedCollectionMinor}).
     */
    public function defaultGranularity(?string $period): string
    {
        return match ($period) {
            'year' => 'month',
            default => 'day',
        };
    }

    /**
     * Build the ordered list of buckets covering [fromDate, toDate] for a
     * granularity. Each bucket carries its UTC start/end timestamps and the
     * as-of date used for the cumulative outstanding balance.
     *
     * @return list<array{label:string, start:string, end:string, as_of:string}>
     */
    public function buildBuckets(string $fromDate, string $toDate, string $granularity, int $maxBuckets = 1000): array
    {
        $windowStart = CarbonImmutable::parse($fromDate, 'UTC')->startOfDay();
        $windowEnd = CarbonImmutable::parse($toDate, 'UTC')->endOfDay();

        $cursor = $this->alignBucketStart($windowStart, $granularity);
        $buckets = [];

        while ($cursor <= $windowEnd && count($buckets) < $maxBuckets) {
            $next = $this->advanceBucket($cursor, $granularity);
            $asOf = $next->subSecond();
            if ($asOf->greaterThan($windowEnd)) {
                $asOf = $windowEnd;
            }

            $buckets[] = [
                'label' => $cursor->toIso8601String(),
                'start' => $cursor->toDateTimeString(),
                'end' => $next->toDateTimeString(),
                'as_of' => $asOf->toDateString(),
            ];

            $cursor = $next;
        }

        return $buckets;
    }

    private function alignBucketStart(CarbonImmutable $start, string $granularity): CarbonImmutable
    {
        return match ($granularity) {
            'hour' => $start->startOfHour(),
            'week' => $start->startOfWeek(),
            'month' => $start->startOfMonth(),
            default => $start->startOfDay(),
        };
    }

    private function advanceBucket(CarbonImmutable $cursor, string $granularity): CarbonImmutable
    {
        return match ($granularity) {
            'hour' => $cursor->addHour(),
            'week' => $cursor->addWeek(),
            'month' => $cursor->addMonth(),
            default => $cursor->addDay(),
        };
    }

    /**
     * Base posted-allocation collection query honoring scope filters.
     */
    private function postedAllocationQuery(?Agency $agency, string $currency, ?string $loanStatus, ?int $loanProductId): Builder
    {
        $query = DB::table('loan_repayment_allocations')
            ->join('loan_repayments', 'loan_repayments.id', '=', 'loan_repayment_allocations.loan_repayment_id')
            ->join('loans', 'loans.id', '=', 'loan_repayments.loan_id')
            ->where('loan_repayments.status', 'posted')
            ->where('loan_repayments.currency', $currency)
            ->whereIn('loan_repayment_allocations.component', ['principal', 'interest', 'penalty']);
        $this->applyLoanScopeFilters($query, $agency, $loanStatus, $loanProductId);

        return $query;
    }

    private function applyLoanScopeFilters(Builder $query, ?Agency $agency, ?string $loanStatus, ?int $loanProductId): void
    {
        if ($agency instanceof Agency) {
            $query->where('loans.agency_id', $agency->id);
        }
        if ($loanStatus !== null) {
            $query->where('loans.status', $loanStatus);
        }
        if ($loanProductId !== null) {
            $query->where('loans.loan_product_id', $loanProductId);
        }
    }

    /**
     * @param  list<int>  $loanIds
     * @return list<int>
     */
    private function loanIdsWithActiveLinesDueBefore(array $loanIds, string $cutoff): array
    {
        $values = DB::table('loan_schedule_lines')
            ->join('loan_schedule_snapshots', 'loan_schedule_snapshots.id', '=', 'loan_schedule_lines.loan_schedule_snapshot_id')
            ->whereIn('loan_schedule_snapshots.loan_id', $loanIds)
            ->where('loan_schedule_snapshots.status', 'active')
            ->whereDate('loan_schedule_lines.due_date', '<', $cutoff)
            ->select('loan_schedule_snapshots.loan_id')
            ->distinct()
            ->pluck('loan_schedule_snapshots.loan_id');

        $ids = [];
        foreach ($values as $value) {
            if (is_numeric($value)) {
                $ids[] = (int) $value;
            }
        }

        return array_values(array_unique($ids));
    }
}
