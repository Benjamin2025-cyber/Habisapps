<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\JournalEntries\CreateJournalEntryReversal;
use App\Http\Controllers\BaseController;
use App\Http\Requests\StoreCashDepositRequest;
use App\Http\Requests\StoreCashManualJournalRequest;
use App\Http\Requests\StoreCashWithdrawalRequest;
use App\Http\Resources\JournalEntryResource;
use App\Http\Resources\TellerTransactionResource;
use App\Models\CustomerAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\OperationCode;
use App\Models\TellerSession;
use App\Models\TellerTransaction;
use App\Models\Till;
use App\Models\User;
use App\Support\Accounting\AccountingBalanceCalculator;
use App\Support\Finance\PhysicalCashAmount;
use App\Support\Security\SecurityAudit;
use App\Support\Staff\StaffAgencyScope;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class TellerTransactionController extends BaseController
{
    public function __construct(
        private readonly SecurityAudit $securityAudit,
        private readonly StaffAgencyScope $staffAgencyScope,
        private readonly AccountingBalanceCalculator $balanceCalculator,
    ) {}

    #[Response(status: 201, type: 'array{success: bool, message: string, data: array{teller_transaction: \App\Http\Resources\TellerTransactionResource, journal_entry: \App\Http\Resources\JournalEntryResource}, errors: null, meta: null}')]
    public function storeDeposit(StoreCashDepositRequest $request, TellerSession $tellerSession): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        if (! $this->canUseSession($actor, $tellerSession)) {
            return $this->respondForbidden('Cash deposit can only be posted in your open teller session scope.');
        }

        $tellerSession->loadMissing(['till']);
        $till = $tellerSession->till;
        if (! $till instanceof Till || $till->ledger_account_id === null) {
            return $this->respondUnprocessable(errors: ['till' => ['The teller session till must have a cash ledger account before posting deposits.']]);
        }

        if ($tellerSession->status !== TellerSession::STATUS_OPEN || $till->daily_state !== Till::DAILY_STATE_OPEN) {
            return $this->respondUnprocessable(errors: ['teller_session' => ['Cash deposits require an open teller session and open till.']]);
        }

        $customerAccount = CustomerAccount::query()
            ->with(['ledgerAccount'])
            ->where('public_id', $request->string('customer_account_public_id')->toString())
            ->first();
        if (! $customerAccount instanceof CustomerAccount) {
            return $this->respondUnprocessable(errors: ['customer_account_public_id' => ['The selected customer account is invalid.']]);
        }

        if ($customerAccount->status !== CustomerAccount::STATUS_ACTIVE
            || $customerAccount->agency_id !== $tellerSession->agency_id
            || $customerAccount->ledger_account_id === null) {
            return $this->respondUnprocessable(errors: ['customer_account_public_id' => ['The selected customer account must be active, agency-scoped, and mapped to a ledger account.']]);
        }

        $customerLedger = $customerAccount->ledgerAccount;
        $tillLedger = LedgerAccount::query()->whereKey($till->ledger_account_id)->first();
        if (! $customerLedger instanceof LedgerAccount || ! $tillLedger instanceof LedgerAccount) {
            return $this->respondUnprocessable(errors: ['ledger_account' => ['Both till and customer account ledger mappings are required.']]);
        }

        if (! $this->ledgerIsActiveInAgency($customerLedger, $tellerSession->agency_id)
            || ! $this->ledgerIsActiveInAgency($tillLedger, $tellerSession->agency_id)) {
            return $this->respondUnprocessable(errors: ['ledger_account' => ['Ledger accounts must be active and match the teller session agency.']]);
        }

        $amountMinor = $request->integer('amount_minor');
        $currency = $this->normalizedCurrency($request->input('currency', $tellerSession->currency ?? $till->currency));
        if ($currency !== $customerAccount->currency || $currency !== $tellerSession->currency) {
            return $this->respondUnprocessable(errors: ['currency' => ['Deposit currency must match the teller session and customer account currency.']]);
        }
        if (! PhysicalCashAmount::validMinorAmount($amountMinor, $currency)) {
            return $this->respondUnprocessable(errors: ['amount_minor' => [PhysicalCashAmount::validationMessage($currency)]]);
        }

        $idempotencyKey = $this->idempotencyKey($request->header('Idempotency-Key'), $request->input('idempotency_key'));
        if ($idempotencyKey !== null) {
            $existing = TellerTransaction::query()
                ->with(['tellerSession', 'till', 'customerAccount', 'journalEntry.lines'])
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existing instanceof TellerTransaction) {
                return $this->transactionResponse($existing, 'Cash deposit already posted successfully');
            }
        }

        $result = DB::transaction(function () use ($request, $actor, $tellerSession, $till, $customerAccount, $customerLedger, $tillLedger, $amountMinor, $currency, $idempotencyKey): TellerTransaction {
            $publicId = (string) Str::ulid();
            $reference = 'CD-'.Str::upper(Str::random(10));

            $transaction = TellerTransaction::query()->create([
                'public_id' => $publicId,
                'teller_session_id' => $tellerSession->id,
                'agency_id' => $tellerSession->agency_id,
                'transaction_date' => $tellerSession->business_date,
                'till_id' => $till->id,
                'transaction_type' => TellerTransaction::TYPE_CASH_DEPOSIT,
                'client_id' => $customerAccount->client_id,
                'customer_account_id' => $customerAccount->id,
                'amount_minor' => $amountMinor,
                'currency' => $currency,
                'status' => TellerTransaction::STATUS_POSTED,
                'reference' => $reference,
                'event_number' => $reference,
                'idempotency_key' => $idempotencyKey,
                'operation_code' => $request->input('operation_code', 'cash_deposit'),
                'depositor_name' => $request->input('depositor_name'),
                'depositor_address' => $request->input('depositor_address'),
                'description' => $request->input('description'),
            ]);

            $journalEntry = JournalEntry::query()->create([
                'public_id' => (string) Str::ulid(),
                'reference' => 'JE-'.$reference,
                'business_date' => $tellerSession->business_date,
                'posted_at' => now(),
                'agency_id' => $tellerSession->agency_id,
                'source_module' => 'cash_operations',
                'source_type' => TellerTransaction::TYPE_CASH_DEPOSIT,
                'source_public_id' => $transaction->public_id,
                'status' => JournalEntry::STATUS_POSTED,
                'description' => $request->input('description', 'Cash deposit '.$customerAccount->account_number),
                'created_by_user_id' => $actor->id,
                'posted_by_user_id' => $actor->id,
                'idempotency_key' => $idempotencyKey,
            ]);

            JournalLine::query()->create([
                'public_id' => (string) Str::ulid(),
                'agency_id' => $tellerSession->agency_id,
                'journal_entry_id' => $journalEntry->id,
                'ledger_account_id' => $tillLedger->id,
                'customer_account_id' => null,
                'debit_minor' => $amountMinor,
                'credit_minor' => 0,
                'currency' => $currency,
                'line_memo' => 'Cash received into till',
            ]);

            JournalLine::query()->create([
                'public_id' => (string) Str::ulid(),
                'agency_id' => $tellerSession->agency_id,
                'journal_entry_id' => $journalEntry->id,
                'ledger_account_id' => $customerLedger->id,
                'customer_account_id' => $customerAccount->id,
                'debit_minor' => 0,
                'credit_minor' => $amountMinor,
                'currency' => $currency,
                'line_memo' => 'Cash deposited to customer account',
            ]);

            $transaction->update(['journal_entry_id' => $journalEntry->id]);

            return $transaction->refresh();
        });

        $this->securityAudit->record('cash.deposit.posted', actor: $actor, subject: $result, properties: [
            'teller_session_public_id' => $tellerSession->public_id,
            'customer_account_public_id' => $customerAccount->public_id,
            'amount_minor' => $amountMinor,
            'currency' => $currency,
        ], request: $request);

        return $this->transactionResponse($result, 'Cash deposit posted successfully');
    }

    #[Response(status: 201, type: 'array{success: bool, message: string, data: array{teller_transaction: \App\Http\Resources\TellerTransactionResource, journal_entry: \App\Http\Resources\JournalEntryResource}, errors: null, meta: null}')]
    public function storeWithdrawal(StoreCashWithdrawalRequest $request, TellerSession $tellerSession): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        if (! $this->canUseSession($actor, $tellerSession)) {
            return $this->respondForbidden('Cash withdrawal can only be posted in your open teller session scope.');
        }

        $tellerSession->loadMissing(['till']);
        $till = $tellerSession->till;
        if (! $till instanceof Till || $till->ledger_account_id === null) {
            return $this->respondUnprocessable(errors: ['till' => ['The teller session till must have a cash ledger account before posting withdrawals.']]);
        }

        if ($tellerSession->status !== TellerSession::STATUS_OPEN || $till->daily_state !== Till::DAILY_STATE_OPEN) {
            return $this->respondUnprocessable(errors: ['teller_session' => ['Cash withdrawals require an open teller session and open till.']]);
        }

        $customerAccount = CustomerAccount::query()
            ->with(['ledgerAccount', 'accountProduct'])
            ->where('public_id', $request->string('customer_account_public_id')->toString())
            ->first();
        if (! $customerAccount instanceof CustomerAccount) {
            return $this->respondUnprocessable(errors: ['customer_account_public_id' => ['The selected customer account is invalid.']]);
        }

        if ($customerAccount->status !== CustomerAccount::STATUS_ACTIVE
            || $customerAccount->agency_id !== $tellerSession->agency_id
            || $customerAccount->ledger_account_id === null) {
            return $this->respondUnprocessable(errors: ['customer_account_public_id' => ['The selected customer account must be active, agency-scoped, and mapped to a ledger account.']]);
        }

        $customerLedger = $customerAccount->ledgerAccount;
        $tillLedger = LedgerAccount::query()->whereKey($till->ledger_account_id)->first();
        if (! $customerLedger instanceof LedgerAccount || ! $tillLedger instanceof LedgerAccount) {
            return $this->respondUnprocessable(errors: ['ledger_account' => ['Both till and customer account ledger mappings are required.']]);
        }

        if (! $this->ledgerIsActiveInAgency($customerLedger, $tellerSession->agency_id)
            || ! $this->ledgerIsActiveInAgency($tillLedger, $tellerSession->agency_id)) {
            return $this->respondUnprocessable(errors: ['ledger_account' => ['Ledger accounts must be active and match the teller session agency.']]);
        }

        $amountMinor = $request->integer('amount_minor');
        $currency = $this->normalizedCurrency($request->input('currency', $tellerSession->currency ?? $till->currency));
        if ($currency !== $customerAccount->currency || $currency !== $tellerSession->currency) {
            return $this->respondUnprocessable(errors: ['currency' => ['Withdrawal currency must match the teller session and customer account currency.']]);
        }
        if (! PhysicalCashAmount::validMinorAmount($amountMinor, $currency)) {
            return $this->respondUnprocessable(errors: ['amount_minor' => [PhysicalCashAmount::validationMessage($currency)]]);
        }

        if ($till->max_withdrawal_limit_minor !== null && $amountMinor > $till->max_withdrawal_limit_minor) {
            return $this->respondUnprocessable(errors: ['amount_minor' => ['Withdrawal amount exceeds the till maximum withdrawal limit.']]);
        }

        if ($amountMinor > $this->postedTillBalanceMinor($tellerSession)) {
            return $this->respondUnprocessable(errors: ['amount_minor' => ['Withdrawal amount exceeds the posted till cash balance.']]);
        }

        $availableBalance = $this->balanceCalculator->availableForCustomerAccount($customerAccount, $currency)['available_balance_minor'];
        if ($amountMinor > $availableBalance) {
            return $this->respondUnprocessable(errors: ['amount_minor' => ['Withdrawal amount exceeds the customer account available balance.']]);
        }

        $idempotencyKey = $this->idempotencyKey($request->header('Idempotency-Key'), $request->input('idempotency_key'));
        if ($idempotencyKey !== null) {
            $existing = TellerTransaction::query()
                ->with(['tellerSession', 'till', 'customerAccount', 'journalEntry.lines'])
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existing instanceof TellerTransaction) {
                return $this->transactionResponse($existing, 'Cash withdrawal already posted successfully');
            }
        }

        $result = DB::transaction(function () use ($request, $actor, $tellerSession, $till, $customerAccount, $customerLedger, $tillLedger, $amountMinor, $currency, $idempotencyKey): TellerTransaction {
            $reference = 'CW-'.Str::upper(Str::random(10));

            $transaction = TellerTransaction::query()->create([
                'public_id' => (string) Str::ulid(),
                'teller_session_id' => $tellerSession->id,
                'agency_id' => $tellerSession->agency_id,
                'transaction_date' => $tellerSession->business_date,
                'till_id' => $till->id,
                'transaction_type' => TellerTransaction::TYPE_CASH_WITHDRAWAL,
                'client_id' => $customerAccount->client_id,
                'customer_account_id' => $customerAccount->id,
                'amount_minor' => $amountMinor,
                'currency' => $currency,
                'status' => TellerTransaction::STATUS_POSTED,
                'reference' => $reference,
                'event_number' => $reference,
                'idempotency_key' => $idempotencyKey,
                'operation_code' => $request->input('operation_code', 'cash_withdrawal'),
                'description' => $request->input('description'),
            ]);

            $journalEntry = JournalEntry::query()->create([
                'public_id' => (string) Str::ulid(),
                'reference' => 'JE-'.$reference,
                'business_date' => $tellerSession->business_date,
                'posted_at' => now(),
                'agency_id' => $tellerSession->agency_id,
                'source_module' => 'cash_operations',
                'source_type' => TellerTransaction::TYPE_CASH_WITHDRAWAL,
                'source_public_id' => $transaction->public_id,
                'status' => JournalEntry::STATUS_POSTED,
                'description' => $request->input('description', 'Cash withdrawal '.$customerAccount->account_number),
                'created_by_user_id' => $actor->id,
                'posted_by_user_id' => $actor->id,
                'idempotency_key' => $idempotencyKey,
            ]);

            JournalLine::query()->create([
                'public_id' => (string) Str::ulid(),
                'agency_id' => $tellerSession->agency_id,
                'journal_entry_id' => $journalEntry->id,
                'ledger_account_id' => $customerLedger->id,
                'customer_account_id' => $customerAccount->id,
                'debit_minor' => $amountMinor,
                'credit_minor' => 0,
                'currency' => $currency,
                'line_memo' => 'Cash withdrawn from customer account',
            ]);

            JournalLine::query()->create([
                'public_id' => (string) Str::ulid(),
                'agency_id' => $tellerSession->agency_id,
                'journal_entry_id' => $journalEntry->id,
                'ledger_account_id' => $tillLedger->id,
                'customer_account_id' => null,
                'debit_minor' => 0,
                'credit_minor' => $amountMinor,
                'currency' => $currency,
                'line_memo' => 'Cash paid out from till',
            ]);

            $transaction->update(['journal_entry_id' => $journalEntry->id]);

            return $transaction->refresh();
        });

        $this->securityAudit->record('cash.withdrawal.posted', actor: $actor, subject: $result, properties: [
            'teller_session_public_id' => $tellerSession->public_id,
            'customer_account_public_id' => $customerAccount->public_id,
            'amount_minor' => $amountMinor,
            'currency' => $currency,
        ], request: $request);

        return $this->transactionResponse($result, 'Cash withdrawal posted successfully');
    }

    #[Response(status: 201, type: 'array{success: bool, message: string, data: array{teller_transaction: \App\Http\Resources\TellerTransactionResource, journal_entry: \App\Http\Resources\JournalEntryResource}, errors: null, meta: null}')]
    public function reverse(Request $request, TellerTransaction $tellerTransaction, CreateJournalEntryReversal $createJournalEntryReversal): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('reverse', $tellerTransaction)) {
            return $this->respondForbidden();
        }

        if (! $this->canAccessTransaction($actor, $tellerTransaction)) {
            return $this->respondForbidden('Cash transaction reversal can only be posted in your teller scope.');
        }

        if ($tellerTransaction->status !== TellerTransaction::STATUS_POSTED || $tellerTransaction->journal_entry_id === null) {
            return $this->respondUnprocessable(errors: ['teller_transaction' => ['Only posted teller transactions linked to a journal entry can be reversed.']]);
        }

        if (TellerTransaction::query()->where('reversal_of_teller_transaction_id', $tellerTransaction->id)->first() instanceof TellerTransaction) {
            return $this->respondUnprocessable(errors: ['teller_transaction' => ['This teller transaction has already been reversed.']]);
        }

        $journalEntry = JournalEntry::query()->with(['lines'])->whereKey($tellerTransaction->journal_entry_id)->first();
        if (! $journalEntry instanceof JournalEntry || $journalEntry->status !== JournalEntry::STATUS_POSTED) {
            return $this->respondUnprocessable(errors: ['journal_entry' => ['The linked journal entry must be posted before it can be reversed.']]);
        }

        $reversalJournal = $createJournalEntryReversal->execute($actor, $journalEntry);
        $reversal = TellerTransaction::query()->create([
            'public_id' => (string) Str::ulid(),
            'teller_session_id' => $tellerTransaction->teller_session_id,
            'agency_id' => $tellerTransaction->agency_id,
            'transaction_date' => $tellerTransaction->transaction_date,
            'till_id' => $tellerTransaction->till_id,
            'transaction_type' => TellerTransaction::TYPE_REVERSAL,
            'client_id' => $tellerTransaction->client_id,
            'customer_account_id' => $tellerTransaction->customer_account_id,
            'loan_id' => $tellerTransaction->loan_id,
            'amount_minor' => $tellerTransaction->amount_minor,
            'currency' => $tellerTransaction->currency,
            'status' => TellerTransaction::STATUS_POSTED,
            'reference' => $tellerTransaction->reference.'-REV',
            'event_number' => $tellerTransaction->event_number !== null ? $tellerTransaction->event_number.'-REV' : null,
            'journal_entry_id' => $reversalJournal->id,
            'operation_code' => 'cash_reversal',
            'description' => 'Reversal of '.$tellerTransaction->reference,
            'reversal_of_teller_transaction_id' => $tellerTransaction->id,
        ]);

        $tellerTransaction->update(['status' => TellerTransaction::STATUS_REVERSED]);

        $this->securityAudit->record('cash.transaction.reversed', actor: $actor, subject: $reversal, properties: [
            'original_transaction_public_id' => $tellerTransaction->public_id,
            'reversal_journal_entry_public_id' => $reversalJournal->public_id,
        ], request: $request);

        return $this->transactionResponse($reversal, 'Cash transaction reversed successfully');
    }

    #[Response(status: 201, type: 'array{success: bool, message: string, data: array{teller_transaction: \App\Http\Resources\TellerTransactionResource, journal_entry: \App\Http\Resources\JournalEntryResource}, errors: null, meta: null}')]
    public function storeManualJournal(StoreCashManualJournalRequest $request, TellerSession $tellerSession): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        if (! $this->canUseSession($actor, $tellerSession)) {
            return $this->respondForbidden('Manual cash journal can only be created in your open teller session scope.');
        }

        $tellerSession->loadMissing(['till']);
        $till = $tellerSession->till;
        if (! $till instanceof Till || $till->ledger_account_id === null) {
            return $this->respondUnprocessable(errors: ['till' => ['The teller session till must have a cash ledger account before creating manual cash journals.']]);
        }

        if ($tellerSession->status !== TellerSession::STATUS_OPEN || $till->daily_state !== Till::DAILY_STATE_OPEN) {
            return $this->respondUnprocessable(errors: ['teller_session' => ['Manual cash journals require an open teller session and open till.']]);
        }

        $currency = $this->normalizedCurrency($request->input('currency', $tellerSession->currency ?? $till->currency));
        if ($currency !== $tellerSession->currency) {
            return $this->respondUnprocessable(errors: ['currency' => ['Manual cash journal currency must match the teller session currency.']]);
        }

        $operationCode = $this->resolveCashOperationCode($request->input('operation_code_public_id'));
        if ($operationCode === false) {
            return $this->respondUnprocessable(errors: ['operation_code_public_id' => ['The selected operation code must be active and belong to the cash module.']]);
        }

        $prepared = $this->prepareManualJournalLines($request->input('lines'), $tellerSession->agency_id, $currency, $till->ledger_account_id);
        if ($prepared['errors'] !== []) {
            return $this->respondUnprocessable(errors: $prepared['errors']);
        }

        $idempotencyKey = $this->idempotencyKey($request->header('Idempotency-Key'), $request->input('idempotency_key'));
        if ($idempotencyKey !== null) {
            $existing = TellerTransaction::query()
                ->with(['tellerSession', 'till', 'customerAccount', 'journalEntry.lines'])
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existing instanceof TellerTransaction) {
                return $this->transactionResponse($existing, 'Manual cash journal already created successfully');
            }
        }

        $result = DB::transaction(function () use ($request, $actor, $tellerSession, $till, $currency, $operationCode, $prepared, $idempotencyKey): TellerTransaction {
            $reference = is_string($request->input('reference')) && $request->input('reference') !== ''
                ? $request->string('reference')->toString()
                : 'OD-'.Str::upper(Str::random(10));

            $journalEntry = JournalEntry::query()->create([
                'public_id' => (string) Str::ulid(),
                'reference' => 'JE-'.$reference,
                'business_date' => $tellerSession->business_date,
                'posted_at' => null,
                'agency_id' => $tellerSession->agency_id,
                'source_module' => 'cash_operations',
                'source_type' => TellerTransaction::TYPE_MANUAL_JOURNAL,
                'source_public_id' => $tellerSession->public_id,
                'status' => JournalEntry::STATUS_SUBMITTED,
                'description' => $request->input('description', 'Manual cash journal '.$reference),
                'created_by_user_id' => $actor->id,
                'submitted_by_user_id' => $actor->id,
                'submitted_at' => now(),
                'idempotency_key' => $idempotencyKey,
            ]);

            foreach ($prepared['lines'] as $line) {
                JournalLine::query()->create([
                    'public_id' => (string) Str::ulid(),
                    'agency_id' => $tellerSession->agency_id,
                    'journal_entry_id' => $journalEntry->id,
                    'ledger_account_id' => $line['ledger_account_id'],
                    'customer_account_id' => $line['customer_account_id'],
                    'debit_minor' => $line['debit_minor'],
                    'credit_minor' => $line['credit_minor'],
                    'currency' => $currency,
                    'line_memo' => $line['memo'],
                ]);
            }

            $transaction = TellerTransaction::query()->create([
                'public_id' => (string) Str::ulid(),
                'teller_session_id' => $tellerSession->id,
                'agency_id' => $tellerSession->agency_id,
                'transaction_date' => $tellerSession->business_date,
                'till_id' => $till->id,
                'transaction_type' => TellerTransaction::TYPE_MANUAL_JOURNAL,
                'amount_minor' => $prepared['debit_total_minor'],
                'currency' => $currency,
                'status' => 'pending_review',
                'reference' => $reference,
                'event_number' => $reference,
                'idempotency_key' => $idempotencyKey,
                'journal_entry_id' => $journalEntry->id,
                'operation_code_id' => $operationCode instanceof OperationCode ? $operationCode->id : null,
                'operation_code' => $operationCode instanceof OperationCode ? $operationCode->code : null,
                'description' => $request->input('description'),
            ]);

            $journalEntry->update(['source_public_id' => $transaction->public_id]);

            return $transaction->refresh();
        });

        $this->securityAudit->record('cash.manual_journal.submitted', actor: $actor, subject: $result, properties: [
            'teller_session_public_id' => $tellerSession->public_id,
            'amount_minor' => $prepared['debit_total_minor'],
            'currency' => $currency,
        ], request: $request);

        return $this->transactionResponse($result, 'Manual cash journal submitted for approval successfully');
    }

    private function canUseSession(User $actor, TellerSession $session): bool
    {
        if ($actor->hasRole('platform-admin')) {
            return true;
        }

        if ($this->staffAgencyScope->currentAgencyId($actor) !== $session->agency_id) {
            return false;
        }

        return ! $actor->hasRole('teller') || $actor->id === $session->teller_user_id;
    }

    private function canAccessTransaction(User $actor, TellerTransaction $transaction): bool
    {
        if ($actor->hasRole('platform-admin')) {
            return true;
        }

        if ($this->staffAgencyScope->currentAgencyId($actor) !== $transaction->agency_id) {
            return false;
        }

        $session = TellerSession::query()->whereKey($transaction->teller_session_id)->first();

        return ! $actor->hasRole('teller') || ($session instanceof TellerSession && $actor->id === $session->teller_user_id);
    }

    private function ledgerIsActiveInAgency(LedgerAccount $ledgerAccount, int $agencyId): bool
    {
        return $ledgerAccount->status === LedgerAccount::STATUS_ACTIVE && $ledgerAccount->agency_id === $agencyId;
    }

    private function normalizedCurrency(mixed $currency): string
    {
        return is_string($currency) && $currency !== '' ? strtoupper($currency) : 'XAF';
    }

    private function idempotencyKey(mixed $headerValue, mixed $bodyValue): ?string
    {
        if (is_string($headerValue) && $headerValue !== '') {
            return $headerValue;
        }

        return is_string($bodyValue) && $bodyValue !== '' ? $bodyValue : null;
    }

    private function postedTillBalanceMinor(TellerSession $session): int
    {
        $opening = $session->opening_declaration_minor ?? 0;
        $transactions = DB::table('teller_transactions')
            ->where('teller_session_id', $session->id)
            ->where('status', TellerTransaction::STATUS_POSTED)
            ->get(['transaction_type', 'amount_minor']);

        $movement = 0;
        foreach ($transactions as $transaction) {
            $type = is_string($transaction->transaction_type) ? $transaction->transaction_type : '';
            $amount = is_numeric($transaction->amount_minor) ? (int) $transaction->amount_minor : 0;

            if ($type === TellerTransaction::TYPE_CASH_DEPOSIT) {
                $movement += $amount;
            }

            if ($type === TellerTransaction::TYPE_CASH_WITHDRAWAL) {
                $movement -= $amount;
            }
        }

        return $opening + $movement;
    }

    private function resolveCashOperationCode(mixed $publicId): OperationCode|false|null
    {
        if ($publicId === null || $publicId === '') {
            return null;
        }

        if (! is_string($publicId)) {
            return false;
        }

        $operationCode = OperationCode::query()->where('public_id', $publicId)->first();
        if (! $operationCode instanceof OperationCode
            || $operationCode->status !== OperationCode::STATUS_ACTIVE
            || $operationCode->module !== 'cash') {
            return false;
        }

        return $operationCode;
    }

    /**
     * @return array{errors: array<string, array<int, string>>, lines: array<int, array{ledger_account_id:int, customer_account_id:int|null, debit_minor:int, credit_minor:int, memo:string|null}>, debit_total_minor:int}
     */
    private function prepareManualJournalLines(mixed $rawLines, int $agencyId, string $currency, int $tillLedgerAccountId): array
    {
        if (! is_array($rawLines)) {
            return ['errors' => ['lines' => ['Manual journal lines are required.']], 'lines' => [], 'debit_total_minor' => 0];
        }

        $lines = [];
        $debitTotal = 0;
        $creditTotal = 0;
        $hasTillLine = false;
        foreach ($rawLines as $index => $rawLine) {
            if (! is_array($rawLine)) {
                return ['errors' => ['lines.'.$index => ['Each manual journal line must be an object.']], 'lines' => [], 'debit_total_minor' => 0];
            }

            $ledgerPublicId = $rawLine['ledger_account_public_id'] ?? null;
            $direction = $rawLine['direction'] ?? null;
            $amount = $rawLine['amount_minor'] ?? null;
            if (! is_string($ledgerPublicId) || ! is_string($direction) || ! is_int($amount)) {
                return ['errors' => ['lines.'.$index => ['Each manual journal line must include a ledger account, direction, and integer amount.']], 'lines' => [], 'debit_total_minor' => 0];
            }

            $ledgerAccount = LedgerAccount::query()->where('public_id', $ledgerPublicId)->first();
            if (! $ledgerAccount instanceof LedgerAccount || ! $this->ledgerIsActiveInAgency($ledgerAccount, $agencyId)) {
                return ['errors' => ['lines.'.$index.'.ledger_account_public_id' => ['The selected ledger account must be active and match the teller session agency.']], 'lines' => [], 'debit_total_minor' => 0];
            }

            $customerAccountId = null;
            $customerPublicId = $rawLine['customer_account_public_id'] ?? null;
            if ($customerPublicId !== null && $customerPublicId !== '') {
                if (! is_string($customerPublicId)) {
                    return ['errors' => ['lines.'.$index.'.customer_account_public_id' => ['The selected customer account is invalid.']], 'lines' => [], 'debit_total_minor' => 0];
                }

                $customerAccount = CustomerAccount::query()->where('public_id', $customerPublicId)->first();
                if (! $customerAccount instanceof CustomerAccount
                    || $customerAccount->agency_id !== $agencyId
                    || $customerAccount->currency !== $currency
                    || $customerAccount->ledger_account_id !== $ledgerAccount->id) {
                    return ['errors' => ['lines.'.$index.'.customer_account_public_id' => ['The selected customer account must match the agency, currency, and ledger account.']], 'lines' => [], 'debit_total_minor' => 0];
                }

                $customerAccountId = $customerAccount->id;
            }

            $debit = $direction === 'debit' ? $amount : 0;
            $credit = $direction === 'credit' ? $amount : 0;
            $debitTotal += $debit;
            $creditTotal += $credit;
            if ($ledgerAccount->id === $tillLedgerAccountId) {
                if (! PhysicalCashAmount::validMinorAmount($amount, $currency)) {
                    return ['errors' => ['lines.'.$index.'.amount_minor' => [PhysicalCashAmount::validationMessage($currency)]], 'lines' => [], 'debit_total_minor' => 0];
                }

                $hasTillLine = true;
            }
            $memo = $rawLine['memo'] ?? null;

            $lines[] = [
                'ledger_account_id' => $ledgerAccount->id,
                'customer_account_id' => $customerAccountId,
                'debit_minor' => $debit,
                'credit_minor' => $credit,
                'memo' => is_string($memo) ? $memo : null,
            ];
        }

        if ($debitTotal !== $creditTotal) {
            return ['errors' => ['lines' => ['Manual cash journal lines must balance: total debit must equal total credit.']], 'lines' => [], 'debit_total_minor' => 0];
        }

        if (! $hasTillLine) {
            return ['errors' => ['lines' => ['Manual cash journal must include the teller session till ledger account.']], 'lines' => [], 'debit_total_minor' => 0];
        }

        return ['errors' => [], 'lines' => $lines, 'debit_total_minor' => $debitTotal];
    }

    private function transactionResponse(TellerTransaction $transaction, string $message): JsonResponse
    {
        $transaction->loadMissing(['tellerSession', 'till', 'customerAccount', 'journalEntry.lines.ledgerAccount', 'journalEntry.lines.customerAccount']);
        $journalEntry = $transaction->journalEntry;

        return $this->respondCreated([
            'teller_transaction' => TellerTransactionResource::make($transaction),
            'journal_entry' => $journalEntry instanceof JournalEntry ? JournalEntryResource::make($journalEntry) : null,
        ], $message);
    }
}
