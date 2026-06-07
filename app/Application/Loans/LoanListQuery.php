<?php

declare(strict_types=1);

namespace App\Application\Loans;

use App\Application\Dashboard\DashboardMetrics;
use App\Http\Controllers\BaseController;
use App\Models\Agency;
use App\Models\Client;
use App\Models\Loan;
use App\Models\User;
use App\Support\Staff\StaffAgencyScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Shared scoped loan list query used by GET /loans and GET /loans/stats.
 */
final class LoanListQuery extends BaseController
{
    /** @var list<string> */
    public const array ALLOWED_FILTER_KEYS = [
        'status',
        'credit_agent_public_id',
        'in_arrears',
        'par_bucket',
        'as_of_date',
        'awaiting_disbursement',
        'client_public_id',
    ];

    /** @var list<string> */
    public const array LOAN_STATUS_KEYS = [
        Loan::STATUS_APPLICATION,
        Loan::STATUS_IN_REVIEW,
        Loan::STATUS_APPROVED,
        Loan::STATUS_REJECTED,
        Loan::STATUS_DISBURSED,
        Loan::STATUS_ACTIVE,
        Loan::STATUS_RESCHEDULED,
        Loan::STATUS_CLOSED,
        Loan::STATUS_WRITTEN_OFF,
    ];

    public function __construct(
        private readonly StaffAgencyScope $staffAgencyScope,
        private readonly DashboardMetrics $dashboardMetrics,
        private readonly LoanDelinquencyProjection $delinquencyProjection,
        private readonly LoanDisbursementReadiness $disbursementReadiness,
    ) {}

    /**
     * @return array{
     *     query: Builder<Loan>,
     *     as_of_date: string,
     *     include_arrears_fields: bool,
     *     error: JsonResponse|null,
     * }
     */
    public function build(User $actor, Request $request): array
    {
        $filterError = $this->validateFilters($request);
        if ($filterError instanceof JsonResponse) {
            return [
                'query' => Loan::query()->whereKey(0),
                'as_of_date' => now()->toDateString(),
                'include_arrears_fields' => false,
                'error' => $filterError,
            ];
        }

        $query = Loan::query()->with([
            'client',
            'agency',
            'loanProduct',
            'creditAgent',
            'amortizationAccount',
            'unpaidAccount',
            'recoveryAccount',
            'transferAccount',
            'sector',
            'subSector',
        ])->latest();

        $scopeError = $this->applyActorScope($query, $actor, $request);
        if ($scopeError instanceof JsonResponse) {
            return [
                'query' => Loan::query()->whereKey(0),
                'as_of_date' => now()->toDateString(),
                'include_arrears_fields' => false,
                'error' => $scopeError,
            ];
        }

        $this->applyStatusFilter($query, $request);
        $this->applySearchFilter($query, $request);
        $this->applyClientFilter($query, $request);

        $currency = $request->query('currency');
        if (is_string($currency) && $currency !== '') {
            $query->where('currency', strtoupper($currency));
        }

        $this->applyCreditAgentFilter($query, $actor, $request);

        $asOfDate = $this->asOfDate($request);
        $includeArrearsFields = $this->shouldIncludeArrearsFields($request);
        $parBucket = $this->parBucketFilter($request);

        if ($this->inArrearsFilterRequested($request) || $parBucket !== null) {
            $candidateIds = [];
            foreach ((clone $query)->pluck('id') as $id) {
                if (is_numeric($id)) {
                    $candidateIds[] = (int) $id;
                }
            }

            // Arrears uses the dashboard delinquency definition: only reportable
            // (live) loans qualify, so written-off/closed loans whose schedule
            // snapshot is still active cannot diverge from the operational
            // dashboard's delinquent count.
            $candidateIds = $this->dashboardMetrics->reportableLoanIdsWithin($candidateIds);
            $delinquentIds = $this->dashboardMetrics->delinquentLoanIdsWithin($candidateIds, $asOfDate);
            if ($parBucket !== null) {
                $projections = $this->delinquencyProjection->forLoanIds($delinquentIds, $asOfDate);
                $delinquentIds = array_values(array_filter(
                    $delinquentIds,
                    fn (int $loanId): bool => isset($projections[$loanId])
                        && $this->delinquencyProjection->matchesNonCumulativeParBucket(
                            $projections[$loanId]['days_in_arrears'],
                            $parBucket,
                        ),
                ));
            }

            $query->whereKey($delinquentIds === [] ? [0] : $delinquentIds);
            $includeArrearsFields = true;
        }

        if ($this->awaitingDisbursementFilterRequested($request)) {
            $candidateIds = [];
            foreach ((clone $query)->pluck('id') as $id) {
                if (is_numeric($id)) {
                    $candidateIds[] = (int) $id;
                }
            }
            $readyIds = $this->disbursementReadiness->awaitingDisbursementIdsWithin($candidateIds);
            $query->whereKey($readyIds === [] ? [0] : $readyIds);
        }

        return [
            'query' => $query,
            'as_of_date' => $asOfDate,
            'include_arrears_fields' => $includeArrearsFields,
            'error' => null,
        ];
    }

    /**
     * @param  Builder<Loan>  $query
     */
    public function applyActorScope(Builder $query, User $actor, Request $request): ?JsonResponse
    {
        if ($actor->hasRole('platform-admin')
            || (($actor->can('crm.scope.institution.read') || $actor->can('loans.scope.institution.read'))
                && ! $actor->hasRole('agency-manager'))) {
            $agencyPublicId = $request->query('agency_public_id');
            if (is_string($agencyPublicId) && $agencyPublicId !== '') {
                $agencyId = Agency::query()->where('public_id', $agencyPublicId)->value('id');
                $query->where('agency_id', is_int($agencyId) ? $agencyId : 0);
            }

            return null;
        }

        $agencyId = $this->staffAgencyScope->currentAgencyId($actor);
        if ($agencyId === null) {
            return $this->respondForbidden('Loan list requires an active agency assignment.');
        }

        $query->where('agency_id', $agencyId);

        if ($this->mustSelfScopeCreditAgent($actor)) {
            $query->where('credit_agent_id', $actor->id);
        }

        return null;
    }

    public function mustSelfScopeCreditAgent(User $actor): bool
    {
        return $actor->hasRole('loan-officer')
            && ! $actor->hasRole('platform-admin')
            && ! $actor->can('crm.scope.institution.read')
            && ! $actor->can('loans.scope.institution.read')
            && ! $actor->hasRole('agency-manager');
    }

    private function validateFilters(Request $request): ?JsonResponse
    {
        $filter = $request->query('filter');
        if (is_array($filter)) {
            $unknown = array_diff(array_keys($filter), self::ALLOWED_FILTER_KEYS);
            if ($unknown !== []) {
                return $this->respondUnprocessable(
                    message: 'Unsupported filter parameters.',
                    errors: ['filter' => [__('domain.unsupported_filter_keys', ['keys' => implode(', ', $unknown)])]],
                );
            }
        }

        $status = $this->statusFilterValue($request);
        if ($status !== null) {
            Validator::make(['status' => $status], [
                'status' => [Rule::in(self::LOAN_STATUS_KEYS)],
            ])->validate();
        }

        if ($this->invalidParBucketRequested($request)) {
            return $this->respondUnprocessable(errors: [
                'filter.par_bucket' => ['par_bucket must be one of: 30, 60, 90.'],
            ]);
        }

        $asOfDate = $request->query('filter.as_of_date') ?? $request->query('filter')['as_of_date'] ?? null;
        if ($asOfDate === null && is_array($filter) && array_key_exists('as_of_date', $filter)) {
            $asOfDate = $filter['as_of_date'];
        }
        if ($asOfDate !== null) {
            Validator::make(['as_of_date' => $asOfDate], [
                'as_of_date' => ['date'],
            ])->validate();
        }

        return null;
    }

    /**
     * @param  Builder<Loan>  $query
     */
    private function applyStatusFilter(Builder $query, Request $request): void
    {
        $status = $this->statusFilterValue($request);
        if ($status !== null) {
            $query->where('status', $status);
        }
    }

    /**
     * @param  Builder<Loan>  $query
     */
    private function applySearchFilter(Builder $query, Request $request): void
    {
        $search = $request->query('search');
        if (! is_string($search) || trim($search) === '') {
            return;
        }

        $term = trim($search);
        $query->where(function (Builder $builder) use ($term): void {
            $builder
                ->where('loan_number', 'ilike', '%'.$term.'%')
                ->orWhere('status', 'ilike', '%'.$term.'%')
                ->orWhere('purpose', 'ilike', '%'.$term.'%')
                ->orWhereHas('client', function (Builder $clientQuery) use ($term): void {
                    $clientQuery
                        ->where('client_reference', 'ilike', '%'.$term.'%')
                        ->orWhere('first_name', 'ilike', '%'.$term.'%')
                        ->orWhere('last_name', 'ilike', '%'.$term.'%')
                        ->orWhere('phone_number', 'ilike', '%'.$term.'%');
                });
        });
    }

    /**
     * @param  Builder<Loan>  $query
     */
    private function applyClientFilter(Builder $query, Request $request): void
    {
        $clientPublicId = $this->clientFilterValue($request);
        if ($clientPublicId === null) {
            return;
        }

        $clientId = Client::query()->where('public_id', $clientPublicId)->value('id');
        $query->where('client_id', is_int($clientId) ? $clientId : 0);
    }

    /**
     * @param  Builder<Loan>  $query
     */
    private function applyCreditAgentFilter(Builder $query, User $actor, Request $request): void
    {
        if ($this->mustSelfScopeCreditAgent($actor)) {
            $requestedPublicId = $this->creditAgentFilterValue($request);
            if ($requestedPublicId !== null && $requestedPublicId !== $actor->public_id) {
                $query->whereKey(0);
            }

            return;
        }

        $requestedPublicId = $this->creditAgentFilterValue($request);
        if ($requestedPublicId === null) {
            return;
        }

        $agent = User::query()->where('public_id', $requestedPublicId)->first();
        if (! $agent instanceof User || $agent->status !== User::STATUS_ACTIVE) {
            $query->whereKey(0);

            return;
        }

        if ($actor->hasRole('platform-admin')
            || (($actor->can('crm.scope.institution.read') || $actor->can('loans.scope.institution.read'))
                && ! $actor->hasRole('agency-manager'))) {
            $query->where('credit_agent_id', $agent->id);

            return;
        }

        $agencyId = $this->staffAgencyScope->currentAgencyId($actor);
        if ($agencyId === null || ! in_array($agent->id, $this->staffAgencyScope->currentAgencyStaffIdList($agencyId), true)) {
            $query->whereKey(0);

            return;
        }

        $query->where('credit_agent_id', $agent->id);
    }

    private function statusFilterValue(Request $request): ?string
    {
        $status = $request->query('status');
        $filter = $request->query('filter');
        if (($status === null || $status === '') && is_array($filter) && array_key_exists('status', $filter)) {
            $status = $filter['status'];
        }

        return is_string($status) && $status !== '' ? $status : null;
    }

    private function creditAgentFilterValue(Request $request): ?string
    {
        $filter = $request->query('filter');
        if (is_array($filter) && isset($filter['credit_agent_public_id']) && is_string($filter['credit_agent_public_id']) && $filter['credit_agent_public_id'] !== '') {
            return $filter['credit_agent_public_id'];
        }

        return null;
    }

    private function clientFilterValue(Request $request): ?string
    {
        $direct = $request->query('client_public_id');
        if (is_string($direct) && $direct !== '') {
            return $direct;
        }

        $filter = $request->query('filter');
        if (is_array($filter) && isset($filter['client_public_id']) && is_string($filter['client_public_id']) && $filter['client_public_id'] !== '') {
            return $filter['client_public_id'];
        }

        return null;
    }

    private function inArrearsFilterRequested(Request $request): bool
    {
        $raw = $request->query('in_arrears');
        $filter = $request->query('filter');
        if ($raw === null && is_array($filter) && array_key_exists('in_arrears', $filter)) {
            $raw = $filter['in_arrears'];
        }

        if (is_bool($raw)) {
            return $raw;
        }

        return is_string($raw) && in_array(strtolower($raw), ['1', 'true', 'yes', 'on'], true);
    }

    private function awaitingDisbursementFilterRequested(Request $request): bool
    {
        $filter = $request->query('filter');
        if (! is_array($filter) || ! array_key_exists('awaiting_disbursement', $filter)) {
            return false;
        }

        $raw = $filter['awaiting_disbursement'];

        if (is_bool($raw)) {
            return $raw;
        }

        return is_string($raw) && in_array(strtolower($raw), ['1', 'true', 'yes', 'on'], true);
    }

    private function parBucketFilter(Request $request): ?int
    {
        $filter = $request->query('filter');
        if (! is_array($filter) || ! array_key_exists('par_bucket', $filter)) {
            return null;
        }

        $raw = $filter['par_bucket'];
        if (! is_string($raw) && ! is_int($raw)) {
            return null;
        }

        $bucket = (int) $raw;
        if (! in_array($bucket, [30, 60, 90], true)) {
            return null;
        }

        return $bucket;
    }

    private function invalidParBucketRequested(Request $request): bool
    {
        $filter = $request->query('filter');
        if (! is_array($filter) || ! array_key_exists('par_bucket', $filter)) {
            return false;
        }

        $raw = $filter['par_bucket'];
        if (! is_string($raw) && ! is_int($raw)) {
            return true;
        }

        return ! in_array((int) $raw, [30, 60, 90], true);
    }

    private function asOfDate(Request $request): string
    {
        $filter = $request->query('filter');
        $raw = null;
        if (is_array($filter) && array_key_exists('as_of_date', $filter)) {
            $raw = $filter['as_of_date'];
        }

        return is_string($raw) && $raw !== '' ? $raw : now()->toDateString();
    }

    private function shouldIncludeArrearsFields(Request $request): bool
    {
        if ($this->inArrearsFilterRequested($request) || $this->parBucketFilter($request) !== null) {
            return true;
        }

        return $request->boolean('include_arrears_fields');
    }
}
