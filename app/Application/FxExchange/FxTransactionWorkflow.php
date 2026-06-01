<?php

declare(strict_types=1);

namespace App\Application\FxExchange;

use App\Http\Controllers\BaseController;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\Till;
use App\Models\User;
use App\Support\AccountingDay\AccountingDayGuard;
use App\Support\Security\SecurityAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

final class FxTransactionWorkflow extends BaseController
{
    public function __construct(
        private readonly SecurityAudit $securityAudit,
        private readonly AccountingDayGuard $accountingDayGuard,
    ) {}

    public function storeExchangeTransaction(Request $request, string $tillPublicId): JsonResponse
    {
        $actor = $this->actor($request);
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'direction' => ['required', Rule::in(['buy_foreign_currency', 'sell_foreign_currency'])],
            'foreign_currency' => ['required', 'string', 'size:3', 'exists:currencies,code'],
            'foreign_amount_minor' => ['required', 'integer', 'min:1'],
            'client_public_id' => ['sometimes', 'nullable', 'string', 'exists:clients,public_id'],
            'identity_full_name' => ['required_without:client_public_id', 'nullable', 'string', 'max:255'],
            'identity_number' => ['required_without:client_public_id', 'nullable', 'string', 'max:128'],
            'identity_document_type' => ['required_without:client_public_id', 'nullable', 'string', 'max:64'],
            'identity_issuing_country' => ['required_without:client_public_id', 'nullable', 'string', 'size:2'],
            'transaction_date' => ['sometimes', 'nullable', 'date'],
            'idempotency_key' => ['sometimes', 'nullable', 'string', 'max:128'],
        ])->validate();

        try {
            $result = DB::transaction(function () use ($actor, $tillPublicId, $validated): array {
                $till = $this->lockTill($tillPublicId);
                $this->assertExchangeTill($till);
                $this->assertAuthorization($till->agency_id, (string) $validated['direction']);

                $accountingDay = $this->accountingDayGuard->assertCanRegister($actor, 'fx.transaction', $till->agency_id);
                $businessDate = $accountingDay->business_date?->toDateString();

                $foreignCurrency = mb_strtoupper((string) $validated['foreign_currency']);
                $this->assertActiveCurrency($foreignCurrency);
                $direction = (string) $validated['direction'];
                $foreignAmount = (int) $validated['foreign_amount_minor'];
                $rate = $this->resolveActiveRate('XAF', $foreignCurrency);

                $appliedRate = $direction === 'sell_foreign_currency'
                    ? (float) $this->rowString($rate, 'sell_rate')
                    : (float) $this->rowString($rate, 'buy_rate');
                $marginRate = $direction === 'sell_foreign_currency'
                    ? (float) $this->rowString($rate, 'sell_margin_rate')
                    : (float) $this->rowString($rate, 'buy_margin_rate');
                $referenceRate = (float) $this->rowString($rate, 'reference_rate');
                $localAmount = (int) round($foreignAmount * $appliedRate);
                $marginAmount = (int) round($foreignAmount * abs($appliedRate - $referenceRate));

                $balance = $this->lockOrCreateStockBalance($till->id, $foreignCurrency);
                $currentBalance = $this->rowInt($balance, 'current_balance_minor');
                if ($direction === 'sell_foreign_currency' && $currentBalance < $foreignAmount) {
                    throw new InvalidArgumentException('Insufficient foreign-currency stock for this sale.');
                }

                $signature = $direction === 'buy_foreign_currency' ? +1 : -1;
                $newBalance = $currentBalance + ($signature * $foreignAmount);
                DB::table('till_currency_balances')->where('id', $this->rowInt($balance, 'id'))->update([
                    'current_balance_minor' => $newBalance,
                    'updated_at' => now(),
                ]);

                $clientId = $this->clientIdByPublicId($validated['client_public_id'] ?? null);
                $clientName = null;
                $clientIdentity = null;
                $clientIdentityType = null;
                $clientIdentityIssuingCountry = null;
                if ($clientId === null) {
                    $clientName = $this->nullableString($validated['identity_full_name'] ?? null);
                    $clientIdentity = $this->nullableString($validated['identity_number'] ?? null);
                    $clientIdentityType = $this->nullableString($validated['identity_document_type'] ?? null);
                    $clientIdentityIssuingCountry = $this->nullableString($validated['identity_issuing_country'] ?? null);
                }

                [$debitLedger, $creditLedger] = $this->resolveExchangeMapping(
                    $till->agency_id,
                    $direction,
                );

                $transactionNumber = 'FX-'.Str::upper(Str::random(10));
                $slipNumber = 'FXSLIP-'.now()->format('Ymd').'-'.Str::upper(Str::random(8));
                $registerNumber = 'FXREG-'.now()->format('Ymd').'-'.Str::upper(Str::random(8));
                $idempotencyKey = is_string($validated['idempotency_key'] ?? null) && $validated['idempotency_key'] !== ''
                    ? $validated['idempotency_key']
                    : 'fx-tx:'.$tillPublicId.':'.$transactionNumber;
                $transactionDate = is_string($validated['transaction_date'] ?? null) && $validated['transaction_date'] !== ''
                    ? $validated['transaction_date']
                    : $businessDate;

                $journalEntry = JournalEntry::query()->create([
                    'public_id' => (string) Str::ulid(),
                    'reference' => $transactionNumber,
                    'business_date' => $businessDate,
                    'posted_at' => null,
                    'agency_id' => $till->agency_id,
                    'source_module' => 'fx',
                    'source_type' => 'fx_'.$direction,
                    'status' => JournalEntry::STATUS_DRAFT,
                    'description' => 'Counter currency exchange '.$direction,
                    'created_by_user_id' => $actor->id,
                    'idempotency_key' => $idempotencyKey,
                    'accounting_day_id' => $accountingDay->id,
                ]);

                JournalLine::query()->create([
                    'public_id' => (string) Str::ulid(),
                    'agency_id' => $till->agency_id,
                    'journal_entry_id' => $journalEntry->id,
                    'ledger_account_id' => $debitLedger,
                    'debit_minor' => $localAmount,
                    'credit_minor' => 0,
                    'currency' => 'XAF',
                    'line_memo' => 'FX '.$direction.' debit leg',
                ]);
                JournalLine::query()->create([
                    'public_id' => (string) Str::ulid(),
                    'agency_id' => $till->agency_id,
                    'journal_entry_id' => $journalEntry->id,
                    'ledger_account_id' => $creditLedger,
                    'debit_minor' => 0,
                    'credit_minor' => $localAmount,
                    'currency' => 'XAF',
                    'line_memo' => 'FX '.$direction.' credit leg',
                ]);

                $this->postSystemJournal($journalEntry, $actor);

                $txId = DB::table('fx_transactions')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'agency_id' => $till->agency_id,
                    'till_id' => $till->id,
                    'client_id' => $clientId,
                    'transaction_number' => $transactionNumber,
                    'slip_number' => $slipNumber,
                    'register_number' => $registerNumber,
                    'transaction_date' => $transactionDate,
                    'direction' => $direction,
                    'foreign_currency' => $foreignCurrency,
                    'foreign_amount_minor' => $foreignAmount,
                    'local_currency' => 'XAF',
                    'local_amount_minor' => $localAmount,
                    'reference_rate' => $referenceRate,
                    'applied_rate' => $appliedRate,
                    'margin_rate' => $marginRate,
                    'margin_amount_minor' => $marginAmount,
                    'client_name' => $clientName,
                    'client_identity_number' => $clientIdentity,
                    'client_identity_type' => $clientIdentityType,
                    'client_identity_issuing_country' => $clientIdentityIssuingCountry,
                    'status' => 'posted',
                    'journal_entry_id' => $journalEntry->id,
                    'metadata' => json_encode([
                        'rate_public_id' => $this->rowString($rate, 'public_id'),
                        'idempotency_key' => $idempotencyKey,
                    ], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $tx = DB::table('fx_transactions')->where('id', $txId)->first();
                if (! is_object($tx)) {
                    throw new InvalidArgumentException('FX transaction could not be reloaded.');
                }

                return ['transaction' => $tx, 'journal_entry_public_id' => $journalEntry->public_id];
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['fx_transaction' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('fx.transaction.posted', actor: $actor, properties: [
            'transaction_public_id' => $this->rowString($result['transaction'], 'public_id'),
            'till_public_id' => $tillPublicId,
        ], request: $request);

        return $this->respondCreated([
            'transaction' => $this->transactionPayload($result['transaction']),
            'journal_entry_public_id' => $result['journal_entry_public_id'],
        ], 'Currency exchange transaction posted successfully');
    }

    public function reverseExchangeTransaction(Request $request, string $transactionPublicId): JsonResponse
    {
        $actor = $this->actor($request);
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        try {
            $reversal = DB::transaction(function () use ($actor, $transactionPublicId): array {
                $tx = DB::table('fx_transactions')
                    ->where('public_id', $transactionPublicId)
                    ->lockForUpdate()
                    ->first();
                if (! is_object($tx)) {
                    throw new InvalidArgumentException('FX transaction is invalid.');
                }
                if ($this->rowString($tx, 'status') !== 'posted') {
                    throw new InvalidArgumentException('Only posted FX transactions can be reversed.');
                }

                $balance = $this->lockOrCreateStockBalance(
                    $this->rowInt($tx, 'till_id'),
                    $this->rowString($tx, 'foreign_currency'),
                );
                $direction = $this->rowString($tx, 'direction');
                $foreignAmount = $this->rowInt($tx, 'foreign_amount_minor');
                $signature = $direction === 'buy_foreign_currency' ? -1 : +1;
                $newBalance = $this->rowInt($balance, 'current_balance_minor') + ($signature * $foreignAmount);
                if ($newBalance < 0) {
                    throw new InvalidArgumentException('Reversal would push foreign-currency stock negative.');
                }
                DB::table('till_currency_balances')->where('id', $this->rowInt($balance, 'id'))->update([
                    'current_balance_minor' => $newBalance,
                    'updated_at' => now(),
                ]);

                $original = JournalEntry::query()->whereKey($this->rowInt($tx, 'journal_entry_id'))->first();
                if (! $original instanceof JournalEntry) {
                    throw new InvalidArgumentException('Original journal entry was not found.');
                }

                $accountingDay = $this->accountingDayGuard->assertCanRegister($actor, 'fx.reversal', $this->rowInt($tx, 'agency_id'));
                $reversalEntry = $this->createReversingEntry($original, $actor, 'fx-reversal:'.$transactionPublicId, $accountingDay);

                DB::table('fx_transactions')->where('id', $this->rowInt($tx, 'id'))->update([
                    'status' => 'reversed',
                    'updated_at' => now(),
                ]);

                $reloaded = DB::table('fx_transactions')->where('id', $this->rowInt($tx, 'id'))->first();
                if (! is_object($reloaded)) {
                    throw new InvalidArgumentException('Reversed transaction could not be reloaded.');
                }

                return [
                    'transaction' => $reloaded,
                    'reversal_journal_public_id' => $reversalEntry->public_id,
                ];
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['fx_transaction' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('fx.transaction.reversed', actor: $actor, properties: [
            'transaction_public_id' => $transactionPublicId,
        ], request: $request);

        return $this->respondSuccess([
            'transaction' => $this->transactionPayload($reversal['transaction']),
            'reversal_journal_public_id' => $reversal['reversal_journal_public_id'],
        ], 'Currency exchange transaction reversed');
    }

    private function actor(Request $request): ?User
    {
        $actor = $request->user();

        return $actor instanceof User && $actor->hasRole('platform-admin') ? $actor : null;
    }

    private function lockTill(string $publicId): Till
    {
        DB::table('tills')->where('public_id', $publicId)->lockForUpdate()->first(['id']);
        $till = Till::query()->where('public_id', $publicId)->first();
        if (! $till instanceof Till) {
            throw new InvalidArgumentException('Till is invalid.');
        }

        return $till;
    }

    private function assertExchangeTill(Till $till): void
    {
        if ($till->status !== Till::STATUS_ACTIVE) {
            throw new InvalidArgumentException('Till must be active.');
        }
        if ($till->nature !== 'exchange') {
            throw new InvalidArgumentException('Currency exchange operations require a till with nature=exchange.');
        }
    }

    private function assertAuthorization(int $agencyId, string $direction): void
    {
        $today = now()->toDateString();
        $query = DB::table('fx_authorizations')
            ->where('status', 'active')
            ->where(function ($builder) use ($agencyId): void {
                $builder->whereNull('agency_id')->orWhere('agency_id', $agencyId);
            })
            ->where('effective_from', '<=', $today)
            ->where(function ($builder) use ($today): void {
                $builder->whereNull('effective_to')->orWhere('effective_to', '>=', $today);
            });

        if ($direction === 'sell_foreign_currency') {
            $query->where('supports_sale', true);
        } else {
            $query->where('supports_purchase', true);
        }

        if (! $query->exists()) {
            throw new InvalidArgumentException('No active currency-exchange authorization covers this operation.');
        }
    }

    private function assertActiveCurrency(string $code): void
    {
        $currency = DB::table('currencies')
            ->where('code', mb_strtoupper($code))
            ->where('status', 'active')
            ->first(['code']);
        if (! is_object($currency)) {
            throw new InvalidArgumentException('Currency '.$code.' is not active for currency-exchange operations.');
        }
    }

    private function resolveActiveRate(string $base, string $quote): object
    {
        $today = now()->toDateString();
        $rate = DB::table('exchange_rates')
            ->where('status', 'active')
            ->where('base_currency', $base)
            ->where('quote_currency', $quote)
            ->where('effective_on', '<=', $today)
            ->where(function ($builder) use ($today): void {
                $builder->whereNull('effective_to')->orWhere('effective_to', '>=', $today);
            })
            ->orderByDesc('effective_on')
            ->first();
        if (! is_object($rate)) {
            throw new InvalidArgumentException('No active exchange rate is available for '.$base.'/'.$quote.'.');
        }

        return $rate;
    }

    private function lockOrCreateStockBalance(int $tillId, string $currency): object
    {
        $balance = DB::table('till_currency_balances')
            ->where('till_id', $tillId)
            ->where('currency', $currency)
            ->lockForUpdate()
            ->first();
        if (is_object($balance)) {
            return $balance;
        }

        $id = DB::table('till_currency_balances')->insertGetId([
            'till_id' => $tillId,
            'currency' => $currency,
            'opening_balance_minor' => 0,
            'current_balance_minor' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $balance = DB::table('till_currency_balances')->where('id', $id)->lockForUpdate()->first();
        if (! is_object($balance)) {
            throw new InvalidArgumentException('Stock balance row could not be reloaded.');
        }

        return $balance;
    }

    /**
     * @return array{0:int, 1:int}
     */
    private function resolveExchangeMapping(int $agencyId, string $direction): array
    {
        $code = $direction === 'buy_foreign_currency' ? 'fx_buy_foreign_currency' : 'fx_sell_foreign_currency';
        $mapping = DB::table('operation_account_mappings as map')
            ->join('operation_codes as op', 'op.id', '=', 'map.operation_code_id')
            ->where('op.code', $code)
            ->where('op.module', 'fx')
            ->where('op.status', 'active')
            ->where('map.status', 'active')
            ->whereIn('map.currency', ['XAF', null])
            ->first(['map.debit_ledger_account_id', 'map.credit_ledger_account_id']);
        if (! is_object($mapping)
            || ! is_numeric($mapping->debit_ledger_account_id)
            || ! is_numeric($mapping->credit_ledger_account_id)) {
            throw new InvalidArgumentException('Active operation mapping with both legs is required for '.$code.'.');
        }

        $debit = (int) $mapping->debit_ledger_account_id;
        $credit = (int) $mapping->credit_ledger_account_id;
        $this->assertLedgerActiveForAgency($debit, $agencyId);
        $this->assertLedgerActiveForAgency($credit, $agencyId);

        return [$debit, $credit];
    }

    private function assertLedgerActiveForAgency(int $ledgerAccountId, int $agencyId): void
    {
        $ledger = LedgerAccount::query()->whereKey($ledgerAccountId)->first();
        if (! $ledger instanceof LedgerAccount
            || $ledger->status !== LedgerAccount::STATUS_ACTIVE
            || $ledger->agency_id !== $agencyId) {
            throw new InvalidArgumentException('FX mapping ledger account must be active and belong to the till agency.');
        }
    }

    private function postSystemJournal(JournalEntry $journalEntry, User $actor): void
    {
        $journalEntry->forceFill([
            'status' => JournalEntry::STATUS_SUBMITTED,
            'submitted_at' => now(),
            'submitted_by_user_id' => $actor->id,
        ])->save();
        $journalEntry->forceFill([
            'status' => JournalEntry::STATUS_APPROVED,
            'reviewed_at' => now(),
            'reviewed_by_user_id' => $actor->id,
        ])->save();
        $journalEntry->forceFill([
            'status' => JournalEntry::STATUS_POSTED,
            'posted_at' => now(),
            'posted_by_user_id' => $actor->id,
        ])->save();
    }

    private function createReversingEntry(JournalEntry $original, User $actor, string $idempotencyKey, \App\Models\AccountingDay $accountingDay): JournalEntry
    {
        $reversal = JournalEntry::query()->create([
            'public_id' => (string) Str::ulid(),
            'reference' => 'REV-'.$original->reference,
            'business_date' => $accountingDay->business_date?->toDateString(),
            'posted_at' => null,
            'agency_id' => $original->agency_id,
            'source_module' => $original->source_module,
            'source_type' => $original->source_type.'_reversal',
            'source_public_id' => $original->source_public_id,
            'status' => JournalEntry::STATUS_DRAFT,
            'description' => 'Reversal of '.$original->reference,
            'reversal_of_journal_entry_id' => $original->id,
            'created_by_user_id' => $actor->id,
            'idempotency_key' => $idempotencyKey,
            'accounting_day_id' => $accountingDay->id,
        ]);

        foreach ($original->lines as $line) {
            JournalLine::query()->create([
                'public_id' => (string) Str::ulid(),
                'agency_id' => $line->agency_id,
                'journal_entry_id' => $reversal->id,
                'ledger_account_id' => $line->ledger_account_id,
                'customer_account_id' => $line->customer_account_id,
                'loan_id' => $line->loan_id,
                'debit_minor' => $line->credit_minor,
                'credit_minor' => $line->debit_minor,
                'currency' => $line->currency,
                'line_memo' => 'Reversal of '.$line->line_memo,
            ]);
        }

        $this->postSystemJournal($reversal, $actor);

        return $reversal;
    }

    private function clientIdByPublicId(mixed $publicId): ?int
    {
        if (! is_string($publicId) || $publicId === '') {
            return null;
        }
        $client = DB::table('clients')->where('public_id', $publicId)->first(['id']);

        return is_object($client) && is_numeric($client->id) ? (int) $client->id : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function transactionPayload(object $row): array
    {
        return [
            'public_id' => $this->rowString($row, 'public_id'),
            'transaction_number' => $this->rowString($row, 'transaction_number'),
            'slip_number' => $this->rowNullableString($row, 'slip_number'),
            'register_number' => $this->rowNullableString($row, 'register_number'),
            'transaction_date' => $this->rowNullableString($row, 'transaction_date'),
            'direction' => $this->rowString($row, 'direction'),
            'foreign_currency' => $this->rowString($row, 'foreign_currency'),
            'foreign_amount_minor' => $this->rowInt($row, 'foreign_amount_minor'),
            'local_currency' => $this->rowString($row, 'local_currency'),
            'local_amount_minor' => $this->rowInt($row, 'local_amount_minor'),
            'reference_rate' => $this->rowNullableString($row, 'reference_rate'),
            'applied_rate' => $this->rowNullableString($row, 'applied_rate'),
            'margin_amount_minor' => $this->rowInt($row, 'margin_amount_minor'),
            'client_identity_type' => $this->rowNullableString($row, 'client_identity_type'),
            'client_identity_issuing_country' => $this->rowNullableString($row, 'client_identity_issuing_country'),
            'status' => $this->rowString($row, 'status'),
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

        return $value === null ? null : (string) $value;
    }

    private function rowInt(object $row, string $key): int
    {
        $value = ((array) $row)[$key] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_scalar($value) ? (string) $value : null;
    }
}
