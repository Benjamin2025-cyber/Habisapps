<?php

declare(strict_types=1);

namespace App\Application\CashOperations;

use App\Http\Controllers\BaseController;
use App\Http\Resources\TellerTransactionCollection;
use App\Models\Loan;
use App\Models\TellerTransaction;
use App\Models\User;
use App\Support\Staff\StaffAgencyScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read model for the teller transaction list (FBI2-024). Applies agency/teller
 * scope, exact filters, and search before paginating.
 */
final class TellerTransactionWorkflow extends BaseController
{
    /** @var array<int, string> */
    private const array ALLOWED_FILTERS = [
        'teller_session_public_id',
        'till_public_id',
        'teller_user_public_id',
        'transaction_type',
        'status',
        'transaction_date',
        'transaction_date_from',
        'transaction_date_to',
        'customer_account_public_id',
        'loan_public_id',
    ];

    public function __construct(
        private readonly StaffAgencyScope $staffAgencyScope,
    ) {}

    public function index(Request $request): TellerTransactionCollection|JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('viewAny', TellerTransaction::class)) {
            return $this->respondForbidden();
        }

        $query = TellerTransaction::query()->with([
            'tellerSession',
            'till',
            'customerAccount',
            'journalEntry',
            'initiatorProxy',
            'customerAccountSignature',
            'signatureCheckedBy',
            'tenders',
        ])->latest();

        $scopeError = $this->applyAgencyScope($query, $actor);
        if ($scopeError instanceof JsonResponse) {
            return $scopeError;
        }

        $filterError = $this->applyFilters($query, $request);
        if ($filterError instanceof JsonResponse) {
            return $filterError;
        }

        $this->applySearch($query, $request);

        $perPage = min(max($request->integer('per_page', 25), 1), 100);

        return new TellerTransactionCollection($query->paginate($perPage));
    }

    /**
     * @param  Builder<TellerTransaction>  $query
     */
    private function applyAgencyScope(Builder $query, User $actor): ?JsonResponse
    {
        if ($actor->hasRole('platform-admin')) {
            return null;
        }

        $agencyId = $this->staffAgencyScope->currentAgencyId($actor);
        if ($agencyId === null) {
            return $this->respondForbidden(
                'A current agency assignment is required to list teller transactions.'
            );
        }

        $query->where('agency_id', $agencyId);

        // A plain teller only sees transactions of their own sessions; agency
        // managers (and broader agency-scoped cash viewers) see the whole agency.
        if ($actor->hasRole('teller') && ! $actor->hasRole('agency-manager')) {
            $query->whereHas('tellerSession', static function (Builder $builder) use ($actor): void {
                $builder->where('teller_user_id', $actor->id);
            });
        }

        return null;
    }

    /**
     * @param  Builder<TellerTransaction>  $query
     */
    private function applyFilters(Builder $query, Request $request): ?JsonResponse
    {
        $filter = $request->query('filter');
        if (! is_array($filter)) {
            return null;
        }

        $unknown = array_diff(array_keys($filter), self::ALLOWED_FILTERS);
        if ($unknown !== []) {
            return $this->respondUnprocessable(
                message: 'Unsupported filter parameters.',
                errors: ['filter' => [__('domain.unsupported_filter_keys', ['keys' => implode(', ', $unknown)])]]
            );
        }

        $this->filterByRelationPublicId($query, $filter, 'teller_session_public_id', 'tellerSession');
        $this->filterByRelationPublicId($query, $filter, 'till_public_id', 'till');
        $this->filterByRelationPublicId($query, $filter, 'customer_account_public_id', 'customerAccount');

        $tellerUserPublicId = $filter['teller_user_public_id'] ?? null;
        if (is_string($tellerUserPublicId) && $tellerUserPublicId !== '') {
            $query->whereHas('tellerSession', static function (Builder $builder) use ($tellerUserPublicId): void {
                $builder->whereHas('teller', static function (Builder $tellerBuilder) use ($tellerUserPublicId): void {
                    $tellerBuilder->where('public_id', $tellerUserPublicId);
                });
            });
        }

        $transactionType = $filter['transaction_type'] ?? null;
        if (is_string($transactionType) && $transactionType !== '') {
            $query->where('transaction_type', $transactionType);
        }

        $status = $filter['status'] ?? null;
        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        }

        // transaction_date is a DATE column, so direct comparisons are exact.
        $transactionDate = $filter['transaction_date'] ?? null;
        if (is_string($transactionDate) && $transactionDate !== '') {
            $query->where('transaction_date', $transactionDate);
        }

        $dateFrom = $filter['transaction_date_from'] ?? null;
        if (is_string($dateFrom) && $dateFrom !== '') {
            $query->where('transaction_date', '>=', $dateFrom);
        }

        $dateTo = $filter['transaction_date_to'] ?? null;
        if (is_string($dateTo) && $dateTo !== '') {
            $query->where('transaction_date', '<=', $dateTo);
        }

        $loanPublicId = $filter['loan_public_id'] ?? null;
        if (is_string($loanPublicId) && $loanPublicId !== '') {
            $loanId = Loan::query()->where('public_id', $loanPublicId)->value('id');
            $query->where('loan_id', is_numeric($loanId) ? (int) $loanId : 0);
        }

        return null;
    }

    /**
     * @param  Builder<TellerTransaction>  $query
     * @param  array<string, mixed>  $filter
     */
    private function filterByRelationPublicId(Builder $query, array $filter, string $key, string $relation): void
    {
        $value = $filter[$key] ?? null;
        if (! is_string($value) || $value === '') {
            return;
        }

        $query->whereHas($relation, static function (Builder $builder) use ($value): void {
            $builder->where('public_id', $value);
        });
    }

    /**
     * @param  Builder<TellerTransaction>  $query
     */
    private function applySearch(Builder $query, Request $request): void
    {
        $search = $request->query('search');
        if (! is_string($search) || trim($search) === '') {
            return;
        }

        $term = trim($search);
        $query->where(static function (Builder $builder) use ($term): void {
            $builder->where('reference', 'ilike', '%'.$term.'%')
                ->orWhere('event_number', 'ilike', '%'.$term.'%')
                ->orWhere('operation_code', 'ilike', '%'.$term.'%')
                ->orWhere('depositor_name', 'ilike', '%'.$term.'%')
                ->orWhere('description', 'ilike', '%'.$term.'%');
        });
    }
}
