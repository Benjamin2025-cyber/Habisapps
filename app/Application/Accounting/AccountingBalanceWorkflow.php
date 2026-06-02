<?php

declare(strict_types=1);

namespace App\Application\Accounting;

use App\Http\Controllers\BaseController;
use App\Http\Resources\AccountingBalanceResource;
use App\Http\Resources\AvailableBalanceResource;
use App\Models\AccountingDay;
use App\Models\CustomerAccount;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Support\Accounting\AccountingBalanceCalculator;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class AccountingBalanceWorkflow extends BaseController
{
    public function __construct(
        private readonly AccountingBalanceCalculator $calculator,
    ) {}

    public function ledgerAccount(Request $request, LedgerAccount $ledgerAccount): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('view', $ledgerAccount)) {
            return $this->respondForbidden();
        }

        $validated = $this->validatedQuery($request);

        return $this->respondSuccess(AccountingBalanceResource::make(
            $this->calculator->forLedgerAccount(
                $ledgerAccount,
                $validated['currency'],
                $validated['from'] ?? null,
                $validated['to'] ?? null,
            )
        ));
    }

    public function customerAccount(Request $request, CustomerAccount $customerAccount): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $this->canViewCustomerBalance($actor, $customerAccount)) {
            return $this->respondForbidden();
        }

        $customerAccount->loadMissing('ledgerAccount');
        $validated = $this->validatedQuery($request);

        return $this->respondSuccess(AccountingBalanceResource::make(
            $this->calculator->forCustomerAccount(
                $customerAccount,
                $validated['currency'],
                $validated['from'] ?? null,
                $validated['to'] ?? null,
            )
        ));
    }

    public function customerAccountAvailable(Request $request, CustomerAccount $customerAccount): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $this->canViewCustomerBalance($actor, $customerAccount)) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'currency' => ['sometimes', 'string', 'size:3'],
        ])->validate();
        $currencyValue = $validated['currency'] ?? 'XAF';
        $currency = strtoupper(is_string($currencyValue) ? $currencyValue : 'XAF');

        return $this->respondSuccess(AvailableBalanceResource::make(
            $this->calculator->availableForCustomerAccount($customerAccount, $currency)
        ));
    }

    public function ledgerAccountMovements(Request $request, LedgerAccount $ledgerAccount): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('view', $ledgerAccount)) {
            return $this->respondForbidden();
        }

        $validated = $this->validatedStatementQuery($request);
        $accountingDay = $this->resolveAccountingDayFilter($validated['accounting_day_public_id'] ?? null);
        if ($accountingDay instanceof AccountingDay && ! $this->accountingDayMatchesAgency($accountingDay, $ledgerAccount->agency_id)) {
            return $this->respondUnprocessable(errors: ['accounting_day_public_id' => ['The selected accounting day is outside the ledger account agency scope.']]);
        }
        $perPage = min(max($request->integer('per_page', 25), 1), 100);
        $page = max($request->integer('page', 1), 1);

        $summary = $this->ledgerStatementSummary($ledgerAccount, $validated['currency'], $validated['from'] ?? null, $validated['to'] ?? null, $accountingDay);
        $query = $this->ledgerMovementQuery($ledgerAccount, $validated['currency'], $validated['from'] ?? null, $validated['to'] ?? null, $accountingDay);
        $search = $request->query('search');
        if (is_string($search) && trim($search) !== '') {
            $term = trim($search);
            $query->where(function (Builder $builder) use ($term): void {
                $builder->where('journal_entries.reference', 'ilike', '%'.$term.'%')
                    ->orWhere('journal_lines.line_memo', 'ilike', '%'.$term.'%')
                    ->orWhere('journal_entries.public_id', 'ilike', '%'.$term.'%');
            });
        }

        $total = (clone $query)->count();
        $movements = (clone $query)
            ->forPage($page, $perPage)
            ->get()
            ->map(fn (object $row): array => $this->movementPayload($row, $ledgerAccount->normal_balance_side))
            ->all();

        return $this->respondSuccess([
            'statement' => $summary,
            'movements' => $movements,
        ], meta: [
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => (int) ceil($total / $perPage),
            ],
        ]);
    }

    public function customerAccountStatement(Request $request, CustomerAccount $customerAccount): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $this->canViewCustomerStatement($actor, $customerAccount)) {
            return $this->respondForbidden();
        }

        $customerAccount->loadMissing('ledgerAccount');
        $validated = $this->validatedStatementQuery($request);
        $accountingDay = $this->resolveAccountingDayFilter($validated['accounting_day_public_id'] ?? null);
        if ($accountingDay instanceof AccountingDay && ! $this->accountingDayMatchesAgency($accountingDay, $customerAccount->agency_id)) {
            return $this->respondUnprocessable(errors: ['accounting_day_public_id' => ['The selected accounting day is outside the customer account agency scope.']]);
        }
        $perPage = min(max($request->integer('per_page', 25), 1), 100);
        $page = max($request->integer('page', 1), 1);

        $summary = $this->customerStatementSummary($customerAccount, $validated['currency'], $validated['from'] ?? null, $validated['to'] ?? null, $accountingDay);
        $query = $this->customerMovementQuery($customerAccount, $validated['currency'], $validated['from'] ?? null, $validated['to'] ?? null, $accountingDay);
        $search = $request->query('search');
        if (is_string($search) && trim($search) !== '') {
            $term = trim($search);
            $query->where(function (Builder $builder) use ($term): void {
                $builder->where('journal_entries.reference', 'ilike', '%'.$term.'%')
                    ->orWhere('journal_lines.line_memo', 'ilike', '%'.$term.'%')
                    ->orWhere('journal_entries.public_id', 'ilike', '%'.$term.'%');
            });
        }

        $total = (clone $query)->count();
        $movements = (clone $query)
            ->forPage($page, $perPage)
            ->get()
            ->map(fn (object $row): array => $this->movementPayload($row, $this->rowString($row, 'normal_balance_side')))
            ->all();

        return $this->respondSuccess([
            'statement' => $summary,
            'movements' => $movements,
        ], meta: [
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => (int) ceil($total / $perPage),
            ],
        ]);
    }

    /**
     * Customer-account current/available balance access (FB-BAL-002).
     *
     * Requires the narrow operational balance permission plus account
     * visibility (which enforces same-agency scope through
     * CustomerAccountPolicy::view). This keeps balance lookups separate from
     * broad ledger/accounting-report access. Platform admins retain full access.
     */
    private function canViewCustomerBalance(User $actor, CustomerAccount $customerAccount): bool
    {
        if ($actor->hasRole('platform-admin')) {
            return true;
        }

        return $actor->can('customer.accounts.balance.view')
            && $actor->can('view', $customerAccount);
    }

    /**
     * Customer-account statement/movement history access (FB-BAL-002).
     *
     * Statements expose full transaction history, which is broader than the
     * current/available balance needed by front-office screens. They remain
     * gated by a dedicated statement permission so relaxing account `view` for
     * operational balance readers does not silently expose movement history.
     */
    private function canViewCustomerStatement(User $actor, CustomerAccount $customerAccount): bool
    {
        if ($actor->hasRole('platform-admin')) {
            return true;
        }

        return $actor->can('customer.accounts.statement.view')
            && $actor->can('view', $customerAccount);
    }

    /**
     * @return array{currency:string, from?:string, to?:string}
     */
    private function validatedQuery(Request $request): array
    {
        /** @var array{currency?:mixed, from?:string, to?:string} $validated */
        $validated = Validator::make($request->all(), [
            'currency' => ['sometimes', 'string', 'size:3'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
        ])->validate();

        $currencyValue = $validated['currency'] ?? 'XAF';
        $validated['currency'] = strtoupper(is_string($currencyValue) ? $currencyValue : 'XAF');

        /** @var array{currency:string, from?:string, to?:string} $validated */
        return $validated;
    }

    /**
     * @return array{currency:string, from?:string, to?:string, accounting_day_public_id?:string}
     */
    private function validatedStatementQuery(Request $request): array
    {
        /** @var array{currency?:mixed, from?:string, to?:string, accounting_day_public_id?:string} $validated */
        $validated = Validator::make($request->all(), [
            'currency' => ['sometimes', 'string', 'size:3'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
            'accounting_day_public_id' => ['sometimes', 'string', 'exists:accounting_days,public_id'],
        ])->validate();

        $currencyValue = $validated['currency'] ?? 'XAF';
        $validated['currency'] = strtoupper(is_string($currencyValue) ? $currencyValue : 'XAF');

        /** @var array{currency:string, from?:string, to?:string, accounting_day_public_id?:string} $validated */
        return $validated;
    }

    /**
     * @return array<string, mixed>
     */
    private function ledgerStatementSummary(LedgerAccount $ledgerAccount, string $currency, ?string $from, ?string $to, ?AccountingDay $accountingDay = null): array
    {
        $openingDate = $from ?? $accountingDay?->business_date?->toDateString();
        $opening = $openingDate !== null
            ? $this->calculator->forLedgerAccount($ledgerAccount, $currency, to: Carbon::parse($openingDate)->subDay()->toDateString())['balance_minor']
            : 0;
        $period = $accountingDay instanceof AccountingDay
            ? $this->periodTotals($this->ledgerMovementQuery($ledgerAccount, $currency, $from, $to, $accountingDay), $ledgerAccount->normal_balance_side)
            : $this->calculator->forLedgerAccount($ledgerAccount, $currency, $from, $to);

        return [
            'scope' => 'ledger_account',
            'public_id' => $ledgerAccount->public_id,
            'currency' => $currency,
            'from' => $from,
            'to' => $to,
            ...$this->accountingDayMetadata($accountingDay),
            'opening_balance_minor' => $opening,
            'debit_total_minor' => $period['debit_total_minor'],
            'credit_total_minor' => $period['credit_total_minor'],
            'closing_balance_minor' => $opening + $period['balance_minor'],
            'normal_balance_side' => $ledgerAccount->normal_balance_side,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function customerStatementSummary(CustomerAccount $customerAccount, string $currency, ?string $from, ?string $to, ?AccountingDay $accountingDay = null): array
    {
        $openingDate = $from ?? $accountingDay?->business_date?->toDateString();
        $opening = $openingDate !== null
            ? $this->calculator->forCustomerAccount($customerAccount, $currency, to: Carbon::parse($openingDate)->subDay()->toDateString())['balance_minor']
            : 0;
        $normalBalanceSide = $customerAccount->ledgerAccount->normal_balance_side ?? LedgerAccount::NORMAL_BALANCE_CREDIT;
        $period = $accountingDay instanceof AccountingDay
            ? $this->periodTotals($this->customerMovementQuery($customerAccount, $currency, $from, $to, $accountingDay), $normalBalanceSide)
            : $this->calculator->forCustomerAccount($customerAccount, $currency, $from, $to);

        return [
            'scope' => 'customer_account',
            'public_id' => $customerAccount->public_id,
            'currency' => $currency,
            'from' => $from,
            'to' => $to,
            ...$this->accountingDayMetadata($accountingDay),
            'opening_balance_minor' => $opening,
            'debit_total_minor' => $period['debit_total_minor'],
            'credit_total_minor' => $period['credit_total_minor'],
            'closing_balance_minor' => $opening + $period['balance_minor'],
            'normal_balance_side' => $normalBalanceSide,
        ];
    }

    private function ledgerMovementQuery(LedgerAccount $ledgerAccount, string $currency, ?string $from, ?string $to, ?AccountingDay $accountingDay = null): Builder
    {
        $query = $this->baseMovementQuery()
            ->where('journal_lines.ledger_account_id', $ledgerAccount->id)
            ->where('journal_lines.currency', $currency);

        return $this->applyMovementFilters($query, $from, $to, $accountingDay);
    }

    private function customerMovementQuery(CustomerAccount $customerAccount, string $currency, ?string $from, ?string $to, ?AccountingDay $accountingDay = null): Builder
    {
        $query = $this->baseMovementQuery()
            ->where('journal_lines.customer_account_id', $customerAccount->id)
            ->where('journal_lines.currency', $currency);

        return $this->applyMovementFilters($query, $from, $to, $accountingDay);
    }

    private function baseMovementQuery(): Builder
    {
        return DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'journal_lines.ledger_account_id')
            ->leftJoin('accounting_days', 'accounting_days.id', '=', 'journal_entries.accounting_day_id')
            ->where('journal_entries.status', JournalEntry::STATUS_POSTED)
            ->orderBy('journal_entries.business_date')
            ->orderBy('journal_lines.id')
            ->select([
                'journal_lines.public_id',
                'journal_lines.debit_minor',
                'journal_lines.credit_minor',
                'journal_lines.currency',
                'journal_lines.line_memo',
                'journal_entries.public_id as journal_entry_public_id',
                'journal_entries.reference',
                'journal_entries.business_date',
                'accounting_days.public_id as accounting_day_public_id',
                'accounting_days.status as accounting_day_status',
                'accounting_days.calendar_closed_at as accounting_day_closed_at',
                'ledger_accounts.public_id as ledger_account_public_id',
                'ledger_accounts.normal_balance_side',
            ]);
    }

    private function applyMovementFilters(Builder $query, ?string $from, ?string $to, ?AccountingDay $accountingDay): Builder
    {
        if ($from !== null) {
            $query->whereDate('journal_entries.business_date', '>=', $from);
        }

        if ($to !== null) {
            $query->whereDate('journal_entries.business_date', '<=', $to);
        }

        if ($accountingDay instanceof AccountingDay) {
            $query->where('journal_entries.accounting_day_id', $accountingDay->id);
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    private function movementPayload(object $row, string $normalBalanceSide): array
    {
        $debit = $this->rowInt($row, 'debit_minor');
        $credit = $this->rowInt($row, 'credit_minor');

        return [
            'public_id' => $this->rowString($row, 'public_id'),
            'journal_entry_public_id' => $this->rowString($row, 'journal_entry_public_id'),
            'ledger_account_public_id' => $this->rowString($row, 'ledger_account_public_id'),
            'reference' => $this->rowNullableString($row, 'reference'),
            'business_date' => $this->rowString($row, 'business_date'),
            'accounting_day_public_id' => $this->rowNullableString($row, 'accounting_day_public_id'),
            'accounting_day_status' => $this->rowNullableString($row, 'accounting_day_status'),
            'accounting_day_final' => $this->rowNullableString($row, 'accounting_day_status') === AccountingDay::STATUS_CLOSED,
            'currency' => $this->rowString($row, 'currency'),
            'debit_minor' => $debit,
            'credit_minor' => $credit,
            'signed_amount_minor' => $normalBalanceSide === LedgerAccount::NORMAL_BALANCE_CREDIT ? $credit - $debit : $debit - $credit,
            'line_memo' => $this->rowNullableString($row, 'line_memo'),
        ];
    }

    /**
     * @return array{debit_total_minor:int, credit_total_minor:int, balance_minor:int}
     */
    private function periodTotals(Builder $query, string $normalBalanceSide): array
    {
        $totals = $query
            ->cloneWithout(['columns', 'orders', 'limit', 'offset'])
            ->selectRaw('COALESCE(SUM(journal_lines.debit_minor), 0) AS debit_total')
            ->selectRaw('COALESCE(SUM(journal_lines.credit_minor), 0) AS credit_total')
            ->first();

        $debit = is_object($totals) && is_numeric($totals->debit_total) ? (int) $totals->debit_total : 0;
        $credit = is_object($totals) && is_numeric($totals->credit_total) ? (int) $totals->credit_total : 0;
        $balance = $normalBalanceSide === LedgerAccount::NORMAL_BALANCE_CREDIT ? $credit - $debit : $debit - $credit;

        return [
            'debit_total_minor' => $debit,
            'credit_total_minor' => $credit,
            'balance_minor' => $balance,
        ];
    }

    private function resolveAccountingDayFilter(?string $publicId): ?AccountingDay
    {
        if ($publicId === null || $publicId === '') {
            return null;
        }

        return AccountingDay::query()->where('public_id', $publicId)->first();
    }

    private function accountingDayMatchesAgency(AccountingDay $day, ?int $agencyId): bool
    {
        if ($day->scope_type === AccountingDay::SCOPE_INSTITUTION) {
            return $agencyId === null;
        }

        return $day->agency_id === $agencyId;
    }

    /**
     * @return array{accounting_day_public_id:string|null, accounting_day_status:string|null, accounting_day_final:bool|null, accounting_day_business_date:string|null}
     */
    private function accountingDayMetadata(?AccountingDay $day): array
    {
        return [
            'accounting_day_public_id' => $day?->public_id,
            'accounting_day_status' => $day?->status,
            'accounting_day_final' => $day instanceof AccountingDay ? $day->status === AccountingDay::STATUS_CLOSED : null,
            'accounting_day_business_date' => $day?->business_date?->toDateString(),
        ];
    }

    private function rowString(object $row, string $key): string
    {
        $value = ((array) $row)[$key] ?? '';

        return is_string($value) ? $value : (string) $value;
    }

    private function rowNullableString(object $row, string $key): ?string
    {
        $value = ((array) $row)[$key] ?? null;

        return $value === null ? null : (is_string($value) ? $value : (string) $value);
    }

    private function rowInt(object $row, string $key): int
    {
        $value = ((array) $row)[$key] ?? 0;

        return is_int($value) ? $value : (int) $value;
    }
}
