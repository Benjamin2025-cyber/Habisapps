<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Notifications\UserNotificationFeed;
use App\Http\Controllers\BaseController;
use App\Http\Requests\StoreTillReconciliationRequest;
use App\Http\Resources\TillReconciliationResource;
use App\Models\Agency;
use App\Models\Denomination;
use App\Models\JournalEntry;
use App\Models\TellerSession;
use App\Models\TellerTransaction;
use App\Models\Till;
use App\Models\TillReconciliation;
use App\Models\TillReconciliationLine;
use App\Models\User;
use App\Support\Security\SecurityAudit;
use App\Support\Staff\StaffAgencyScope;
use Closure;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class TillReconciliationController extends BaseController
{
    /** @var array<int, string> */
    private const array ALLOWED_FILTERS = [
        'teller_session_public_id',
        'till_public_id',
        'teller_user_public_id',
        'business_date',
        'business_date_from',
        'business_date_to',
        'status',
        'agency_public_id',
    ];

    public function __construct(
        private readonly SecurityAudit $securityAudit,
        private readonly StaffAgencyScope $staffAgencyScope,
        private readonly UserNotificationFeed $notifications,
    ) {}

    #[QueryParameter('filter[teller_session_public_id]', 'Limit results to a teller session public ID.', type: 'string')]
    #[QueryParameter('filter[till_public_id]', 'Limit results to a till public ID.', type: 'string')]
    #[QueryParameter('filter[teller_user_public_id]', 'Limit results to a teller user public ID.', type: 'string')]
    #[QueryParameter('filter[business_date]', 'Limit results to an exact teller-session business date in YYYY-MM-DD format.', type: 'string', format: 'date')]
    #[QueryParameter('filter[business_date_from]', 'Limit results to teller sessions on or after this business date in YYYY-MM-DD format.', type: 'string', format: 'date')]
    #[QueryParameter('filter[business_date_to]', 'Limit results to teller sessions on or before this business date in YYYY-MM-DD format.', type: 'string', format: 'date')]
    #[QueryParameter('filter[status]', 'Limit results to a reconciliation status.', type: 'string')]
    #[QueryParameter('filter[agency_public_id]', 'Platform-admin only. Limit results to reconciliations for an agency public ID.', type: 'string')]
    #[QueryParameter('search', 'Search reconciliation status, currency, notes, and counted-by user.', type: 'string')]
    #[QueryParameter('per_page', 'Results per page. Capped at 100.', type: 'integer')]
    #[Response(status: 200, type: 'array{success: bool, message: string, data: array{till_reconciliations: array<int, \App\Http\Resources\TillReconciliationResource>}, errors: null, meta: array{pagination: array{current_page: int, per_page: int, total: int, last_page: int}}}')]
    public function index(Request $request, ?TellerSession $tellerSession = null): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('viewAny', TillReconciliation::class)) {
            return $this->respondForbidden();
        }

        if ($tellerSession instanceof TellerSession && ! $this->canAccessSession($actor, $tellerSession)) {
            return $this->respondForbidden();
        }

        $query = TillReconciliation::query()
            ->with(['tellerSession.till', 'tellerSession.teller', 'countedBy', 'lines.denomination']);

        if ($tellerSession instanceof TellerSession) {
            $query->where('teller_session_id', $tellerSession->id);
        }

        $scopeError = $this->applyAgencyScope($query, $actor, $request);
        if ($scopeError instanceof JsonResponse) {
            return $scopeError;
        }

        $filterError = $this->applyFilters($query, $request, $tellerSession);
        if ($filterError instanceof JsonResponse) {
            return $filterError;
        }

        $search = $request->query('search');
        if (is_string($search) && trim($search) !== '') {
            $term = trim($search);
            $query->where(static function (Builder $builder) use ($term): void {
                $builder->where('status', 'ilike', '%'.$term.'%')
                    ->orWhere('currency', 'ilike', '%'.$term.'%')
                    ->orWhere('notes', 'ilike', '%'.$term.'%')
                    ->orWhereHas('countedBy', static function (Builder $userBuilder) use ($term): void {
                        $userBuilder->where('name', 'ilike', '%'.$term.'%')
                            ->orWhere('email', 'ilike', '%'.$term.'%');
                    });
            });
        }

        $perPage = min(max($request->integer('per_page', 25), 1), 100);
        $items = $query->latest()->paginate($perPage);

        return $this->respondSuccess([
            'till_reconciliations' => TillReconciliationResource::collection($items->getCollection()),
        ], meta: [
            'pagination' => [
                'current_page' => $items->currentPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
                'last_page' => $items->lastPage(),
            ],
        ]);
    }

    #[Response(status: 201, type: 'array{success: bool, message: string, data: array{till_reconciliation: \App\Http\Resources\TillReconciliationResource}, errors: null, meta: null}')]
    public function store(StoreTillReconciliationRequest $request, TellerSession $tellerSession): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $this->canAccessSession($actor, $tellerSession)) {
            return $this->respondForbidden();
        }

        $tellerSession->loadMissing(['till']);
        $till = $tellerSession->till;
        if (! $till instanceof Till) {
            return $this->respondUnprocessable(errors: ['till' => ['The teller session must be linked to a valid till.']]);
        }

        if ($this->hasPendingTransactions($tellerSession->id)) {
            $this->notifications->notifyAgency(
                agencyId: $tellerSession->agency_id,
                type: 'warning',
                category: 'till_reconciliation_pending',
                title: 'Till reconciliation pending',
                message: 'A till reconciliation is pending because teller transactions still need review.',
                sourceType: TellerSession::class,
                sourcePublicId: $tellerSession->public_id,
                actionUrl: '/teller-sessions/'.$tellerSession->public_id.'/reconciliations',
                metadata: [
                    'teller_session_public_id' => $tellerSession->public_id,
                ],
            );

            return $this->respondUnprocessable(errors: ['transactions' => ['Pending teller transactions must be posted or cancelled before reconciliation.']]);
        }

        $currency = $this->normalizedCurrency($request->input('currency', $tellerSession->currency ?? $till->currency));
        if ($currency !== $tellerSession->currency) {
            return $this->respondUnprocessable(errors: ['currency' => ['Reconciliation currency must match the teller session currency.']]);
        }

        $counts = $this->validatedDenominationCounts($request->input('denomination_counts'), $currency);
        if ($counts['errors'] !== []) {
            return $this->respondUnprocessable(errors: $counts['errors']);
        }

        $actualBalanceMinor = array_sum(array_column($counts['lines'], 'declared_amount_minor'));
        $theoreticalBalanceMinor = $this->theoreticalBalanceMinor($tellerSession, $till);
        $differenceMinor = $actualBalanceMinor - $theoreticalBalanceMinor;
        if ($differenceMinor !== 0) {
            $this->notifications->notifyAgency(
                agencyId: $tellerSession->agency_id,
                type: 'error',
                category: 'till_reconciliation_rejected',
                title: 'Till reconciliation rejected',
                message: 'A till reconciliation was rejected because the cash difference was not zero.',
                sourceType: TellerSession::class,
                sourcePublicId: $tellerSession->public_id,
                actionUrl: '/teller-sessions/'.$tellerSession->public_id.'/reconciliations',
                metadata: [
                    'teller_session_public_id' => $tellerSession->public_id,
                    'actual_balance_minor' => $actualBalanceMinor,
                    'theoretical_balance_minor' => $theoreticalBalanceMinor,
                    'difference_minor' => $differenceMinor,
                    'currency' => $currency,
                ],
            );

            return $this->respondUnprocessable(errors: ['difference_minor' => ['Reconciliation difference must be zero before it can be recorded.']]);
        }

        $reconciliation = DB::transaction(function () use ($request, $actor, $tellerSession, $currency, $counts, $actualBalanceMinor, $theoreticalBalanceMinor, $differenceMinor): TillReconciliation {
            $record = TillReconciliation::query()->create([
                'public_id' => (string) Str::ulid(),
                'teller_session_id' => $tellerSession->id,
                'counted_by_user_id' => $actor->id,
                'counted_at' => now(),
                'reconciliation_date' => now(),
                'theoretical_balance_minor' => $theoreticalBalanceMinor,
                'actual_balance_minor' => $actualBalanceMinor,
                'difference_minor' => $differenceMinor,
                'currency' => $currency,
                'status' => TillReconciliation::STATUS_BALANCED,
                'notes' => $request->input('notes'),
            ]);

            foreach ($counts['lines'] as $line) {
                TillReconciliationLine::query()->create([
                    'till_reconciliation_id' => $record->id,
                    'denomination_id' => $line['denomination_id'],
                    'count' => $line['count'],
                    'declared_amount_minor' => $line['declared_amount_minor'],
                ]);
            }

            return $record;
        });

        $this->securityAudit->record('cash.till_reconciliation.balanced', actor: $actor, subject: $reconciliation, properties: [
            'teller_session_public_id' => $tellerSession->public_id,
            'actual_balance_minor' => $actualBalanceMinor,
            'theoretical_balance_minor' => $theoreticalBalanceMinor,
            'currency' => $currency,
        ], request: $request);
        $this->notifications->notifyAgency(
            agencyId: $tellerSession->agency_id,
            type: 'success',
            category: 'till_reconciliation_accepted',
            title: 'Till reconciliation accepted',
            message: 'A till reconciliation was accepted with zero difference.',
            sourceType: TillReconciliation::class,
            sourcePublicId: $reconciliation->public_id,
            actionUrl: '/till-reconciliations?filter[teller_session_public_id]='.$tellerSession->public_id,
            metadata: [
                'teller_session_public_id' => $tellerSession->public_id,
                'actual_balance_minor' => $actualBalanceMinor,
                'theoretical_balance_minor' => $theoreticalBalanceMinor,
                'currency' => $currency,
            ],
        );

        return $this->respondCreated(
            TillReconciliationResource::make($reconciliation->loadMissing(['tellerSession', 'countedBy', 'lines.denomination'])),
            'Till reconciliation recorded successfully'
        );
    }

    private function canAccessSession(User $actor, TellerSession $session): bool
    {
        if ($actor->hasRole('platform-admin')) {
            return true;
        }

        return $this->staffAgencyScope->currentAgencyId($actor) === $session->agency_id;
    }

    /**
     * @param  Builder<TillReconciliation>  $query
     */
    private function applyAgencyScope(Builder $query, User $actor, Request $request): ?JsonResponse
    {
        $requestedAgencyPublicId = $this->filterString($request, 'agency_public_id');
        if ($actor->hasRole('platform-admin')) {
            if ($requestedAgencyPublicId !== null) {
                $agencyId = Agency::query()->where('public_id', $requestedAgencyPublicId)->value('id');
                if (! is_numeric($agencyId)) {
                    return $this->respondUnprocessable(errors: ['filter.agency_public_id' => ['The selected agency is invalid.']]);
                }

                $this->whereSession($query, static function (Builder $builder) use ($agencyId): void {
                    $builder->where('agency_id', (int) $agencyId);
                });
            }

            return null;
        }

        $agencyId = $this->staffAgencyScope->currentAgencyId($actor);
        if ($agencyId === null) {
            return $this->respondForbidden();
        }

        if ($requestedAgencyPublicId !== null) {
            $requestedAgencyId = Agency::query()->where('public_id', $requestedAgencyPublicId)->value('id');
            if (! is_numeric($requestedAgencyId)) {
                return $this->respondUnprocessable(errors: ['filter.agency_public_id' => ['The selected agency is invalid.']]);
            }

            if ((int) $requestedAgencyId !== $agencyId) {
                return $this->respondForbidden('You cannot query till reconciliations outside your current agency.');
            }
        }

        $this->whereSession($query, static function (Builder $builder) use ($agencyId): void {
            $builder->where('agency_id', $agencyId);
        });

        return null;
    }

    /**
     * @param  Builder<TillReconciliation>  $query
     */
    private function applyFilters(Builder $query, Request $request, ?TellerSession $nestedSession): ?JsonResponse
    {
        $filter = $request->query('filter');
        if (! is_array($filter)) {
            return null;
        }

        $unknown = array_diff(array_keys($filter), self::ALLOWED_FILTERS);
        if ($unknown !== []) {
            return $this->respondUnprocessable(
                message: 'Unsupported filter parameters.',
                errors: ['filter' => ['The following filter keys are not supported: '.implode(', ', $unknown)]]
            );
        }

        $sessionPublicId = $this->filterString($request, 'teller_session_public_id');
        if ($sessionPublicId !== null) {
            if ($nestedSession instanceof TellerSession && $nestedSession->public_id !== $sessionPublicId) {
                $query->where('id', -1);
            } else {
                $this->whereSession($query, static function (Builder $builder) use ($sessionPublicId): void {
                    $builder->where('public_id', $sessionPublicId);
                });
            }
        }

        $tillPublicId = $this->filterString($request, 'till_public_id');
        if ($tillPublicId !== null) {
            $this->whereSession($query, static function (Builder $builder) use ($tillPublicId): void {
                $builder->whereHas('till', static function (Builder $tillBuilder) use ($tillPublicId): void {
                    $tillBuilder->where('public_id', $tillPublicId);
                });
            });
        }

        $tellerPublicId = $this->filterString($request, 'teller_user_public_id');
        if ($tellerPublicId !== null) {
            $this->whereSession($query, static function (Builder $builder) use ($tellerPublicId): void {
                $builder->whereHas('teller', static function (Builder $tellerBuilder) use ($tellerPublicId): void {
                    $tellerBuilder->where('public_id', $tellerPublicId);
                });
            });
        }

        foreach ([
            'business_date' => '=',
            'business_date_from' => '>=',
            'business_date_to' => '<=',
        ] as $key => $operator) {
            $value = $this->filterString($request, $key);
            if ($value !== null) {
                $this->whereSession($query, static function (Builder $builder) use ($operator, $value): void {
                    $builder->where('business_date', $operator, $value);
                });
            }
        }

        $status = $this->filterString($request, 'status');
        if ($status !== null) {
            $query->where('status', $status);
        }

        return null;
    }

    /**
     * @param  Builder<TillReconciliation>  $query
     */
    private function whereSession(Builder $query, Closure $callback): void
    {
        $query->whereHas('tellerSession', $callback);
    }

    private function filterString(Request $request, string $key): ?string
    {
        $filter = $request->query('filter');
        if (! is_array($filter)) {
            return null;
        }

        $value = $filter[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function hasPendingTransactions(int $tellerSessionId): bool
    {
        return DB::table('teller_transactions')
            ->where('teller_session_id', $tellerSessionId)
            ->whereNotIn('status', [TellerTransaction::STATUS_POSTED, TellerTransaction::STATUS_REVERSED, TellerTransaction::STATUS_CANCELLED])
            ->first(['id']) !== null;
    }

    private function theoreticalBalanceMinor(TellerSession $session, Till $till): int
    {
        $opening = $session->opening_declaration_minor ?? 0;
        $transactions = DB::table('teller_transactions')
            ->where('teller_session_id', $session->id)
            ->where('status', TellerTransaction::STATUS_POSTED)
            ->get(['id', 'transaction_type', 'amount_minor', 'journal_entry_id']);

        $movement = 0;
        foreach ($transactions as $transaction) {
            $type = is_string($transaction->transaction_type) ? $transaction->transaction_type : '';
            $amount = is_numeric($transaction->amount_minor) ? (int) $transaction->amount_minor : 0;

            if ($type === TellerTransaction::TYPE_CASH_DEPOSIT) {
                $movement += $amount;

                continue;
            }

            if ($type === TellerTransaction::TYPE_CASH_WITHDRAWAL) {
                $movement -= $amount;

                continue;
            }

            if ($type === TellerTransaction::TYPE_MANUAL_JOURNAL && is_numeric($transaction->journal_entry_id)) {
                $movement += $this->journalCashMovement((int) $transaction->journal_entry_id, $till->ledger_account_id);
            }
        }

        return $opening + $movement;
    }

    private function journalCashMovement(int $journalEntryId, ?int $tillLedgerAccountId): int
    {
        if ($tillLedgerAccountId === null) {
            return 0;
        }

        $line = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.status', JournalEntry::STATUS_POSTED)
            ->where('journal_lines.journal_entry_id', $journalEntryId)
            ->where('journal_lines.ledger_account_id', $tillLedgerAccountId)
            ->selectRaw('COALESCE(SUM(journal_lines.debit_minor - journal_lines.credit_minor), 0) AS movement_minor')
            ->first();

        return is_object($line) && is_numeric($line->movement_minor) ? (int) $line->movement_minor : 0;
    }

    /**
     * @return array{errors: array<string, array<int, string>>, lines: array<int, array{denomination_id:int, denomination_public_id:string, count:int, declared_amount_minor:int}>}
     */
    private function validatedDenominationCounts(mixed $rawCounts, string $currency): array
    {
        if (! is_array($rawCounts)) {
            return ['errors' => ['denomination_counts' => ['Denomination counts are required.']], 'lines' => []];
        }

        $seen = [];
        $lines = [];
        foreach ($rawCounts as $index => $line) {
            if (! is_array($line)) {
                return ['errors' => ['denomination_counts.'.$index => ['Each denomination count must be an object.']], 'lines' => []];
            }

            $publicId = $line['denomination_public_id'] ?? null;
            $count = $line['count'] ?? null;
            if (! is_string($publicId) || ! is_int($count)) {
                return ['errors' => ['denomination_counts.'.$index => ['Each denomination count must include a denomination and integer count.']], 'lines' => []];
            }

            if (array_key_exists($publicId, $seen)) {
                return ['errors' => ['denomination_counts' => ['Duplicate denominations are not allowed.']], 'lines' => []];
            }
            $seen[$publicId] = true;

            $denomination = Denomination::query()->where('public_id', $publicId)->first();
            if (! $denomination instanceof Denomination || $denomination->status !== Denomination::STATUS_ACTIVE || $denomination->currency !== $currency) {
                return ['errors' => ['denomination_counts.'.$index.'.denomination_public_id' => ['The selected denomination must be active and match the reconciliation currency.']], 'lines' => []];
            }

            $lines[] = [
                'denomination_id' => $denomination->id,
                'denomination_public_id' => $denomination->public_id,
                'count' => $count,
                'declared_amount_minor' => $denomination->value_minor * $count,
            ];
        }

        return ['errors' => [], 'lines' => $lines];
    }

    private function normalizedCurrency(mixed $currency): string
    {
        return is_string($currency) && $currency !== '' ? strtoupper($currency) : 'XAF';
    }
}
