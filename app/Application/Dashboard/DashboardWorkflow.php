<?php

declare(strict_types=1);

namespace App\Application\Dashboard;

use App\Http\Controllers\BaseController;
use App\Models\Agency;
use App\Models\JournalEntry;
use App\Models\Till;
use App\Models\User;
use App\Support\Staff\StaffAgencyScope;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

final class DashboardWorkflow extends BaseController
{
    public function __construct(
        private readonly StaffAgencyScope $staffAgencyScope,
        private readonly DashboardMetrics $metrics,
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
        $loanProductId = $this->metrics->loanProductIdByPublicId($validated['loan_product_public_id'] ?? null);
        $premiumStatus = is_string($validated['premium_status'] ?? null) && $validated['premium_status'] !== '' ? $validated['premium_status'] : null;
        $claimStatus = is_string($validated['claim_status'] ?? null) && $validated['claim_status'] !== '' ? $validated['claim_status'] : null;
        $asOfDate = $to ?? now()->toDateString();

        $portfolio = $this->metrics->portfolioOutstanding($agency, $currency, $asOfDate, $loanStatus, $loanProductId);
        $par = $this->metrics->parBuckets($agency, $currency, $asOfDate, $loanStatus, $loanProductId);
        $collections = $this->metrics->collections($agency, $currency, $from, $to, $loanStatus, $loanProductId);
        $activeLoanCount = $this->metrics->reportableLoanCount($agency, $currency, $loanStatus, $loanProductId);
        $delinquentLoanCount = $this->metrics->delinquentLoanCount($agency, $currency, $asOfDate, $loanStatus, $loanProductId);
        $cashPosition = $this->dailyCashPosition($agency);
        $tellerVariances = $this->tellerVariances($agency, $from, $to);
        $premiums = $this->insurancePremiums($agency, $from, $to, $premiumStatus);
        $claims = $this->claimsByStatus($agency, $claimStatus);
        $sections = $this->dashboardSections([
            ['key' => 'portfolio_outstanding_minor', 'label' => 'Portfolio outstanding', 'value' => $portfolio],
            ['key' => 'active_loan_count', 'label' => 'Active loans', 'value' => $activeLoanCount],
            ['key' => 'delinquent_loan_count', 'label' => 'Delinquent loans', 'value' => $delinquentLoanCount],
            ['key' => 'par_30_outstanding_at_risk_minor', 'label' => 'PAR 30', 'value' => $par['par30_outstanding_at_risk_minor']],
            ['key' => 'par_60_outstanding_at_risk_minor', 'label' => 'PAR 60', 'value' => $par['par60_outstanding_at_risk_minor']],
            ['key' => 'par_90_outstanding_at_risk_minor', 'label' => 'PAR 90', 'value' => $par['par90_outstanding_at_risk_minor']],
            ['key' => 'cash_position_minor', 'label' => 'Cash position', 'value' => $cashPosition],
            ['key' => 'insurance_premiums_assessed_minor', 'label' => 'Insurance premiums assessed', 'value' => $premiums['assessed_minor']],
            ['key' => 'insurance_premiums_paid_minor', 'label' => 'Insurance premiums paid', 'value' => $premiums['paid_minor']],
            ['key' => 'claim_pending_count', 'label' => 'Pending claims', 'value' => $claims['pending'] ?? 0],
            ['key' => 'claim_approved_count', 'label' => 'Approved claims', 'value' => $claims['approved'] ?? 0],
            ['key' => 'claim_rejected_count', 'label' => 'Rejected claims', 'value' => $claims['rejected'] ?? 0],
            ['key' => 'claim_settled_count', 'label' => 'Settled claims', 'value' => $claims['settled'] ?? 0],
        ], $request);
        $pagination = $this->paginateSections($sections, $request, 100);

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
            'active_loan_count' => $activeLoanCount,
            'delinquent_loan_count' => $delinquentLoanCount,
            'par' => $par,
            'collections' => $collections,
            'cash_position_minor' => $cashPosition,
            'teller_variances' => $tellerVariances,
            'insurance_premiums' => $premiums,
            'claims_by_status' => $claims,
            'dashboard_sections' => $pagination['items'],
        ], 'Operational dashboard', ['pagination' => $pagination['pagination']]);
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

        $portfolio = $this->metrics->portfolioOutstanding(null, $currency, $asOfDate, null, null);
        $par = $this->metrics->parBuckets(null, $currency, $asOfDate, null, null);
        $collections = $this->metrics->collections(null, $currency, $from, $to, null, null);
        $premiumTotals = $this->insurancePremiums(null, $from, $to, null);
        $claimCounts = $this->claimsByStatus(null, null);
        $sections = $this->dashboardSections([
            ['key' => 'portfolio_outstanding_minor', 'label' => 'Portfolio outstanding', 'value' => $portfolio],
            ['key' => 'par_total_minor', 'label' => 'PAR total', 'value' => $par['par30_outstanding_at_risk_minor'] + $par['par60_outstanding_at_risk_minor'] + $par['par90_outstanding_at_risk_minor']],
            ['key' => 'collection_actual_minor', 'label' => 'Collections actual', 'value' => $collections['actual_collection_minor']],
            ['key' => 'collection_expected_minor', 'label' => 'Collections expected', 'value' => $collections['expected_collection_minor']],
            ['key' => 'insurance_premiums_assessed_minor', 'label' => 'Insurance premiums assessed', 'value' => $premiumTotals['assessed_minor']],
            ['key' => 'insurance_premiums_paid_minor', 'label' => 'Insurance premiums paid', 'value' => $premiumTotals['paid_minor']],
            ['key' => 'claim_pending_count', 'label' => 'Pending claims', 'value' => $claimCounts['pending'] ?? 0],
            ['key' => 'claim_approved_count', 'label' => 'Approved claims', 'value' => $claimCounts['approved'] ?? 0],
            ['key' => 'claim_rejected_count', 'label' => 'Rejected claims', 'value' => $claimCounts['rejected'] ?? 0],
            ['key' => 'claim_settled_count', 'label' => 'Settled claims', 'value' => $claimCounts['settled'] ?? 0],
        ], $request);
        $pagination = $this->paginateSections($sections, $request, 100);

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
            'dashboard_sections' => $pagination['items'],
        ], 'Executive dashboard', ['pagination' => $pagination['pagination']]);
    }

    public function timeseries(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondUnauthorized();
        }
        if (! $this->canViewOperational($actor)) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'agency_public_id' => ['sometimes', 'nullable', 'string', 'exists:agencies,public_id'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'period' => ['sometimes', 'nullable', 'string', Rule::in(['today', 'week', 'month', 'year'])],
            'granularity' => ['sometimes', 'nullable', 'string', Rule::in(['hour', 'day', 'week', 'month'])],
            'period_starts_on' => ['sometimes', 'nullable', 'date'],
            'period_ends_on' => ['sometimes', 'nullable', 'date', 'after_or_equal:period_starts_on'],
            'loan_product_public_id' => ['sometimes', 'nullable', 'string', 'exists:loan_products,public_id'],
            'loan_status' => ['sometimes', 'nullable', 'string', 'max:32'],
            'product_status' => ['sometimes', 'nullable', 'string', 'max:32'],
        ])->validate();

        try {
            $agency = $this->resolveAgencyScope($actor, $validated['agency_public_id'] ?? null);
        } catch (InvalidArgumentException $exception) {
            return $this->respondForbidden($exception->getMessage());
        }

        $currency = strtoupper((string) ($validated['currency'] ?? 'XAF'));
        $period = $this->stringOrNull($validated['period'] ?? null);
        ['from' => $from, 'to' => $to] = $this->resolvePeriod($period, $validated['period_starts_on'] ?? null, $validated['period_ends_on'] ?? null);
        $granularity = $this->stringOrNull($validated['granularity'] ?? null) ?? $this->metrics->defaultGranularity($period);
        $loanStatus = $this->resolveLoanStatus($validated);
        $loanProductId = $this->metrics->loanProductIdByPublicId($validated['loan_product_public_id'] ?? null);

        $buckets = $this->metrics->buildBuckets($from, $to, $granularity, 501);
        if (count($buckets) > 500) {
            return $this->respondUnprocessable('The requested period and granularity produce too many buckets (max 500). Narrow the range or use a coarser granularity.');
        }

        $points = [];
        foreach ($buckets as $bucket) {
            $points[] = [
                'bucket' => $bucket['label'],
                'balance_minor' => $this->metrics->portfolioBalanceAsOf($agency, $currency, $bucket['as_of'], $loanStatus, $loanProductId),
                'collection_minor' => $this->metrics->windowedCollectionMinor($agency, $currency, $bucket['start'], $bucket['end'], $loanStatus, $loanProductId),
            ];
        }

        return $this->respondSuccess([
            'agency_public_id' => $agency?->public_id,
            'currency' => $currency,
            'period' => ['from' => $from, 'to' => $to],
            'granularity' => $granularity,
            'loan_product_public_id' => $validated['loan_product_public_id'] ?? null,
            'loan_status' => $loanStatus,
            'points' => $points,
        ], 'Operational dashboard timeseries');
    }

    public function agenciesPerformance(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondUnauthorized();
        }
        if (! $this->canViewOperational($actor)) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'currency' => ['sometimes', 'string', 'size:3'],
            'period' => ['sometimes', 'nullable', 'string', Rule::in(['today', 'week', 'month', 'year'])],
            'period_starts_on' => ['sometimes', 'nullable', 'date'],
            'period_ends_on' => ['sometimes', 'nullable', 'date', 'after_or_equal:period_starts_on'],
            'agency_public_id' => ['sometimes', 'nullable', 'string', 'exists:agencies,public_id'],
        ])->validate();

        try {
            $scope = $this->resolveAgencyScope($actor, $validated['agency_public_id'] ?? null);
        } catch (InvalidArgumentException $exception) {
            return $this->respondForbidden($exception->getMessage());
        }

        $currency = strtoupper((string) ($validated['currency'] ?? 'XAF'));
        $period = $this->stringOrNull($validated['period'] ?? null);
        ['from' => $from, 'to' => $to] = $this->resolvePeriod($period, $validated['period_starts_on'] ?? null, $validated['period_ends_on'] ?? null);
        $asOfDate = $to;

        $agencies = $scope instanceof Agency
            ? collect([$scope])
            : Agency::query()->get()->sortBy('code')->values();

        $rows = [];
        foreach ($agencies as $agency) {
            $rows[] = $this->agencyPerformanceRow($agency, $currency, $from, $to, $asOfDate);
        }

        return $this->respondSuccess([
            'currency' => $currency,
            'period' => ['from' => $from, 'to' => $to],
            'agencies' => $rows,
        ], 'Agency performance dashboard');
    }

    /**
     * @return array<string, mixed>
     */
    private function agencyPerformanceRow(Agency $agency, string $currency, string $from, string $to, string $asOfDate): array
    {
        $delinquentLoanIds = $this->metrics->delinquentLoanIds($agency, $currency, $asOfDate, null, null);
        $bestAgent = $this->bestCollector($agency, $currency, $from, $to);

        return [
            'agency_public_id' => $agency->public_id,
            'agency_code' => $agency->code,
            'agency_name' => $agency->name,
            'collections_minor' => $this->metrics->postedCollectionMinor($agency, $currency, $from, $to, null, null),
            'loans_count' => $this->metrics->reportableLoanCount($agency, $currency, null, null),
            'loans_amount_minor' => $this->metrics->portfolioOutstanding($agency, $currency, $asOfDate, null, null),
            'delinquent_count' => count($delinquentLoanIds),
            'delinquent_amount_minor' => $this->metrics->outstandingForLoanIds($delinquentLoanIds, $asOfDate),
            'best_agent_public_id' => $bestAgent['public_id'],
            'best_agent_name' => $bestAgent['name'],
        ];
    }

    /**
     * Top collector (staff user) by posted repayment allocations in the period.
     *
     * @return array{public_id: ?string, name: ?string}
     */
    private function bestCollector(Agency $agency, string $currency, string $from, string $to): array
    {
        $top = DB::table('loan_repayment_allocations as alloc')
            ->join('loan_repayments as r', 'r.id', '=', 'alloc.loan_repayment_id')
            ->where('r.agency_id', $agency->id)
            ->where('r.status', 'posted')
            ->where('r.currency', $currency)
            ->whereIn('alloc.component', ['principal', 'interest', 'penalty'])
            ->whereNotNull('r.posted_by_user_id')
            ->whereDate('r.paid_on', '>=', $from)
            ->whereDate('r.paid_on', '<=', $to)
            ->groupBy('r.posted_by_user_id')
            ->select('r.posted_by_user_id')
            ->selectRaw('SUM(alloc.amount_minor) AS total_minor')
            ->orderByDesc('total_minor')
            ->first();

        if (! is_object($top) || ! is_numeric($top->posted_by_user_id ?? null)) {
            return ['public_id' => null, 'name' => null];
        }

        $user = User::query()->whereKey((int) $top->posted_by_user_id)->first(['public_id', 'name']);
        if (! $user instanceof User) {
            return ['public_id' => null, 'name' => null];
        }

        return ['public_id' => $user->public_id, 'name' => $user->name];
    }

    private function canViewOperational(User $actor): bool
    {
        return $actor->hasRole('platform-admin')
            || $actor->hasRole('agency-manager')
            || $actor->hasPermissionTo('accounting.audit.view');
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function resolveLoanStatus(array $validated): ?string
    {
        return $this->stringOrNull($validated['loan_status'] ?? null)
            ?? $this->stringOrNull($validated['product_status'] ?? null);
    }

    /**
     * Resolve the effective [from, to] window: explicit dates win per-side, with
     * gaps filled from the named period (default month). Always returns from<=to.
     *
     * @return array{from:string, to:string}
     */
    private function resolvePeriod(?string $period, mixed $explicitFrom, mixed $explicitTo): array
    {
        $window = $this->metrics->periodWindow($period);
        $from = $this->stringOrNull($explicitFrom) ?? $window['from'];
        $to = $this->stringOrNull($explicitTo) ?? $window['to'];
        if ($from > $to) {
            $to = $from;
        }

        return ['from' => $from, 'to' => $to];
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

    private function numericValue(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param  array<int, array{key:string,label:string,value:mixed}>  $sections
     * @return array<int, array{key:string,label:string,value:mixed}>
     */
    private function dashboardSections(array $sections, Request $request): array
    {
        $decorated = array_map(
            fn (array $section): array => $section + ['search_blob' => mb_strtolower($section['key'].' '.$section['label'].' '.json_encode($section['value'], JSON_THROW_ON_ERROR))],
            $sections,
        );
        $search = $request->query('search');
        if (! is_string($search) || trim($search) === '') {
            return array_map(static fn (array $section): array => [
                'key' => $section['key'],
                'label' => $section['label'],
                'value' => $section['value'],
            ], $decorated);
        }

        $term = mb_strtolower(trim($search));

        return array_map(
            static fn (array $section): array => [
                'key' => $section['key'],
                'label' => $section['label'],
                'value' => $section['value'],
            ],
            array_filter($decorated, fn (array $section): bool => str_contains($section['search_blob'], $term)),
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array{items: array<int, array<string, mixed>>, pagination: array<string, int>}
     */
    private function paginateSections(array $items, Request $request, int $defaultPerPage): array
    {
        $page = max(1, $request->integer('page', 1));
        $perPage = min(max($request->integer('per_page', $defaultPerPage), 1), 100);
        $total = count($items);
        $lastPage = max(1, (int) ceil($total / $perPage));

        return [
            'items' => array_slice($items, ($page - 1) * $perPage, $perPage),
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
            ],
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
                ->selectRaw("COALESCE(SUM(CASE WHEN transaction_type = 'cash_deposit' THEN COALESCE(cash_amount_minor, amount_minor) WHEN transaction_type = 'cash_withdrawal' THEN -COALESCE(cash_amount_minor, amount_minor) ELSE 0 END), 0) AS movement")
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
