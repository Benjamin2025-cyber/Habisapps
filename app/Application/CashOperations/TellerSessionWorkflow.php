<?php

declare(strict_types=1);

namespace App\Application\CashOperations;

use App\Application\Notifications\UserNotificationFeed;
use App\Http\Controllers\BaseController;
use App\Http\Requests\CloseTellerSessionRequest;
use App\Http\Requests\StoreTellerSessionRequest;
use App\Http\Resources\TellerSessionCollection;
use App\Http\Resources\TellerSessionResource;
use App\Models\Agency;
use App\Models\Denomination;
use App\Models\StaffAgencyAssignment;
use App\Models\TellerSession;
use App\Models\Till;
use App\Models\User;
use App\Support\AccountingDay\AccountingDayGuard;
use App\Support\Security\SecurityAudit;
use App\Support\Staff\StaffAgencyScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class TellerSessionWorkflow extends BaseController
{
    /** @var array<int, string> */
    private const array ALLOWED_FILTERS = [
        'business_date',
        'business_date_from',
        'business_date_to',
        'till_public_id',
        'teller_user_public_id',
        'status',
        'agency_public_id',
    ];

    /** @var array<string, array{0: string, 1: 'asc'|'desc'}> */
    private const array ALLOWED_SORTS = [
        'business_date' => ['business_date', 'asc'],
        '-business_date' => ['business_date', 'desc'],
        'opened_at' => ['opened_at', 'asc'],
        '-opened_at' => ['opened_at', 'desc'],
        'closed_at' => ['closed_at', 'asc'],
        '-closed_at' => ['closed_at', 'desc'],
        'status' => ['status', 'asc'],
    ];

    public function __construct(
        private readonly SecurityAudit $securityAudit,
        private readonly StaffAgencyScope $staffAgencyScope,
        private readonly AccountingDayGuard $accountingDayGuard,
        private readonly TellerSessionSummary $summary,
        private readonly UserNotificationFeed $notifications,
    ) {}

    public function index(Request $request): TellerSessionCollection|JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('viewAny', TellerSession::class)) {
            return $this->respondForbidden();
        }

        $query = TellerSession::query()->with(['agency', 'till', 'teller']);

        $scopeError = $this->applyAgencyScope($query, $actor, $request);
        if ($scopeError instanceof JsonResponse) {
            return $scopeError;
        }

        $filterError = $this->applyFilters($query, $request);
        if ($filterError instanceof JsonResponse) {
            return $filterError;
        }

        $search = $request->query('search');
        if (is_string($search) && trim($search) !== '') {
            $term = trim($search);
            $query->where(static function (Builder $builder) use ($term): void {
                $builder->where('status', 'ilike', '%'.$term.'%')
                    ->orWhere('business_date', 'ilike', '%'.$term.'%')
                    ->orWhere('currency', 'ilike', '%'.$term.'%')
                    ->orWhereHas('agency', static function (Builder $agencyBuilder) use ($term): void {
                        $agencyBuilder->where('code', 'ilike', '%'.$term.'%')
                            ->orWhere('name', 'ilike', '%'.$term.'%');
                    })
                    ->orWhereHas('till', static function (Builder $tillBuilder) use ($term): void {
                        $tillBuilder->where('code', 'ilike', '%'.$term.'%')
                            ->orWhere('name', 'ilike', '%'.$term.'%');
                    })
                    ->orWhereHas('teller', static function (Builder $tellerBuilder) use ($term): void {
                        $tellerBuilder->where('name', 'ilike', '%'.$term.'%')
                            ->orWhere('email', 'ilike', '%'.$term.'%');
                    });
            });
        }

        $sortError = $this->applySort($query, $request);
        if ($sortError instanceof JsonResponse) {
            return $sortError;
        }

        $paginator = $query->paginate(min(max($request->integer('per_page', 25), 1), 100));
        $this->summary->attach($paginator->getCollection());

        return new TellerSessionCollection($paginator);
    }

    public function store(StoreTellerSessionRequest $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        $till = Till::query()
            ->with(['agency'])
            ->where('public_id', $request->string('till_public_id')->toString())
            ->first();
        if (! $till instanceof Till || ! $till->agency instanceof Agency) {
            return $this->respondUnprocessable(errors: ['till_public_id' => [__('The selected till is invalid.')]]);
        }

        if (! $this->canAccessAgency($actor, $till->agency_id)) {
            return $this->respondForbidden('Teller session can only be opened inside your agency scope.');
        }

        if ($till->status !== Till::STATUS_ACTIVE || $till->daily_state !== Till::DAILY_STATE_CLOSED) {
            return $this->respondUnprocessable(errors: ['till_public_id' => [__('The selected till must be active and closed before opening a teller session.')]]);
        }

        $teller = $this->resolveTeller($request, $actor);
        if (! $teller instanceof User || ! $this->canBeAssignedToTill($teller, $till->agency_id)) {
            return $this->respondUnprocessable(errors: ['teller_user_public_id' => [__('The selected teller must be an active teller or cashier in the till agency.')]]);
        }

        if ($till->assigned_user_id !== null && $till->assigned_user_id !== $teller->id) {
            return $this->respondUnprocessable(errors: ['teller_user_public_id' => [__('The selected teller is not assigned to this till.')]]);
        }

        if ($this->hasOpenSessionForTill($till->id) || $this->hasOpenSessionForTeller($teller->id)) {
            return $this->respondUnprocessable(errors: ['session' => [__('The till or teller already has an open session.')]]);
        }

        $openingDeclarationMinor = $request->integer('opening_declaration_minor');
        $currency = $this->normalizedCurrency($request->input('currency', $till->currency));
        $denominationCounts = $this->validatedDenominationCounts(
            $request->input('denomination_counts'),
            $currency,
            $openingDeclarationMinor,
            $till->requires_denominations
        );
        if ($denominationCounts['errors'] !== []) {
            return $this->respondUnprocessable(errors: $denominationCounts['errors']);
        }

        // The open accounting day for the till's agency governs the session date.
        $requestedDate = $request->input('business_date');
        $accountingDay = $this->accountingDayGuard->resolveAccountingDay(
            $actor,
            'cash.session.open',
            $till->agency_id,
            is_string($requestedDate) ? $requestedDate : null,
            $request,
        );
        $businessDate = $accountingDay->business_date->toDateString();

        $session = DB::transaction(function () use ($till, $teller, $openingDeclarationMinor, $currency, $businessDate, $accountingDay): TellerSession {
            $session = TellerSession::query()->create([
                'public_id' => (string) Str::ulid(),
                'till_id' => $till->id,
                'agency_id' => $till->agency_id,
                'accounting_day_id' => $accountingDay->id,
                'teller_user_id' => $teller->id,
                'business_date' => $businessDate,
                'opened_at' => now(),
                'opening_declaration_minor' => $openingDeclarationMinor,
                'currency' => $currency,
                'status' => TellerSession::STATUS_OPEN,
            ]);

            $till->fill([
                'daily_state' => Till::DAILY_STATE_OPEN,
                'opening_balance_minor' => $openingDeclarationMinor,
                'currency' => $currency,
            ])->save();

            return $session;
        });

        $this->securityAudit->record('cash.teller_session.opened', actor: $actor, subject: $session, properties: [
            'till_public_id' => $till->public_id,
            'teller_user_public_id' => $teller->public_id,
            'opening_declaration_minor' => $openingDeclarationMinor,
            'currency' => $currency,
            'denomination_counts' => $denominationCounts['lines'],
        ], request: $request);
        $this->notifications->notifyAgency(
            agencyId: $session->agency_id,
            type: 'success',
            category: 'cash_session_opened',
            title: 'Teller session opened',
            message: 'A teller session was opened for '.$teller->name.'.',
            sourceType: TellerSession::class,
            sourcePublicId: $session->public_id,
            actionUrl: '/teller-sessions/'.$session->public_id,
            metadata: [
                'till_public_id' => $till->public_id,
                'teller_user_public_id' => $teller->public_id,
            ],
        );

        $session->loadMissing(['agency', 'accountingDay', 'till', 'teller']);
        $this->summary->attach([$session]);

        return $this->respondCreated(
            TellerSessionResource::make($session),
            'Teller session opened successfully'
        );
    }

    public function show(Request $request, TellerSession $tellerSession): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('view', $tellerSession)) {
            return $this->respondForbidden();
        }

        $tellerSession->loadMissing(['agency', 'accountingDay', 'till', 'teller']);
        $this->summary->attach([$tellerSession]);

        return $this->respondSuccess(TellerSessionResource::make($tellerSession));
    }

    public function close(CloseTellerSessionRequest $request, TellerSession $tellerSession): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        $tellerSession->loadMissing(['till']);
        $till = $tellerSession->till;
        if (! $till instanceof Till) {
            return $this->respondUnprocessable(errors: ['till' => [__('The teller session must be linked to a valid till.')]]);
        }

        if ($actor->hasRole('teller') && $actor->id !== $tellerSession->teller_user_id) {
            return $this->respondForbidden('A teller can only close their own teller session.');
        }

        if ($tellerSession->status !== TellerSession::STATUS_OPEN) {
            return $this->respondUnprocessable(errors: ['status' => [__('Only open teller sessions can be closed.')]]);
        }

        if ($this->hasPendingTransactions($tellerSession->id)) {
            return $this->respondUnprocessable(errors: ['transactions' => [__('Pending teller transactions must be posted or cancelled before closing the session.')]]);
        }

        $closingDeclarationMinor = $request->integer('closing_declaration_minor');
        $currency = $this->normalizedCurrency($request->input('currency', $tellerSession->currency ?? $till->currency));
        $denominationCounts = $this->validatedDenominationCounts(
            $request->input('denomination_counts'),
            $currency,
            $closingDeclarationMinor,
            $till->requires_denominations
        );
        if ($denominationCounts['errors'] !== []) {
            return $this->respondUnprocessable(errors: $denominationCounts['errors']);
        }

        $theoreticalBalanceMinor = $this->theoreticalBalanceMinor($tellerSession);
        if ($closingDeclarationMinor !== $theoreticalBalanceMinor) {
            return $this->respondUnprocessable(errors: ['closing_declaration_minor' => [__('Closing declaration must equal the posted theoretical till balance.')]]);
        }

        DB::transaction(function () use ($tellerSession, $till, $closingDeclarationMinor, $currency): void {
            $tellerSession->fill([
                'closed_at' => now(),
                'closing_declaration_minor' => $closingDeclarationMinor,
                'currency' => $currency,
                'status' => TellerSession::STATUS_CLOSED,
            ])->save();

            $till->fill([
                'daily_state' => Till::DAILY_STATE_CLOSED,
                'last_closing_balance_minor' => $closingDeclarationMinor,
                'last_closing_at' => now(),
                'currency' => $currency,
            ])->save();
        });

        $this->securityAudit->record('cash.teller_session.closed', actor: $actor, subject: $tellerSession, properties: [
            'till_public_id' => $till->public_id,
            'closing_declaration_minor' => $closingDeclarationMinor,
            'theoretical_balance_minor' => $theoreticalBalanceMinor,
            'currency' => $currency,
            'denomination_counts' => $denominationCounts['lines'],
        ], request: $request);
        $this->notifications->notifyAgency(
            agencyId: $tellerSession->agency_id,
            type: 'info',
            category: 'cash_session_closed',
            title: 'Teller session closed',
            message: 'A teller session was closed with expected cash balance '.$theoreticalBalanceMinor.' '.$currency.'.',
            sourceType: TellerSession::class,
            sourcePublicId: $tellerSession->public_id,
            actionUrl: '/teller-sessions/'.$tellerSession->public_id,
            metadata: [
                'till_public_id' => $till->public_id,
                'closing_declaration_minor' => $closingDeclarationMinor,
                'theoretical_balance_minor' => $theoreticalBalanceMinor,
                'currency' => $currency,
            ],
        );

        $tellerSession->refresh()->loadMissing(['agency', 'accountingDay', 'till', 'teller']);
        $this->summary->attach([$tellerSession]);

        return $this->respondSuccess(
            TellerSessionResource::make($tellerSession),
            'Teller session closed successfully'
        );
    }

    private function canAccessAgency(User $actor, int $agencyId): bool
    {
        if ($actor->hasRole('platform-admin')) {
            return true;
        }

        return $this->staffAgencyScope->currentAgencyId($actor) === $agencyId;
    }

    /**
     * @param  Builder<TellerSession>  $query
     */
    private function applyAgencyScope(Builder $query, User $actor, Request $request): ?JsonResponse
    {
        $requestedAgencyPublicId = $this->filterString($request, 'agency_public_id');
        if ($actor->hasRole('platform-admin')) {
            if ($requestedAgencyPublicId !== null) {
                $agencyId = Agency::query()->where('public_id', $requestedAgencyPublicId)->value('id');
                if (! is_numeric($agencyId)) {
                    return $this->respondUnprocessable(errors: ['filter.agency_public_id' => [__('domain.staff_selected_agency_invalid')]]);
                }

                $query->where('agency_id', (int) $agencyId);
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
                return $this->respondUnprocessable(errors: ['filter.agency_public_id' => [__('domain.staff_selected_agency_invalid')]]);
            }

            if ((int) $requestedAgencyId !== $agencyId) {
                return $this->respondForbidden('You cannot query teller sessions outside your current agency.');
            }
        }

        $query->where('agency_id', $agencyId);

        return null;
    }

    /**
     * @param  Builder<TellerSession>  $query
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
                message: __('Unsupported filter parameters.'),
                errors: ['filter' => [__('domain.unsupported_filter_keys', ['keys' => implode(', ', $unknown)])]]
            );
        }

        foreach ([
            'business_date' => '=',
            'business_date_from' => '>=',
            'business_date_to' => '<=',
        ] as $key => $operator) {
            $value = $this->filterString($request, $key);
            if ($value !== null) {
                $query->where('business_date', $operator, $value);
            }
        }

        $status = $this->filterString($request, 'status');
        if ($status !== null) {
            $query->where('status', $status);
        }

        $this->filterByRelationPublicId($query, $request, 'till_public_id', 'till');
        $this->filterByRelationPublicId($query, $request, 'teller_user_public_id', 'teller');

        return null;
    }

    /**
     * @param  Builder<TellerSession>  $query
     */
    private function applySort(Builder $query, Request $request): ?JsonResponse
    {
        $sort = $request->query('sort', '-opened_at');
        if (! is_string($sort) || ! array_key_exists($sort, self::ALLOWED_SORTS)) {
            return $this->respondUnprocessable(
                message: 'Unsupported sort parameter.',
                errors: ['sort' => [__('cash_journal.allowed_sort_values', ['values' => implode(', ', array_keys(self::ALLOWED_SORTS))])]]
            );
        }

        match ($sort) {
            'business_date' => $query->getQuery()->orderBy('business_date'),
            '-business_date' => $query->getQuery()->orderByDesc('business_date'),
            'opened_at' => $query->getQuery()->orderBy('opened_at'),
            '-opened_at' => $query->getQuery()->orderByDesc('opened_at'),
            'closed_at' => $query->getQuery()->orderBy('closed_at'),
            '-closed_at' => $query->getQuery()->orderByDesc('closed_at'),
            'status' => $query->getQuery()->orderBy('status'),
        };
        $query->getQuery()->orderByDesc('id');

        return null;
    }

    /**
     * @param  Builder<TellerSession>  $query
     */
    private function filterByRelationPublicId(Builder $query, Request $request, string $key, string $relation): void
    {
        $value = $this->filterString($request, $key);
        if ($value === null) {
            return;
        }

        $query->whereHas($relation, static function (Builder $builder) use ($value): void {
            $builder->where('public_id', $value);
        });
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

    private function resolveTeller(StoreTellerSessionRequest $request, User $actor): ?User
    {
        $publicId = $request->input('teller_user_public_id');
        if ($publicId === null || $publicId === '') {
            return $actor;
        }

        if (! is_string($publicId)) {
            return null;
        }

        $user = User::query()->where('public_id', $publicId)->first();

        return $user instanceof User ? $user : null;
    }

    private function canBeAssignedToTill(User $user, int $agencyId): bool
    {
        if ($user->status !== User::STATUS_ACTIVE || $this->staffAgencyScope->currentAgencyId($user) !== $agencyId) {
            return false;
        }

        if ($user->hasRole('teller') || $user->hasRole('cashier')) {
            return true;
        }

        return DB::table('staff_agency_assignments')
            ->where('user_id', $user->id)
            ->where('agency_id', $agencyId)
            ->where('status', StaffAgencyAssignment::STATUS_ACTIVE)
            ->where('starts_on', '<=', now()->toDateString())
            ->whereRaw('(ends_on IS NULL OR ends_on >= ?)', [now()->toDateString()])
            ->whereIn('role_at_agency', ['teller', 'cashier'])
            ->exists();
    }

    private function hasOpenSessionForTill(int $tillId): bool
    {
        return TellerSession::query()
            ->where('till_id', $tillId)
            ->where('status', TellerSession::STATUS_OPEN)
            ->first() instanceof TellerSession;
    }

    private function hasOpenSessionForTeller(int $tellerUserId): bool
    {
        return TellerSession::query()
            ->where('teller_user_id', $tellerUserId)
            ->where('status', TellerSession::STATUS_OPEN)
            ->first() instanceof TellerSession;
    }

    private function hasPendingTransactions(int $tellerSessionId): bool
    {
        return DB::table('teller_transactions')
            ->where('teller_session_id', $tellerSessionId)
            ->whereNotIn('status', ['posted', 'reversed', 'cancelled'])
            ->first(['id']) !== null;
    }

    private function theoreticalBalanceMinor(TellerSession $session): int
    {
        return $this->summary->theoreticalBalanceMinor($session);
    }

    /**
     * @return array{errors: array<string, array<int, string>>, lines: array<int, array{denomination_public_id: string, count: int, amount_minor: int}>}
     */
    private function validatedDenominationCounts(mixed $rawCounts, string $currency, int $expectedTotalMinor, bool $required): array
    {
        if (! is_array($rawCounts)) {
            return $required
                ? ['errors' => ['denomination_counts' => ['Denomination counts are required for this till.']], 'lines' => []]
                : ['errors' => [], 'lines' => []];
        }

        $seen = [];
        $total = 0;
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
                return ['errors' => ['denomination_counts.'.$index.'.denomination_public_id' => ['The selected denomination must be active and match the session currency.']], 'lines' => []];
            }

            $amountMinor = $denomination->value_minor * $count;
            $total += $amountMinor;
            $lines[] = [
                'denomination_public_id' => $publicId,
                'count' => $count,
                'amount_minor' => $amountMinor,
            ];
        }

        if ($total !== $expectedTotalMinor) {
            return ['errors' => ['denomination_counts' => ['Denomination counts must equal the opening declaration.']], 'lines' => []];
        }

        return ['errors' => [], 'lines' => $lines];
    }

    private function normalizedCurrency(mixed $currency): string
    {
        return is_string($currency) && $currency !== '' ? strtoupper($currency) : 'XAF';
    }
}
