<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\BaseController;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\TellerSession;
use App\Models\Till;
use App\Models\User;
use App\Support\Security\SecurityAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

final class CurrencyExchangeController extends BaseController
{
    private const array AUTHORIZATION_TYPES = [
        'credit_institution',
        'emf',
        'postal_administration',
        'dedicated_bureau',
        'sub_delegate',
    ];

    public function __construct(
        private readonly SecurityAudit $securityAudit,
    ) {}

    public function storeAuthorization(Request $request): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'agency_public_id' => ['sometimes', 'nullable', 'string', 'exists:agencies,public_id'],
            'authorization_reference' => ['required', 'string', 'max:191'],
            'authorization_type' => ['required', Rule::in(self::AUTHORIZATION_TYPES)],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:effective_from'],
            'supports_purchase' => ['sometimes', 'boolean'],
            'supports_sale' => ['sometimes', 'boolean'],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ])->validate();

        try {
            $row = DB::transaction(function () use ($validated, $request): object {
                $type = (string) $validated['authorization_type'];
                $supportsPurchase = $this->boolValue($validated['supports_purchase'] ?? true, true);
                $supportsSale = $this->boolValue($validated['supports_sale'] ?? true, true);
                if ($type === 'sub_delegate' && $supportsSale === true) {
                    throw new InvalidArgumentException('Sub-delegate authorizations may not support sale operations (BEAC Instruction 009).');
                }
                if ($supportsPurchase === false && $supportsSale === false) {
                    throw new InvalidArgumentException('Authorization must support at least one of purchase or sale.');
                }

                $id = DB::table('fx_authorizations')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'agency_id' => $this->agencyIdByPublicId($validated['agency_public_id'] ?? null),
                    'authorization_reference' => (string) $validated['authorization_reference'],
                    'authorization_type' => $type,
                    'effective_from' => (string) $validated['effective_from'],
                    'effective_to' => $this->nullableString($validated['effective_to'] ?? null),
                    'status' => 'active',
                    'supports_purchase' => $supportsPurchase,
                    'supports_sale' => $supportsSale,
                    'metadata' => $this->jsonOrNull($validated['metadata'] ?? null),
                    'created_by_user_id' => $request->user()?->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $row = DB::table('fx_authorizations')->where('id', $id)->first();
                if (! is_object($row)) {
                    throw new InvalidArgumentException('Authorization could not be reloaded.');
                }

                return $row;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['fx_authorization' => [$exception->getMessage()]]);
        }

        $this->audit($request, 'fx.authorization.created', [
            'authorization_public_id' => $this->rowString($row, 'public_id'),
            'authorization_type' => $this->rowString($row, 'authorization_type'),
        ]);

        return $this->respondCreated($this->authorizationPayload($row), 'Currency exchange authorization recorded successfully');
    }

    public function storeCurrency(Request $request): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'code' => ['required', 'string', 'size:3', 'unique:currencies,code'],
            'name' => ['required', 'string', 'max:255'],
            'minor_unit' => ['sometimes', 'integer', 'min:0', 'max:8'],
            'status' => ['sometimes', Rule::in(['active', 'inactive', 'archived'])],
            'is_base_currency' => ['sometimes', 'boolean'],
        ])->validate();

        $code = mb_strtoupper((string) $validated['code']);
        $id = DB::table('currencies')->insertGetId([
            'code' => $code,
            'name' => (string) $validated['name'],
            'minor_unit' => isset($validated['minor_unit']) && is_numeric($validated['minor_unit']) ? (int) $validated['minor_unit'] : 2,
            'is_base_currency' => $this->boolValue($validated['is_base_currency'] ?? false, false),
            'status' => is_string($validated['status'] ?? null) && $validated['status'] !== '' ? $validated['status'] : 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->audit($request, 'fx.currency.created', ['code' => $code]);

        $row = DB::table('currencies')->where('id', $id)->first();
        if (! is_object($row)) {
            return $this->respondUnprocessable(errors: ['currency' => ['Currency row could not be reloaded.']]);
        }

        return $this->respondCreated($this->currencyPayload($row), 'Currency reference recorded successfully');
    }

    public function storeRateDraft(Request $request): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'base_currency' => ['required', 'string', 'size:3', 'exists:currencies,code'],
            'quote_currency' => ['required', 'string', 'size:3', 'exists:currencies,code', 'different:base_currency'],
            'reference_rate' => ['required', 'numeric', 'gt:0'],
            'buy_margin_rate' => ['required', 'numeric', 'min:0'],
            'sell_margin_rate' => ['required', 'numeric', 'min:0'],
            'effective_on' => ['required', 'date'],
            'effective_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:effective_on'],
        ])->validate();

        try {
            $row = DB::transaction(function () use ($validated, $request): object {
                $base = mb_strtoupper((string) $validated['base_currency']);
                $quote = mb_strtoupper((string) $validated['quote_currency']);
                if ($base !== 'XAF') {
                    throw new InvalidArgumentException('Currency exchange rates must settle against XAF in this module.');
                }
                if ($quote === 'XAF') {
                    throw new InvalidArgumentException('Quote currency must be a foreign currency.');
                }
                $this->assertActiveCurrency($base);
                $this->assertActiveCurrency($quote);

                $reference = (float) $validated['reference_rate'];
                $buyMargin = (float) $validated['buy_margin_rate'];
                $sellMargin = (float) $validated['sell_margin_rate'];

                $buyRate = $reference * (1 - $buyMargin);
                $sellRate = $reference * (1 + $sellMargin);
                if ($buyRate <= 0) {
                    throw new InvalidArgumentException('Buy margin cannot wipe out the reference rate.');
                }

                $this->assertNoActiveRateOverlap(
                    $base,
                    $quote,
                    (string) $validated['effective_on'],
                    $this->nullableString($validated['effective_to'] ?? null),
                    null,
                );

                $id = DB::table('exchange_rates')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'base_currency' => $base,
                    'quote_currency' => $quote,
                    'reference_rate' => $reference,
                    'buy_margin_rate' => $buyMargin,
                    'sell_margin_rate' => $sellMargin,
                    'buy_rate' => $buyRate,
                    'sell_rate' => $sellRate,
                    'effective_on' => (string) $validated['effective_on'],
                    'effective_to' => $this->nullableString($validated['effective_to'] ?? null),
                    'status' => 'draft',
                    'created_by_user_id' => $request->user()?->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $row = DB::table('exchange_rates')->where('id', $id)->first();
                if (! is_object($row)) {
                    throw new InvalidArgumentException('Exchange rate draft could not be reloaded.');
                }

                return $row;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['exchange_rate' => [$exception->getMessage()]]);
        }

        $this->audit($request, 'fx.rate.draft_created', ['rate_public_id' => $this->rowString($row, 'public_id')]);

        return $this->respondCreated($this->ratePayload($row), 'Exchange rate draft created successfully');
    }

    public function approveRate(Request $request, string $ratePublicId): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasRole('platform-admin')) {
            return $this->respondForbidden();
        }

        try {
            $row = DB::transaction(function () use ($ratePublicId, $actor): object {
                $rate = DB::table('exchange_rates')
                    ->where('public_id', $ratePublicId)
                    ->lockForUpdate()
                    ->first();
                if (! is_object($rate)) {
                    throw new InvalidArgumentException('Exchange rate is invalid.');
                }
                if ($this->rowString($rate, 'status') !== 'draft') {
                    throw new InvalidArgumentException('Only draft rates can be approved.');
                }
                if ($this->rowInt($rate, 'created_by_user_id') === $actor->id) {
                    throw new InvalidArgumentException('Maker cannot approve their own rate draft.');
                }
                $this->assertActiveCurrency($this->rowString($rate, 'base_currency'));
                $this->assertActiveCurrency($this->rowString($rate, 'quote_currency'));
                $this->assertNoActiveRateOverlap(
                    $this->rowString($rate, 'base_currency'),
                    $this->rowString($rate, 'quote_currency'),
                    $this->rowString($rate, 'effective_on'),
                    $this->rowNullableString($rate, 'effective_to'),
                    $this->rowInt($rate, 'id'),
                );

                DB::table('exchange_rates')->where('id', $this->rowInt($rate, 'id'))->update([
                    'status' => 'active',
                    'approved_by_user_id' => $actor->id,
                    'approved_at' => now(),
                    'updated_at' => now(),
                ]);

                $updated = DB::table('exchange_rates')->where('id', $this->rowInt($rate, 'id'))->first();
                if (! is_object($updated)) {
                    throw new InvalidArgumentException('Approved rate could not be reloaded.');
                }

                return $updated;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['exchange_rate' => [$exception->getMessage()]]);
        }

        $this->audit($request, 'fx.rate.approved', ['rate_public_id' => $this->rowString($row, 'public_id')]);

        return $this->respondSuccess($this->ratePayload($row), 'Exchange rate approved');
    }

    public function storeExchangeTransaction(Request $request, string $tillPublicId): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasRole('platform-admin')) {
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
                    : now()->toDateString();

                $journalEntry = JournalEntry::query()->create([
                    'public_id' => (string) Str::ulid(),
                    'reference' => $transactionNumber,
                    'business_date' => $transactionDate,
                    'posted_at' => null,
                    'agency_id' => $till->agency_id,
                    'source_module' => 'fx',
                    'source_type' => 'fx_'.$direction,
                    'status' => JournalEntry::STATUS_DRAFT,
                    'description' => 'Counter currency exchange '.$direction,
                    'created_by_user_id' => $actor->id,
                    'idempotency_key' => $idempotencyKey,
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

        $this->audit($request, 'fx.transaction.posted', [
            'transaction_public_id' => $this->rowString($result['transaction'], 'public_id'),
            'till_public_id' => $tillPublicId,
        ]);

        return $this->respondCreated([
            'transaction' => $this->transactionPayload($result['transaction']),
            'journal_entry_public_id' => $result['journal_entry_public_id'],
        ], 'Currency exchange transaction posted successfully');
    }

    public function reverseExchangeTransaction(Request $request, string $transactionPublicId): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasRole('platform-admin')) {
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
                $reversalEntry = $this->createReversingEntry($original, $actor, 'fx-reversal:'.$transactionPublicId);

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

        $this->audit($request, 'fx.transaction.reversed', [
            'transaction_public_id' => $transactionPublicId,
        ]);

        return $this->respondSuccess([
            'transaction' => $this->transactionPayload($reversal['transaction']),
            'reversal_journal_public_id' => $reversal['reversal_journal_public_id'],
        ], 'Currency exchange transaction reversed');
    }

    public function storeStockMovement(Request $request, string $tillPublicId): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasRole('platform-admin')) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'movement_type' => ['required', Rule::in(['partner_replenishment', 'partner_sale', 'adjustment_correction'])],
            'currency' => ['required', 'string', 'size:3', 'exists:currencies,code'],
            'amount_minor' => ['required', 'integer', 'min:1'],
            'movement_date' => ['sometimes', 'nullable', 'date'],
            'counterparty_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ])->validate();

        try {
            $row = DB::transaction(function () use ($actor, $tillPublicId, $validated): object {
                $till = $this->lockTill($tillPublicId);
                $this->assertExchangeTill($till);
                $movementType = (string) $validated['movement_type'];
                $currency = mb_strtoupper((string) $validated['currency']);
                $this->assertActiveCurrency($currency);
                $amount = (int) $validated['amount_minor'];

                $movementDate = is_string($validated['movement_date'] ?? null) && $validated['movement_date'] !== ''
                    ? $validated['movement_date']
                    : now()->toDateString();

                $id = DB::table('fx_stock_movements')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'agency_id' => $till->agency_id,
                    'till_id' => $till->id,
                    'currency' => $currency,
                    'movement_type' => $movementType,
                    'amount_minor' => $amount,
                    'movement_date' => $movementDate,
                    'counterparty_name' => $this->nullableString($validated['counterparty_name'] ?? null),
                    'status' => 'pending',
                    'requested_by_user_id' => $actor->id,
                    'notes' => $this->nullableString($validated['notes'] ?? null),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $row = DB::table('fx_stock_movements')->where('id', $id)->first();
                if (! is_object($row)) {
                    throw new InvalidArgumentException('Stock movement could not be reloaded.');
                }

                return $row;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['fx_stock_movement' => [$exception->getMessage()]]);
        }

        $this->audit($request, 'fx.stock_movement.posted', [
            'movement_public_id' => $this->rowString($row, 'public_id'),
            'till_public_id' => $tillPublicId,
        ]);

        return $this->respondCreated($this->stockMovementPayload($row), 'FX stock movement recorded');
    }

    public function approveStockMovement(Request $request, string $movementPublicId): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasRole('platform-admin')) {
            return $this->respondForbidden();
        }

        try {
            $row = DB::transaction(function () use ($actor, $movementPublicId): object {
                $movement = DB::table('fx_stock_movements')
                    ->where('public_id', $movementPublicId)
                    ->lockForUpdate()
                    ->first();
                if (! is_object($movement)) {
                    throw new InvalidArgumentException('FX stock movement is invalid.');
                }
                if ($this->rowString($movement, 'status') !== 'pending') {
                    throw new InvalidArgumentException('Only pending FX stock movements can be approved.');
                }
                if ($this->rowInt($movement, 'requested_by_user_id') === $actor->id) {
                    throw new InvalidArgumentException('Requester cannot approve their own FX stock movement.');
                }

                $till = Till::query()->whereKey($this->rowInt($movement, 'till_id'))->first();
                if (! $till instanceof Till) {
                    throw new InvalidArgumentException('Till is invalid.');
                }
                $this->assertExchangeTill($till);

                $currency = $this->rowString($movement, 'currency');
                $this->assertActiveCurrency($currency);
                $balance = $this->lockOrCreateStockBalance($this->rowInt($movement, 'till_id'), $currency);
                $movementType = $this->rowString($movement, 'movement_type');
                $signature = $movementType === 'partner_replenishment' ? +1 : -1;
                $newBalance = $this->rowInt($balance, 'current_balance_minor') + ($signature * $this->rowInt($movement, 'amount_minor'));
                if ($newBalance < 0) {
                    throw new InvalidArgumentException('Stock movement would push foreign-currency stock negative.');
                }

                DB::table('till_currency_balances')->where('id', $this->rowInt($balance, 'id'))->update([
                    'current_balance_minor' => $newBalance,
                    'updated_at' => now(),
                ]);
                DB::table('fx_stock_movements')->where('id', $this->rowInt($movement, 'id'))->update([
                    'status' => 'posted',
                    'approved_by_user_id' => $actor->id,
                    'approved_at' => now(),
                    'updated_at' => now(),
                ]);

                $row = DB::table('fx_stock_movements')->where('id', $this->rowInt($movement, 'id'))->first();
                if (! is_object($row)) {
                    throw new InvalidArgumentException('Stock movement could not be reloaded.');
                }

                return $row;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['fx_stock_movement' => [$exception->getMessage()]]);
        }

        $this->audit($request, 'fx.stock_movement.approved', [
            'movement_public_id' => $movementPublicId,
        ]);

        return $this->respondSuccess($this->stockMovementPayload($row), 'FX stock movement approved');
    }

    public function storeReconciliation(Request $request, string $tillPublicId): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasRole('platform-admin')) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'business_date' => ['required', 'date'],
            'currency' => ['required', 'string', 'size:3', 'exists:currencies,code'],
            'counted_minor' => ['required', 'integer', 'min:0'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ])->validate();

        try {
            $row = DB::transaction(function () use ($actor, $tillPublicId, $validated): object {
                $till = $this->lockTill($tillPublicId);
                $this->assertExchangeTill($till);
                $currency = mb_strtoupper((string) $validated['currency']);
                $this->assertActiveCurrency($currency);
                $counted = (int) $validated['counted_minor'];

                $balance = $this->lockOrCreateStockBalance($till->id, $currency);
                $theoretical = $this->rowInt($balance, 'current_balance_minor');
                $variance = $counted - $theoretical;
                $status = $variance === 0 ? 'closed' : 'variance_blocked';

                $id = DB::table('fx_reconciliations')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'till_id' => $till->id,
                    'agency_id' => $till->agency_id,
                    'business_date' => (string) $validated['business_date'],
                    'currency' => $currency,
                    'counted_minor' => $counted,
                    'theoretical_minor' => $theoretical,
                    'variance_minor' => $variance,
                    'status' => $status,
                    'notes' => $this->nullableString($validated['notes'] ?? null),
                    'closed_by_user_id' => $status === 'closed' ? $actor->id : null,
                    'closed_at' => $status === 'closed' ? now() : null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                if ($status === 'closed') {
                    DB::table('till_currency_balances')->where('id', $this->rowInt($balance, 'id'))->update([
                        'last_closing_balance_minor' => $counted,
                        'last_reconciled_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                $row = DB::table('fx_reconciliations')->where('id', $id)->first();
                if (! is_object($row)) {
                    throw new InvalidArgumentException('Reconciliation could not be reloaded.');
                }

                return $row;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['fx_reconciliation' => [$exception->getMessage()]]);
        }

        $this->audit($request, 'fx.reconciliation.recorded', [
            'reconciliation_public_id' => $this->rowString($row, 'public_id'),
            'status' => $this->rowString($row, 'status'),
        ]);

        return $this->respondCreated($this->reconciliationPayload($row), 'FX reconciliation recorded');
    }

    public function register(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasRole('platform-admin')) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->query(), [
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'agency_public_id' => ['sometimes', 'nullable', 'string', 'exists:agencies,public_id'],
            'currency' => ['sometimes', 'nullable', 'string', 'size:3'],
        ])->validate();

        $query = DB::table('fx_transactions as tx')
            ->join('agencies as agency', 'agency.id', '=', 'tx.agency_id')
            ->whereBetween('tx.transaction_date', [(string) $validated['from'], (string) $validated['to']])
            ->orderBy('tx.transaction_date')
            ->orderBy('tx.id');

        $agencyId = $this->agencyIdByPublicId($validated['agency_public_id'] ?? null);
        if ($agencyId !== null) {
            $query->where('tx.agency_id', $agencyId);
        }
        if (is_string($validated['currency'] ?? null) && $validated['currency'] !== '') {
            $query->where('tx.foreign_currency', mb_strtoupper($validated['currency']));
        }

        $entries = $query->get([
            'tx.public_id',
            'tx.transaction_date',
            'tx.transaction_number',
            'tx.slip_number',
            'tx.register_number',
            'tx.direction',
            'tx.foreign_currency',
            'tx.foreign_amount_minor',
            'tx.local_currency',
            'tx.local_amount_minor',
            'tx.reference_rate',
            'tx.applied_rate',
            'tx.margin_rate',
            'tx.margin_amount_minor',
            'tx.client_name',
            'tx.client_identity_number',
            'tx.client_identity_type',
            'tx.client_identity_issuing_country',
            'tx.status',
            'agency.code as agency_code',
        ])->map(fn (object $row): array => $this->registerEntryPayload($row))->all();

        return $this->respondSuccess([
            'from' => (string) $validated['from'],
            'to' => (string) $validated['to'],
            'entries' => $entries,
        ], 'FX register generated');
    }

    private function requirePlatformAdmin(Request $request): bool
    {
        $actor = $request->user();

        return $actor instanceof User && $actor->hasRole('platform-admin');
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

    private function assertNoActiveRateOverlap(
        string $base,
        string $quote,
        string $effectiveOn,
        ?string $effectiveTo,
        ?int $ignoreRateId,
    ): void {
        $newEnd = $effectiveTo ?? '9999-12-31';
        $query = DB::table('exchange_rates')
            ->where('base_currency', $base)
            ->where('quote_currency', $quote)
            ->where('status', 'active')
            ->where('effective_on', '<=', $newEnd)
            ->where(function ($builder) use ($effectiveOn): void {
                $builder->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', $effectiveOn);
            });

        if ($ignoreRateId !== null) {
            $query->where('id', '<>', $ignoreRateId);
        }

        if ($query->exists()) {
            throw new InvalidArgumentException('An active rate already covers this currency pair and effective window.');
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

    private function createReversingEntry(JournalEntry $original, User $actor, string $idempotencyKey): JournalEntry
    {
        $reversal = JournalEntry::query()->create([
            'public_id' => (string) Str::ulid(),
            'reference' => 'REV-'.$original->reference,
            'business_date' => now()->toDateString(),
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

    private function agencyIdByPublicId(mixed $publicId): ?int
    {
        if (! is_string($publicId) || $publicId === '') {
            return null;
        }
        $agency = DB::table('agencies')->where('public_id', $publicId)->first(['id']);

        return is_object($agency) && is_numeric($agency->id) ? (int) $agency->id : null;
    }

    private function clientIdByPublicId(mixed $publicId): ?int
    {
        if (! is_string($publicId) || $publicId === '') {
            return null;
        }
        $client = DB::table('clients')->where('public_id', $publicId)->first(['id']);

        return is_object($client) && is_numeric($client->id) ? (int) $client->id : null;
    }

    private function audit(Request $request, string $event, mixed $properties): void
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            return;
        }
        $this->securityAudit->record($event, actor: $actor, properties: is_array($properties) ? $properties : [], request: $request);
    }

    /**
     * @return array<string, mixed>
     */
    private function authorizationPayload(object $row): array
    {
        return [
            'public_id' => $this->rowString($row, 'public_id'),
            'authorization_reference' => $this->rowString($row, 'authorization_reference'),
            'authorization_type' => $this->rowString($row, 'authorization_type'),
            'effective_from' => $this->rowNullableString($row, 'effective_from'),
            'effective_to' => $this->rowNullableString($row, 'effective_to'),
            'status' => $this->rowString($row, 'status'),
            'supports_purchase' => (bool) (((array) $row)['supports_purchase'] ?? false),
            'supports_sale' => (bool) (((array) $row)['supports_sale'] ?? false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function currencyPayload(object $row): array
    {
        return [
            'code' => $this->rowString($row, 'code'),
            'name' => $this->rowString($row, 'name'),
            'minor_unit' => $this->rowInt($row, 'minor_unit'),
            'is_base_currency' => (bool) (((array) $row)['is_base_currency'] ?? false),
            'status' => $this->rowString($row, 'status'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ratePayload(object $row): array
    {
        return [
            'public_id' => $this->rowString($row, 'public_id'),
            'base_currency' => $this->rowString($row, 'base_currency'),
            'quote_currency' => $this->rowString($row, 'quote_currency'),
            'reference_rate' => $this->rowNullableString($row, 'reference_rate'),
            'buy_rate' => $this->rowNullableString($row, 'buy_rate'),
            'sell_rate' => $this->rowNullableString($row, 'sell_rate'),
            'buy_margin_rate' => $this->rowNullableString($row, 'buy_margin_rate'),
            'sell_margin_rate' => $this->rowNullableString($row, 'sell_margin_rate'),
            'effective_on' => $this->rowNullableString($row, 'effective_on'),
            'effective_to' => $this->rowNullableString($row, 'effective_to'),
            'status' => $this->rowString($row, 'status'),
        ];
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

    /**
     * @return array<string, mixed>
     */
    private function stockMovementPayload(object $row): array
    {
        return [
            'public_id' => $this->rowString($row, 'public_id'),
            'movement_type' => $this->rowString($row, 'movement_type'),
            'currency' => $this->rowString($row, 'currency'),
            'amount_minor' => $this->rowInt($row, 'amount_minor'),
            'movement_date' => $this->rowNullableString($row, 'movement_date'),
            'status' => $this->rowString($row, 'status'),
            'approved_at' => $this->rowNullableString($row, 'approved_at'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reconciliationPayload(object $row): array
    {
        return [
            'public_id' => $this->rowString($row, 'public_id'),
            'business_date' => $this->rowNullableString($row, 'business_date'),
            'currency' => $this->rowString($row, 'currency'),
            'counted_minor' => $this->rowInt($row, 'counted_minor'),
            'theoretical_minor' => $this->rowInt($row, 'theoretical_minor'),
            'variance_minor' => $this->rowInt($row, 'variance_minor'),
            'status' => $this->rowString($row, 'status'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function registerEntryPayload(object $row): array
    {
        return [
            'public_id' => $this->rowString($row, 'public_id'),
            'agency_code' => $this->rowString($row, 'agency_code'),
            'transaction_date' => $this->rowNullableString($row, 'transaction_date'),
            'transaction_number' => $this->rowString($row, 'transaction_number'),
            'slip_number' => $this->rowNullableString($row, 'slip_number'),
            'register_number' => $this->rowNullableString($row, 'register_number'),
            'direction' => $this->rowString($row, 'direction'),
            'foreign_currency' => $this->rowString($row, 'foreign_currency'),
            'foreign_amount_minor' => $this->rowInt($row, 'foreign_amount_minor'),
            'local_currency' => $this->rowString($row, 'local_currency'),
            'local_amount_minor' => $this->rowInt($row, 'local_amount_minor'),
            'reference_rate' => $this->rowNullableString($row, 'reference_rate'),
            'applied_rate' => $this->rowNullableString($row, 'applied_rate'),
            'margin_rate' => $this->rowNullableString($row, 'margin_rate'),
            'margin_amount_minor' => $this->rowInt($row, 'margin_amount_minor'),
            'client_name' => $this->rowNullableString($row, 'client_name'),
            'client_identity_number' => $this->rowNullableString($row, 'client_identity_number'),
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

    private function boolValue(mixed $value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value !== 0;
        }
        if (is_string($value)) {
            return in_array(mb_strtolower($value), ['1', 'true', 'yes', 'on'], true);
        }

        return $default;
    }

    private function jsonOrNull(mixed $value): ?string
    {
        return is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR) : null;
    }
}
