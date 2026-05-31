<?php

declare(strict_types=1);

namespace App\Application\Accounting;

use App\Http\Controllers\BaseController;
use App\Http\Resources\AccountingBalanceResource;
use App\Http\Resources\AvailableBalanceResource;
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
        if (! $actor instanceof User || $actor->cannot('view', $customerAccount)) {
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
        if (! $actor instanceof User || $actor->cannot('view', $customerAccount)) {
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

        $validated = $this->validatedQuery($request);
        $perPage = min(max($request->integer('per_page', 25), 1), 100);
        $page = max($request->integer('page', 1), 1);

        $summary = $this->ledgerStatementSummary($ledgerAccount, $validated['currency'], $validated['from'] ?? null, $validated['to'] ?? null);
        $query = $this->ledgerMovementQuery($ledgerAccount, $validated['currency'], $validated['from'] ?? null, $validated['to'] ?? null);
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
        if (! $actor instanceof User || $actor->cannot('view', $customerAccount)) {
            return $this->respondForbidden();
        }

        $customerAccount->loadMissing('ledgerAccount');
        $validated = $this->validatedQuery($request);
        $perPage = min(max($request->integer('per_page', 25), 1), 100);
        $page = max($request->integer('page', 1), 1);

        $summary = $this->customerStatementSummary($customerAccount, $validated['currency'], $validated['from'] ?? null, $validated['to'] ?? null);
        $query = $this->customerMovementQuery($customerAccount, $validated['currency'], $validated['from'] ?? null, $validated['to'] ?? null);
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
     * @return array<string, mixed>
     */
    private function ledgerStatementSummary(LedgerAccount $ledgerAccount, string $currency, ?string $from, ?string $to): array
    {
        $opening = $from !== null
            ? $this->calculator->forLedgerAccount($ledgerAccount, $currency, to: Carbon::parse($from)->subDay()->toDateString())['balance_minor']
            : 0;
        $period = $this->calculator->forLedgerAccount($ledgerAccount, $currency, $from, $to);

        return [
            'scope' => 'ledger_account',
            'public_id' => $ledgerAccount->public_id,
            'currency' => $currency,
            'from' => $from,
            'to' => $to,
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
    private function customerStatementSummary(CustomerAccount $customerAccount, string $currency, ?string $from, ?string $to): array
    {
        $opening = $from !== null
            ? $this->calculator->forCustomerAccount($customerAccount, $currency, to: Carbon::parse($from)->subDay()->toDateString())['balance_minor']
            : 0;
        $period = $this->calculator->forCustomerAccount($customerAccount, $currency, $from, $to);

        return [
            'scope' => 'customer_account',
            'public_id' => $customerAccount->public_id,
            'currency' => $currency,
            'from' => $from,
            'to' => $to,
            'opening_balance_minor' => $opening,
            'debit_total_minor' => $period['debit_total_minor'],
            'credit_total_minor' => $period['credit_total_minor'],
            'closing_balance_minor' => $opening + $period['balance_minor'],
            'normal_balance_side' => $customerAccount->ledgerAccount?->normal_balance_side,
        ];
    }

    private function ledgerMovementQuery(LedgerAccount $ledgerAccount, string $currency, ?string $from, ?string $to): Builder
    {
        $query = $this->baseMovementQuery()
            ->where('journal_lines.ledger_account_id', $ledgerAccount->id)
            ->where('journal_lines.currency', $currency);

        return $this->applyMovementDateRange($query, $from, $to);
    }

    private function customerMovementQuery(CustomerAccount $customerAccount, string $currency, ?string $from, ?string $to): Builder
    {
        $query = $this->baseMovementQuery()
            ->where('journal_lines.customer_account_id', $customerAccount->id)
            ->where('journal_lines.currency', $currency);

        return $this->applyMovementDateRange($query, $from, $to);
    }

    private function baseMovementQuery(): Builder
    {
        return DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'journal_lines.ledger_account_id')
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
                'ledger_accounts.public_id as ledger_account_public_id',
                'ledger_accounts.normal_balance_side',
            ]);
    }

    private function applyMovementDateRange(Builder $query, ?string $from, ?string $to): Builder
    {
        if ($from !== null) {
            $query->whereDate('journal_entries.business_date', '>=', $from);
        }

        if ($to !== null) {
            $query->whereDate('journal_entries.business_date', '<=', $to);
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
            'currency' => $this->rowString($row, 'currency'),
            'debit_minor' => $debit,
            'credit_minor' => $credit,
            'signed_amount_minor' => $normalBalanceSide === LedgerAccount::NORMAL_BALANCE_CREDIT ? $credit - $debit : $debit - $credit,
            'line_memo' => $this->rowNullableString($row, 'line_memo'),
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
