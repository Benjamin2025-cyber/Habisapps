<?php

declare(strict_types=1);

namespace App\Application\CashOperations;

use App\Application\JournalEntries\CreateJournalEntryReversal;
use App\Http\Controllers\BaseController;
use App\Http\Requests\StoreCashDepositRequest;
use App\Http\Requests\StoreCashWithdrawalRequest;
use App\Http\Resources\JournalEntryResource;
use App\Http\Resources\TellerTransactionResource;
use App\Models\ClientProxy;
use App\Models\CustomerAccount;
use App\Models\CustomerAccountSignature;
use App\Models\Document;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\TellerSession;
use App\Models\TellerTransaction;
use App\Models\Till;
use App\Models\AccountingDay;
use App\Models\User;
use App\Support\Accounting\AccountingBalanceCalculator;
use App\Support\AccountingDay\AccountingDayGuard;
use App\Support\Crm\ClientProxyMandateAuthorizer;
use App\Support\Finance\PhysicalCashAmount;
use App\Support\Security\SecurityAudit;
use App\Support\Staff\StaffAgencyScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class TellerCashTransactionWorkflow extends BaseController
{
    public function __construct(
        private readonly SecurityAudit $securityAudit,
        private readonly StaffAgencyScope $staffAgencyScope,
        private readonly AccountingBalanceCalculator $balanceCalculator,
        private readonly ClientProxyMandateAuthorizer $proxyMandateAuthorizer,
        private readonly CreateJournalEntryReversal $createJournalEntryReversal,
        private readonly AccountingDayGuard $accountingDayGuard,
    ) {}

    /**
     * Resolve and assert the accounting day governing a teller session.
     *
     * Cash transactions inherit their session's accounting day; if the day has
     * since closed, the write is blocked even though the session row is "open".
     */
    private function resolveSessionAccountingDay(TellerSession $session, User $actor, string $operation, Request $request): AccountingDay
    {
        if ($session->accounting_day_id !== null) {
            $session->loadMissing('accountingDay');
            $day = $session->accountingDay;
            if ($day instanceof AccountingDay) {
                return $this->accountingDayGuard->assertDayAllowsRegistration($day, $actor, $operation, $request);
            }
        }

        return $this->accountingDayGuard->assertCanRegister($actor, $operation, $session->agency_id, $request);
    }

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

        $accountingDay = $this->resolveSessionAccountingDay($tellerSession, $actor, 'cash.deposit', $request);

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

        $initiator = $this->resolveInitiator($request, $customerAccount, TellerTransaction::TYPE_CASH_DEPOSIT, $amountMinor, $currency);
        if ($initiator['ok'] === false) {
            return $this->respondUnprocessable(errors: $initiator['errors']);
        }

        if ($till->max_balance_limit_minor !== null
            && $this->postedTillBalanceMinor($tellerSession) + $amountMinor > $till->max_balance_limit_minor) {
            return $this->respondUnprocessable(errors: ['amount_minor' => ['Deposit would push till balance above its maximum balance limit.']]);
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

        $result = DB::transaction(function () use ($request, $actor, $tellerSession, $till, $customerAccount, $customerLedger, $tillLedger, $amountMinor, $currency, $idempotencyKey, $initiator, $accountingDay): TellerTransaction {
            $publicId = (string) Str::ulid();
            $reference = 'CD-'.Str::upper(Str::random(10));

            $transaction = TellerTransaction::query()->create([
                'public_id' => $publicId,
                'teller_session_id' => $tellerSession->id,
                'accounting_day_id' => $accountingDay->id,
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
                'initiator_type' => $initiator['initiator_type'],
                'initiator_proxy_id' => $initiator['proxy_id'],
                'description' => $request->input('description'),
            ]);

            $journalEntry = JournalEntry::query()->create([
                'public_id' => (string) Str::ulid(),
                'reference' => 'JE-'.$reference,
                'business_date' => $tellerSession->business_date,
                'accounting_day_id' => $accountingDay->id,
                'posted_at' => null,
                'agency_id' => $tellerSession->agency_id,
                'source_module' => 'cash_operations',
                'source_type' => TellerTransaction::TYPE_CASH_DEPOSIT,
                'source_public_id' => $transaction->public_id,
                'status' => JournalEntry::STATUS_DRAFT,
                'description' => $request->input('description', 'Cash deposit '.$customerAccount->account_number),
                'created_by_user_id' => $actor->id,
                'posted_by_user_id' => null,
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
            $this->postSystemJournal($journalEntry, $actor);

            return $transaction->refresh();
        });

        $this->securityAudit->record('cash.deposit.posted', actor: $actor, subject: $result, properties: [
            'teller_session_public_id' => $tellerSession->public_id,
            'customer_account_public_id' => $customerAccount->public_id,
            'amount_minor' => $amountMinor,
            'currency' => $currency,
            'initiator_type' => $result->initiator_type,
            'initiator_proxy_id' => $result->initiator_proxy_id,
        ], request: $request);

        return $this->transactionResponse($result, 'Cash deposit posted successfully');
    }

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

        $accountingDay = $this->resolveSessionAccountingDay($tellerSession, $actor, 'cash.withdrawal', $request);

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

        $initiator = $this->resolveInitiator($request, $customerAccount, TellerTransaction::TYPE_CASH_WITHDRAWAL, $amountMinor, $currency);
        if ($initiator['ok'] === false) {
            return $this->respondUnprocessable(errors: $initiator['errors']);
        }

        $signatureCheck = $this->resolveWithdrawalSignatureCheck($request, $customerAccount, $initiator);
        if ($signatureCheck['ok'] === false) {
            return $this->respondUnprocessable(errors: $signatureCheck['errors']);
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

        try {
            $result = DB::transaction(function () use ($request, $actor, $tellerSession, $till, $customerAccount, $customerLedger, $tillLedger, $amountMinor, $currency, $idempotencyKey, $initiator, $signatureCheck, $accountingDay): TellerTransaction {
                DB::table('customer_accounts')->where('id', $customerAccount->id)->lockForUpdate()->first();
                $lockedAccount = CustomerAccount::query()->whereKey($customerAccount->id)->firstOrFail();
                $availableBalance = $this->balanceCalculator->availableForCustomerAccount($lockedAccount, $currency)['available_balance_minor'];
                if ($amountMinor > $availableBalance) {
                    throw new \DomainException('Withdrawal amount exceeds the customer account available balance.');
                }

                $reference = 'CW-'.Str::upper(Str::random(10));

                $transaction = TellerTransaction::query()->create([
                    'public_id' => (string) Str::ulid(),
                    'teller_session_id' => $tellerSession->id,
                    'accounting_day_id' => $accountingDay->id,
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
                    'initiator_type' => $initiator['initiator_type'],
                    'initiator_proxy_id' => $initiator['proxy_id'],
                    'customer_account_signature_id' => $signatureCheck['signature_id'],
                    'signature_checked_at' => now(),
                    'signature_checked_by_user_id' => $actor->id,
                    'signature_verification_method' => $signatureCheck['verification_method'],
                    'description' => $request->input('description'),
                ]);

                $journalEntry = JournalEntry::query()->create([
                    'public_id' => (string) Str::ulid(),
                    'reference' => 'JE-'.$reference,
                    'business_date' => $tellerSession->business_date,
                    'accounting_day_id' => $accountingDay->id,
                    'posted_at' => null,
                    'agency_id' => $tellerSession->agency_id,
                    'source_module' => 'cash_operations',
                    'source_type' => TellerTransaction::TYPE_CASH_WITHDRAWAL,
                    'source_public_id' => $transaction->public_id,
                    'status' => JournalEntry::STATUS_DRAFT,
                    'description' => $request->input('description', 'Cash withdrawal '.$customerAccount->account_number),
                    'created_by_user_id' => $actor->id,
                    'posted_by_user_id' => null,
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
                $this->postSystemJournal($journalEntry, $actor);

                return $transaction->refresh();
            });
        } catch (\DomainException $exception) {
            return $this->respondUnprocessable(errors: ['amount_minor' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('cash.withdrawal.posted', actor: $actor, subject: $result, properties: [
            'teller_session_public_id' => $tellerSession->public_id,
            'customer_account_public_id' => $customerAccount->public_id,
            'amount_minor' => $amountMinor,
            'currency' => $currency,
            'initiator_type' => $result->initiator_type,
            'initiator_proxy_id' => $result->initiator_proxy_id,
            'customer_account_signature_public_id' => $signatureCheck['signature_public_id'],
            'signature_verification_method' => $signatureCheck['verification_method'],
        ], request: $request);

        return $this->transactionResponse($result, 'Cash withdrawal posted successfully');
    }

    public function reverse(Request $request, TellerTransaction $tellerTransaction): JsonResponse
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

        $reversalResult = DB::transaction(function () use ($actor, $tellerTransaction): array {
            DB::table('teller_transactions')->where('id', $tellerTransaction->id)->lockForUpdate()->first();
            $locked = TellerTransaction::query()->whereKey($tellerTransaction->id)->firstOrFail();

            if ($locked->status !== TellerTransaction::STATUS_POSTED) {
                return ['ok' => false, 'message' => 'Only posted teller transactions can be reversed.'];
            }

            if (DB::table('teller_transactions')->where('reversal_of_teller_transaction_id', $locked->id)->exists()) {
                return ['ok' => false, 'message' => 'This teller transaction has already been reversed.'];
            }

            $journalEntry = JournalEntry::query()->with(['lines'])->whereKey($locked->journal_entry_id)->first();
            if (! $journalEntry instanceof JournalEntry || $journalEntry->status !== JournalEntry::STATUS_POSTED) {
                return ['ok' => false, 'message' => 'The linked journal entry must be posted before it can be reversed.'];
            }

            $reversalJournal = $this->createJournalEntryReversal->execute($actor, $journalEntry, postImmediately: true);
            $reversal = TellerTransaction::query()->create([
                'public_id' => (string) Str::ulid(),
                'teller_session_id' => $locked->teller_session_id,
                'accounting_day_id' => $reversalJournal->accounting_day_id,
                'agency_id' => $locked->agency_id,
                'transaction_date' => $locked->transaction_date,
                'till_id' => $locked->till_id,
                'transaction_type' => TellerTransaction::TYPE_REVERSAL,
                'client_id' => $locked->client_id,
                'customer_account_id' => $locked->customer_account_id,
                'loan_id' => $locked->loan_id,
                'amount_minor' => $locked->amount_minor,
                'currency' => $locked->currency,
                'status' => TellerTransaction::STATUS_POSTED,
                'reference' => $locked->reference.'-REV',
                'event_number' => $locked->event_number !== null ? $locked->event_number.'-REV' : null,
                'journal_entry_id' => $reversalJournal->id,
                'operation_code' => 'cash_reversal',
                'description' => 'Reversal of '.$locked->reference,
                'reversal_of_teller_transaction_id' => $locked->id,
            ]);

            $locked->update(['status' => TellerTransaction::STATUS_REVERSED]);

            return ['ok' => true, 'reversal' => $reversal, 'reversal_journal' => $reversalJournal];
        });

        if ($reversalResult['ok'] === false) {
            return $this->respondUnprocessable(errors: ['teller_transaction' => [$reversalResult['message']]]);
        }

        $reversal = $reversalResult['reversal'];
        $reversalJournal = $reversalResult['reversal_journal'];

        $this->securityAudit->record('cash.transaction.reversed', actor: $actor, subject: $reversal, properties: [
            'original_transaction_public_id' => $tellerTransaction->public_id,
            'reversal_journal_entry_public_id' => $reversalJournal->public_id,
        ], request: $request);

        return $this->transactionResponse($reversal, 'Cash transaction reversed successfully');
    }

    /**
     * @return array{ok: true, initiator_type: string, proxy_id: int|null}|array{ok: false, errors: array<string, array<int, string>>}
     */
    private function resolveInitiator(
        Request $request,
        CustomerAccount $customerAccount,
        string $operationType,
        int $amountMinor,
        string $currency,
    ): array {
        $initiatorType = $request->input('initiator_type');
        if (! is_string($initiatorType) || $initiatorType === '') {
            $initiatorType = TellerTransaction::INITIATOR_STAFF_ON_BEHALF;
        }

        $proxyPublicId = $request->input('initiator_proxy_public_id');
        $proxyPublicId = is_string($proxyPublicId) && $proxyPublicId !== '' ? $proxyPublicId : null;

        if ($initiatorType !== TellerTransaction::INITIATOR_PROXY && $proxyPublicId !== null) {
            return ['ok' => false, 'errors' => ['initiator_proxy_public_id' => ['A proxy public id is only valid when initiator_type is proxy.']]];
        }

        if ($initiatorType === TellerTransaction::INITIATOR_PROXY) {
            if ($proxyPublicId === null) {
                return ['ok' => false, 'errors' => ['initiator_proxy_public_id' => ['A proxy public id is required when initiator_type is proxy.']]];
            }

            $proxy = ClientProxy::query()->where('public_id', $proxyPublicId)->first();
            if (! $proxy instanceof ClientProxy) {
                return ['ok' => false, 'errors' => ['initiator_proxy_public_id' => ['The proxy does not exist.']]];
            }

            if (! $this->proxyMandateAuthorizer->allows($proxy, $customerAccount, $operationType, $amountMinor, $currency)) {
                return ['ok' => false, 'errors' => ['initiator_proxy_public_id' => ['The proxy is not authorized for this operation on the selected customer account.']]];
            }

            return ['ok' => true, 'initiator_type' => TellerTransaction::INITIATOR_PROXY, 'proxy_id' => $proxy->id];
        }

        if ($initiatorType === TellerTransaction::INITIATOR_HOLDER) {
            return ['ok' => true, 'initiator_type' => TellerTransaction::INITIATOR_HOLDER, 'proxy_id' => null];
        }

        return ['ok' => true, 'initiator_type' => $initiatorType, 'proxy_id' => null];
    }

    /**
     * @param  array{ok: true, initiator_type: string, proxy_id: int|null}|array{ok: false, errors: array<string, array<int, string>>}  $initiator
     * @return array{ok: true, signature_id: int, signature_public_id: string, verification_method: string}|array{ok: false, errors: array<string, array<int, string>>}
     */
    private function resolveWithdrawalSignatureCheck(Request $request, CustomerAccount $customerAccount, array $initiator): array
    {
        if ($initiator['ok'] === false) {
            return ['ok' => false, 'errors' => ['initiator_type' => ['The initiator must be valid before checking a signature.']]];
        }

        $signaturePublicId = $request->input('signature_public_id');
        if (! is_string($signaturePublicId) || $signaturePublicId === '') {
            return ['ok' => false, 'errors' => ['signature_public_id' => ['A checked account signature is required for cash withdrawals.']]];
        }

        $method = $request->input('signature_verification_method');
        if (! is_string($method) || ! in_array($method, [
            TellerTransaction::SIGNATURE_METHOD_VISUAL_MATCH,
            TellerTransaction::SIGNATURE_METHOD_THUMBPRINT_MATCH,
            TellerTransaction::SIGNATURE_METHOD_VERIFIED_PROXY_MANDATE,
            TellerTransaction::SIGNATURE_METHOD_EXCEPTION_OVERRIDE,
        ], true)) {
            return ['ok' => false, 'errors' => ['signature_verification_method' => ['A supported signature verification method is required.']]];
        }

        $signature = CustomerAccountSignature::query()
            ->with(['document'])
            ->where('public_id', $signaturePublicId)
            ->first();

        if (! $signature instanceof CustomerAccountSignature
            || $signature->agency_id !== $customerAccount->agency_id
            || $signature->customer_account_id !== $customerAccount->id
            || $signature->client_id !== $customerAccount->client_id
            || $signature->status !== CustomerAccountSignature::STATUS_ACTIVE
            || $signature->verified_at === null
            || ! $signature->document instanceof Document
            || $signature->document->status !== Document::STATUS_ACTIVE) {
            return ['ok' => false, 'errors' => ['signature_public_id' => ['The selected signature must be active, verified, document-backed, and tied to the withdrawal account.']]];
        }

        if ($initiator['initiator_type'] === TellerTransaction::INITIATOR_PROXY) {
            if ($signature->client_proxy_id === null || $signature->client_proxy_id !== $initiator['proxy_id']) {
                return ['ok' => false, 'errors' => ['signature_public_id' => ['Proxy withdrawals require a signature linked to the verified proxy mandate.']]];
            }

            if (! in_array($signature->signature_type, [CustomerAccountSignature::TYPE_PROXY, CustomerAccountSignature::TYPE_MANDATE], true)) {
                return ['ok' => false, 'errors' => ['signature_public_id' => ['Proxy withdrawals require a proxy or mandate signature specimen.']]];
            }
        } elseif (! in_array($signature->signature_type, [
            CustomerAccountSignature::TYPE_PRIMARY_HOLDER,
            CustomerAccountSignature::TYPE_JOINT_HOLDER,
            CustomerAccountSignature::TYPE_THUMBPRINT,
        ], true)) {
            return ['ok' => false, 'errors' => ['signature_public_id' => ['Holder withdrawals require a holder or thumbprint signature specimen.']]];
        }

        return [
            'ok' => true,
            'signature_id' => $signature->id,
            'signature_public_id' => $signature->public_id,
            'verification_method' => $method,
        ];
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

    private function transactionResponse(TellerTransaction $transaction, string $message): JsonResponse
    {
        $transaction->loadMissing(['tellerSession', 'till', 'customerAccount', 'initiatorProxy', 'customerAccountSignature', 'signatureCheckedBy', 'journalEntry.lines.ledgerAccount', 'journalEntry.lines.customerAccount']);
        $journalEntry = $transaction->journalEntry;

        return $this->respondCreated([
            'teller_transaction' => TellerTransactionResource::make($transaction),
            'journal_entry' => $journalEntry instanceof JournalEntry ? JournalEntryResource::make($journalEntry) : null,
        ], $message);
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
}
