<?php

declare(strict_types=1);

namespace App\Application\Dashboard;

use App\Http\Controllers\BaseController;
use App\Models\Agency;
use App\Models\JournalEntry;
use App\Models\Loan;
use App\Models\Till;
use App\Models\User;
use App\Support\Staff\StaffAgencyScope;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

final class DashboardWorkflow extends BaseController
{
    public function __construct(
        private readonly StaffAgencyScope $staffAgencyScope,
    ) {}

    public function operational(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondUnauthorized();
        }
        if (! $actor->hasRole('platform-admin')
            && ! $actor->hasRole('agency-manager')
            && ! $actor->hasPermissionTo('accounting.audit.view')) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'agency_public_id' => ['sometimes', 'nullable', 'string', 'exists:agencies,public_id'],
            'period_starts_on' => ['sometimes', 'nullable', 'date'],
            'period_ends_on' => ['sometimes', 'nullable', 'date', 'after_or_equal:period_starts_on'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'loan_product_public_id' => ['sometimes', 'nullable', 'string', 'exists:loan_products,public_id'],
            'loan_status' => ['sometimes', 'nullable', 'string', 'max:32'],
            'product_status' => ['sometimes', 'nullable', 'string', 'max:32'],
            'premium_status' => ['sometimes', 'nullable', 'string', 'max:32'],
            'claim_status' => ['sometimes', 'nullable', 'string', 'max:32'],
        ])->validate();

        try {
            $agency = $this->resolveAgencyScope($actor, $validated['agency_public_id'] ?? null);
        } catch (InvalidArgumentException $exception) {
            return $this->respondForbidden($exception->getMessage());
        }

        $currency = strtoupper((string) ($validated['currency'] ?? 'XAF'));
        $from = is_string($validated['period_starts_on'] ?? null) && $validated['period_starts_on'] !== '' ? $validated['period_starts_on'] : null;
        $to = is_string($validated['period_ends_on'] ?? null) && $validated['period_ends_on'] !== '' ? $validated['period_ends_on'] : null;
        $loanStatus = is_string($validated['loan_status'] ?? null) && $validated['loan_status'] !== ''
            ? $validated['loan_status']
            : (is_string($validated['product_status'] ?? null) && $validated['product_status'] !== '' ? $validated['product_status'] : null);
        $loanProductId = $this->loanProductIdByPublicId($validated['loan_product_public_id'] ?? null);
        $premiumStatus = is_string($validated['premium_status'] ?? null) && $validated['premium_status'] !== '' ? $validated['premium_status'] : null;
        $claimStatus = is_string($validated['claim_status'] ?? null) && $validated['claim_status'] !== '' ? $validated['claim_status'] : null;
        $asOfDate = $to ?? now()->toDateString();

        $portfolio = $this->portfolioOutstanding($agency, $currency, $asOfDate, $loanStatus, $loanProductId);
        $par = $this->parBuckets($agency, $currency, $asOfDate, $loanStatus, $loanProductId);
        $collections = $this->collections($agency, $currency, $from, $to, $loanStatus, $loanProductId);
        $cashPosition = $this->dailyCashPosition($agency);
        $tellerVariances = $this->tellerVariances($agency, $from, $to);
        $premiums = $this->insurancePremiums($agency, $from, $to, $premiumStatus);
        $claims = $this->claimsByStatus($agency, $claimStatus);

        return $this->respondSuccess([
            'agency_public_id' => $agency?->public_id,
            'currency' => $currency,
            'period' => ['from' => $from, 'to' => $to],
            'loan_product_public_id' => $validated['loan_product_public_id'] ?? null,
            'loan_status' => $loanStatus,
            'premium_status' => $premiumStatus,
            'claim_status' => $claimStatus,
            'data_freshness_at' => $this->dataFreshnessAt(),
            'metric_sources' => [
                'portfolio_outstanding' => 'credit_portfolio_outstanding',
                'par' => 'credit_par_delinquency',
                'collections' => 'credit_collection_performance',
                'cash_position' => 'posted_journal_lines',
                'teller_variances' => 'closed_teller_sessions',
                'insurance_premiums' => 'insurance_premium_assessments_and_payments',
                'claims_by_status' => 'insurance_claims',
            ],
            'portfolio_outstanding_minor' => $portfolio,
            'par' => $par,
            'collections' => $collections,
            'cash_position_minor' => $cashPosition,
            'teller_variances' => $tellerVariances,
            'insurance_premiums' => $premiums,
            'claims_by_status' => $claims,
        ], 'Operational dashboard');
    }

    public function executive(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasRole('platform-admin')) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'period_starts_on' => ['sometimes', 'nullable', 'date'],
            'period_ends_on' => ['sometimes', 'nullable', 'date', 'after_or_equal:period_starts_on'],
            'currency' => ['sometimes', 'string', 'size:3'],
        ])->validate();

        $currency = strtoupper((string) ($validated['currency'] ?? 'XAF'));
        $from = is_string($validated['period_starts_on'] ?? null) && $validated['period_starts_on'] !== '' ? $validated['period_starts_on'] : null;
        $to = is_string($validated['period_ends_on'] ?? null) && $validated['period_ends_on'] !== '' ? $validated['period_ends_on'] : null;
        $asOfDate = $to ?? now()->toDateString();

        $portfolio = $this->portfolioOutstanding(null, $currency, $asOfDate, null, null);
        $par = $this->parBuckets(null, $currency, $asOfDate, null, null);
        $collections = $this->collections(null, $currency, $from, $to, null, null);
        $premiumTotals = $this->insurancePremiums(null, $from, $to, null);
        $claimCounts = $this->claimsByStatus(null, null);

        return $this->respondSuccess([
            'currency' => $currency,
            'period' => ['from' => $from, 'to' => $to],
            'data_freshness_at' => $this->dataFreshnessAt(),
            'portfolio_outstanding_minor' => $portfolio,
            'par_total_minor' => $par['par30_outstanding_at_risk_minor'] + $par['par60_outstanding_at_risk_minor'] + $par['par90_outstanding_at_risk_minor'],
            'par_buckets' => [
                'par30_outstanding_at_risk_minor' => $par['par30_outstanding_at_risk_minor'],
                'par60_outstanding_at_risk_minor' => $par['par60_outstanding_at_risk_minor'],
                'par90_outstanding_at_risk_minor' => $par['par90_outstanding_at_risk_minor'],
            ],
            'collections' => [
                'expected_collection_minor' => $collections['expected_collection_minor'],
                'actual_collection_minor' => $collections['actual_collection_minor'],
                'performance_ratio' => $collections['performance_ratio'],
            ],
            'insurance_premium_totals' => [
                'assessed_minor' => $premiumTotals['assessed_minor'],
                'paid_minor' => $premiumTotals['paid_minor'],
            ],
            'claim_counts' => [
                'pending' => $claimCounts['pending'] ?? 0,
                'approved' => $claimCounts['approved'] ?? 0,
                'rejected' => $claimCounts['rejected'] ?? 0,
                'settled' => $claimCounts['settled'] ?? 0,
            ],
        ], 'Executive dashboard');
    }

    private function resolveAgencyScope(User $actor, mixed $requestedAgencyPublicId): ?Agency
    {
        $isPlatformAdmin = $actor->hasRole('platform-admin');
        $assignedAgencyId = $this->staffAgencyScope->currentAgencyId($actor);

        $requested = is_string($requestedAgencyPublicId) && $requestedAgencyPublicId !== ''
            ? Agency::query()->where('public_id', $requestedAgencyPublicId)->first()
            : null;

        if ($isPlatformAdmin) {
            return $requested instanceof Agency ? $requested : null;
        }

        if ($assignedAgencyId === null) {
            throw new InvalidArgumentException('Operational dashboard requires an agency assignment.');
        }

        if ($requested instanceof Agency && $requested->id !== $assignedAgencyId) {
            throw new InvalidArgumentException('You may not view dashboards for another agency.');
        }

        $agency = $requested instanceof Agency ? $requested : Agency::query()->whereKey($assignedAgencyId)->first();
        if (! $agency instanceof Agency) {
            throw new InvalidArgumentException('Assigned agency could not be loaded.');
        }

        return $agency;
    }

    private function portfolioOutstanding(?Agency $agency, string $currency, string $asOfDate, ?string $loanStatus, ?int $loanProductId): int
    {
        $loanIds = $this->reportableLoanIds($agency, $currency, $loanStatus, $loanProductId);
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

        $paid = $this->numericValue(DB::table('loan_repayment_allocations')
            ->join('loan_repayments', 'loan_repayments.id', '=', 'loan_repayment_allocations.loan_repayment_id')
            ->whereIn('loan_repayments.loan_id', $loanIds)
            ->where('loan_repayments.status', 'posted')
            ->whereIn('loan_repayment_allocations.component', ['principal', 'interest', 'penalty'])
            ->sum('loan_repayment_allocations.amount_minor'));

        return max(0, $due - $paid);
    }

    private function numericValue(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @return array{
     *     par30_outstanding_at_risk_minor:int,
     *     par60_outstanding_at_risk_minor:int,
     *     par90_outstanding_at_risk_minor:int,
     * }
     */
    private function parBuckets(?Agency $agency, string $currency, string $asOfDate, ?string $loanStatus, ?int $loanProductId): array
    {
        return [
            'par30_outstanding_at_risk_minor' => $this->portfolioAtRiskOutstanding($agency, $currency, $asOfDate, 30, $loanStatus, $loanProductId),
            'par60_outstanding_at_risk_minor' => $this->portfolioAtRiskOutstanding($agency, $currency, $asOfDate, 60, $loanStatus, $loanProductId),
            'par90_outstanding_at_risk_minor' => $this->portfolioAtRiskOutstanding($agency, $currency, $asOfDate, 90, $loanStatus, $loanProductId),
        ];
    }

    private function portfolioAtRiskOutstanding(?Agency $agency, string $currency, string $asOfDate, int $daysPastDue, ?string $loanStatus, ?int $loanProductId): int
    {
        $loanIds = $this->reportableLoanIds($agency, $currency, $loanStatus, $loanProductId);
        if ($loanIds === []) {
            return 0;
        }
        $cutoff = CarbonImmutable::parse($asOfDate)->subDays($daysPastDue)->toDateString();

        $overdueLoanIds = DB::table('loan_schedule_lines')
            ->join('loan_schedule_snapshots', 'loan_schedule_snapshots.id', '=', 'loan_schedule_lines.loan_schedule_snapshot_id')
            ->whereIn('loan_schedule_snapshots.loan_id', $loanIds)
            ->where('loan_schedule_snapshots.status', 'active')
            ->whereDate('loan_schedule_lines.due_date', '<', $cutoff)
            ->select('loan_schedule_snapshots.loan_id')
            ->distinct()
            ->pluck('loan_schedule_snapshots.loan_id')
            ->filter(fn (mixed $id): bool => is_numeric($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
        $overdueLoanIds = array_values($overdueLoanIds);

        if ($overdueLoanIds === []) {
            return 0;
        }

        return $this->outstandingForLoanIds($overdueLoanIds, $asOfDate);
    }

    /**
     * @return list<int>
     */
    private function reportableLoanIds(?Agency $agency, string $currency, ?string $loanStatus, ?int $loanProductId): array
    {
        $query = DB::table('loans')
            ->where('currency', $currency)
            ->whereIn('status', [Loan::STATUS_DISBURSED, Loan::STATUS_ACTIVE, Loan::STATUS_RESCHEDULED]);
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

    /**
     * @return array{
     *     expected_collection_minor:int,
     *     actual_collection_minor:int,
     *     performance_ratio:?float,
     * }
     */
    private function collections(?Agency $agency, string $currency, ?string $from, ?string $to, ?string $loanStatus, ?int $loanProductId): array
    {
        $expectedQuery = DB::table('loan_schedule_lines')
            ->join('loan_schedule_snapshots', 'loan_schedule_snapshots.id', '=', 'loan_schedule_lines.loan_schedule_snapshot_id')
            ->join('loans', 'loans.id', '=', 'loan_schedule_snapshots.loan_id')
            ->where('loan_schedule_snapshots.status', 'active')
            ->where('loans.currency', $currency)
            ->whereIn('loans.status', [Loan::STATUS_DISBURSED, Loan::STATUS_ACTIVE, Loan::STATUS_RESCHEDULED]);
        if ($agency instanceof Agency) {
            $expectedQuery->where('loans.agency_id', $agency->id);
        }
        if ($loanStatus !== null) {
            $expectedQuery->where('loans.status', $loanStatus);
        }
        if ($loanProductId !== null) {
            $expectedQuery->where('loans.loan_product_id', $loanProductId);
        }
        if ($from !== null) {
            $expectedQuery->whereDate('loan_schedule_lines.due_date', '>=', $from);
        }
        if ($to !== null) {
            $expectedQuery->whereDate('loan_schedule_lines.due_date', '<=', $to);
        }
        $expected = $this->numericValue($expectedQuery
            ->selectRaw('COALESCE(SUM(loan_schedule_lines.principal_minor + loan_schedule_lines.interest_minor + loan_schedule_lines.penalty_minor), 0) AS total_minor')
            ->value('total_minor'));

        $actualQuery = DB::table('loan_repayment_allocations')
            ->join('loan_repayments', 'loan_repayments.id', '=', 'loan_repayment_allocations.loan_repayment_id')
            ->join('loans', 'loans.id', '=', 'loan_repayments.loan_id')
            ->where('loan_repayments.status', 'posted')
            ->where('loan_repayments.currency', $currency)
            ->whereIn('loan_repayment_allocations.component', ['principal', 'interest', 'penalty']);
        if ($agency instanceof Agency) {
            $actualQuery->where('loans.agency_id', $agency->id);
        }
        if ($loanStatus !== null) {
            $actualQuery->where('loans.status', $loanStatus);
        }
        if ($loanProductId !== null) {
            $actualQuery->where('loans.loan_product_id', $loanProductId);
        }
        if ($from !== null) {
            $actualQuery->whereDate('loan_repayments.paid_on', '>=', $from);
        }
        if ($to !== null) {
            $actualQuery->whereDate('loan_repayments.paid_on', '<=', $to);
        }
        $actual = $this->numericValue($actualQuery->sum('loan_repayment_allocations.amount_minor'));

        return [
            'expected_collection_minor' => $expected,
            'actual_collection_minor' => $actual,
            'performance_ratio' => $expected > 0 ? round($actual / $expected, 6) : null,
        ];
    }

    private function dailyCashPosition(?Agency $agency): int
    {
        $query = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'journal_lines.ledger_account_id')
            ->join('tills', 'tills.ledger_account_id', '=', 'ledger_accounts.id')
            ->where('journal_entries.status', JournalEntry::STATUS_POSTED)
            ->where('tills.status', Till::STATUS_ACTIVE)
            ->where('journal_lines.currency', 'XAF');
        if ($agency instanceof Agency) {
            $query->where('tills.agency_id', $agency->id);
        }

        $totals = $query
            ->selectRaw('COALESCE(SUM(journal_lines.debit_minor), 0) AS debit_total')
            ->selectRaw('COALESCE(SUM(journal_lines.credit_minor), 0) AS credit_total')
            ->first();

        $debit = is_object($totals) && is_numeric($totals->debit_total) ? (int) $totals->debit_total : 0;
        $credit = is_object($totals) && is_numeric($totals->credit_total) ? (int) $totals->credit_total : 0;

        return max(0, $debit - $credit);
    }

    /**
     * @return array{closed_count:int, variance_count:int, variance_total_abs_minor:int}
     */
    private function tellerVariances(?Agency $agency, ?string $from, ?string $to): array
    {
        $query = DB::table('teller_sessions')
            ->whereNotNull('closed_at')
            ->whereNotNull('opening_declaration_minor')
            ->whereNotNull('closing_declaration_minor');
        if ($agency instanceof Agency) {
            $query->where('agency_id', $agency->id);
        }
        if ($from !== null) {
            $query->whereDate('business_date', '>=', $from);
        }
        if ($to !== null) {
            $query->whereDate('business_date', '<=', $to);
        }

        $sessions = $query->select([
            'id',
            'opening_declaration_minor',
            'closing_declaration_minor',
        ])->get();

        $varianceCount = 0;
        $varianceAbs = 0;
        foreach ($sessions as $session) {
            $opening = is_numeric($session->opening_declaration_minor) ? (int) $session->opening_declaration_minor : 0;
            $closing = is_numeric($session->closing_declaration_minor) ? (int) $session->closing_declaration_minor : 0;

            $movement = $this->numericValue(DB::table('teller_transactions')
                ->where('teller_session_id', $session->id)
                ->where('status', 'posted')
                ->selectRaw("COALESCE(SUM(CASE WHEN transaction_type = 'cash_deposit' THEN amount_minor WHEN transaction_type = 'cash_withdrawal' THEN -amount_minor ELSE 0 END), 0) AS movement")
                ->value('movement'));

            $expectedClose = $opening + $movement;
            $variance = $closing - $expectedClose;
            if ($variance !== 0) {
                $varianceCount++;
                $varianceAbs += abs($variance);
            }
        }

        return [
            'closed_count' => $sessions->count(),
            'variance_count' => $varianceCount,
            'variance_total_abs_minor' => $varianceAbs,
        ];
    }

    /**
     * @return array{assessed_minor:int, paid_minor:int, due_count:int, paid_count:int}
     */
    private function insurancePremiums(?Agency $agency, ?string $from, ?string $to, ?string $premiumStatus): array
    {
        $assessQuery = DB::table('insurance_premium_assessments as assess')
            ->join('insurance_subscriptions as sub', 'sub.id', '=', 'assess.insurance_subscription_id');
        if ($agency instanceof Agency) {
            $assessQuery->where('sub.agency_id', $agency->id);
        }
        if ($from !== null) {
            $assessQuery->whereDate('assess.due_on', '>=', $from);
        }
        if ($to !== null) {
            $assessQuery->whereDate('assess.due_on', '<=', $to);
        }
        if ($premiumStatus !== null) {
            $assessQuery->where('assess.status', $premiumStatus);
        } else {
            $assessQuery->where('assess.status', 'assessed');
        }
        $assessed = $this->numericValue($assessQuery->sum('assess.premium_amount_minor'));
        $dueCount = (clone $assessQuery)->count();

        $paidQuery = DB::table('insurance_premium_payments as pay')
            ->join('insurance_premium_assessments as assess', 'assess.id', '=', 'pay.insurance_premium_assessment_id')
            ->join('insurance_subscriptions as sub', 'sub.id', '=', 'assess.insurance_subscription_id');
        if ($agency instanceof Agency) {
            $paidQuery->where('sub.agency_id', $agency->id);
        }
        if ($from !== null) {
            $paidQuery->whereDate('pay.paid_at', '>=', $from);
        }
        if ($to !== null) {
            $paidQuery->whereDate('pay.paid_at', '<=', $to);
        }
        $paidQuery->where('pay.status', 'posted');
        $paid = $this->numericValue($paidQuery->sum('pay.amount_minor'));
        $paidCount = (clone $paidQuery)->count();

        return [
            'assessed_minor' => $assessed,
            'paid_minor' => $paid,
            'due_count' => $dueCount,
            'paid_count' => $paidCount,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function claimsByStatus(?Agency $agency, ?string $claimStatus): array
    {
        $query = DB::table('insurance_claims');
        if ($agency instanceof Agency) {
            $query->where('agency_id', $agency->id);
        }
        if ($claimStatus !== null) {
            $query->where('status', $claimStatus);
        }

        $rows = $query
            ->select('status')
            ->selectRaw('COUNT(*) AS row_count')
            ->groupBy('status')
            ->get();

        $byStatus = [];
        foreach ($rows as $row) {
            $status = is_string($row->status) ? $row->status : '';
            $count = is_numeric($row->row_count) ? (int) $row->row_count : 0;
            if ($status !== '') {
                $byStatus[$status] = $count;
            }
        }

        return $byStatus;
    }

    /**
     * @param list<int> $loanIds
     */
    private function outstandingForLoanIds(array $loanIds, string $asOfDate): int
    {
        $due = $this->numericValue(DB::table('loan_schedule_lines')
            ->join('loan_schedule_snapshots', 'loan_schedule_snapshots.id', '=', 'loan_schedule_lines.loan_schedule_snapshot_id')
            ->whereIn('loan_schedule_snapshots.loan_id', $loanIds)
            ->where('loan_schedule_snapshots.status', 'active')
            ->whereDate('loan_schedule_lines.due_date', '<=', $asOfDate)
            ->selectRaw('COALESCE(SUM(loan_schedule_lines.principal_minor + loan_schedule_lines.interest_minor + loan_schedule_lines.penalty_minor), 0) AS total_minor')
            ->value('total_minor'));

        $paid = $this->numericValue(DB::table('loan_repayment_allocations')
            ->join('loan_repayments', 'loan_repayments.id', '=', 'loan_repayment_allocations.loan_repayment_id')
            ->whereIn('loan_repayments.loan_id', $loanIds)
            ->where('loan_repayments.status', 'posted')
            ->whereIn('loan_repayment_allocations.component', ['principal', 'interest', 'penalty'])
            ->sum('loan_repayment_allocations.amount_minor'));

        return max(0, $due - $paid);
    }

    private function loanProductIdByPublicId(mixed $publicId): ?int
    {
        if (! is_string($publicId) || $publicId === '') {
            return null;
        }

        $id = DB::table('loan_products')->where('public_id', $publicId)->value('id');

        return is_numeric($id) ? (int) $id : null;
    }

    private function dataFreshnessAt(): string
    {
        $candidates = [
            DB::table('report_runs')->max('generated_at'),
            DB::table('journal_entries')->max('updated_at'),
            DB::table('loans')->max('updated_at'),
            DB::table('loan_repayments')->max('updated_at'),
            DB::table('teller_sessions')->max('updated_at'),
            DB::table('insurance_premium_assessments')->max('updated_at'),
            DB::table('insurance_premium_payments')->max('updated_at'),
            DB::table('insurance_claims')->max('updated_at'),
        ];

        $latest = null;
        foreach ($candidates as $candidate) {
            if (! is_string($candidate) || $candidate === '') {
                continue;
            }
            if ($latest === null || $candidate > $latest) {
                $latest = $candidate;
            }
        }

        return $latest !== null
            ? CarbonImmutable::parse($latest)->toIso8601String()
            : now()->toIso8601String();
    }
}
