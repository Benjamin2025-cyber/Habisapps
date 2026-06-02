<?php

declare(strict_types=1);

namespace App\Application\CashOperations;

use App\Application\JournalEntries\CreateJournalEntryReversal;
use App\Application\Notifications\UserNotificationFeed;
use App\Http\Controllers\BaseController;
use App\Http\Requests\StoreCashDepositRequest;
use App\Http\Requests\StoreCashWithdrawalRequest;
use App\Http\Resources\JournalEntryResource;
use App\Http\Resources\TellerTransactionResource;
use App\Models\AccountingDay;
use App\Models\Client;
use App\Models\ClientProxy;
use App\Models\CustomerAccount;
use App\Models\CustomerAccountSignature;
use App\Models\Denomination;
use App\Models\Document;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\TellerSession;
use App\Models\TellerTransaction;
use App\Models\TellerTransactionTender;
use App\Models\Till;
use App\Models\User;
use App\Support\Accounting\AccountingBalanceCalculator;
use App\Support\Accounting\AgencyLedgerMappingResolver;
use App\Support\AccountingDay\AccountingDayGuard;
use App\Support\Crm\ClientProxyMandateAuthorizer;
use App\Support\Finance\PhysicalCashAmount;
use App\Support\Security\SecurityAudit;
use App\Support\Staff\StaffAgencyScope;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * @phpstan-type DenominationLine array{denomination_public_id: string, count: int, amount_minor: int}
 * @phpstan-type TenderComponent array{method: string, amount_minor: int, debit_ledger_account_id: int|null, credit_ledger_account_id: int|null, ledger_mapping_evidence: array<string, mixed>, denomination_counts: array<int, DenominationLine>|null}
 * @phpstan-type TenderContract array{ok: true, payment_method: string, cash_amount_minor: int, cheque_amount_minor: int, transfer_amount_minor: int, channel: string, external_reference: string|null, fee_policy_key: string|null, notify_customer: bool, notification_channels: array<int, string>, cheque_number: mixed, cheque_bank_name: mixed, cheque_issue_date: mixed, tenders: array<int, TenderComponent>, fingerprint: string}
 */
final class TellerCashTransactionWorkflow extends BaseController
{
    public function __construct(
        private readonly SecurityAudit $securityAudit,
        private readonly StaffAgencyScope $staffAgencyScope,
        private readonly AccountingBalanceCalculator $balanceCalculator,
        private readonly ClientProxyMandateAuthorizer $proxyMandateAuthorizer,
        private readonly CreateJournalEntryReversal $createJournalEntryReversal,
        private readonly AccountingDayGuard $accountingDayGuard,
        private readonly UserNotificationFeed $notifications,
        private readonly AgencyLedgerMappingResolver $mappingResolver,
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
            ->with(['ledgerAccount', 'client'])
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

        try {
            $tender = $this->resolveTenderContract($request, $tellerSession, $till, $amountMinor, $currency, TellerTransaction::TYPE_CASH_DEPOSIT);
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['operation_account_mapping' => [$exception->getMessage()]]);
        }
        if ($tender['ok'] !== true) {
            return $this->respondUnprocessable(errors: $tender['errors']);
        }

        $initiator = $this->resolveInitiator($request, $customerAccount, TellerTransaction::TYPE_CASH_DEPOSIT, $amountMinor, $currency);
        if ($initiator['ok'] !== true) {
            return $this->respondUnprocessable(errors: $initiator['errors']);
        }

        if ($till->max_balance_limit_minor !== null
            && $this->postedTillBalanceMinor($tellerSession) + $tender['cash_amount_minor'] > $till->max_balance_limit_minor) {
            return $this->respondUnprocessable(errors: ['amount_minor' => ['Deposit would push till balance above its maximum balance limit.']]);
        }

        $idempotencyKey = $this->idempotencyKey($request->header('Idempotency-Key'), $request->input('idempotency_key'));
        if ($idempotencyKey !== null) {
            $existing = TellerTransaction::query()
                ->with(['tellerSession', 'till', 'customerAccount', 'journalEntry.lines', 'tenders'])
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existing instanceof TellerTransaction) {
                if (! $this->idempotentTenderMatches($existing, $tender)) {
                    return $this->respondUnprocessable(errors: ['idempotency_key' => ['Idempotency-Key has already been used for a different tender breakdown.']]);
                }

                return $this->transactionResponse($existing, 'Cash deposit already posted successfully');
            }
        }

        $result = DB::transaction(function () use ($request, $actor, $tellerSession, $till, $customerAccount, $customerLedger, $tillLedger, $amountMinor, $currency, $idempotencyKey, $initiator, $accountingDay, $tender): TellerTransaction {
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
                'payment_method' => $tender['payment_method'],
                'cash_amount_minor' => $tender['cash_amount_minor'],
                'cheque_amount_minor' => $tender['cheque_amount_minor'],
                'transfer_amount_minor' => $tender['transfer_amount_minor'],
                'channel' => $tender['channel'],
                'external_reference' => $tender['external_reference'],
                'fee_policy_key' => $tender['fee_policy_key'],
                'fees_applied' => false,
                'fee_amount_minor' => 0,
                'notify_customer' => $tender['notify_customer'],
                'notification_channels' => $tender['notification_channels'],
                'notification_status' => TellerTransaction::NOTIFICATION_NOT_REQUESTED,
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

            $this->createDepositTenderDebits($transaction, $journalEntry, $tender, $tillLedger, $tellerSession->agency_id, $currency);

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
            $this->queueCustomerNotificationIfRequested($transaction->refresh(), $customerAccount, $tender, 'cash_deposit_posted');

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
        $this->notifications->notifyAgency(
            agencyId: $result->agency_id,
            type: 'success',
            category: 'cash_deposit_posted',
            title: 'Cash deposit posted',
            message: 'A cash deposit of '.$amountMinor.' '.$currency.' was posted.',
            sourceType: TellerTransaction::class,
            sourcePublicId: $result->public_id,
            actionUrl: '/teller-transactions?filter[transaction_type]=cash_deposit',
            metadata: [
                'teller_session_public_id' => $tellerSession->public_id,
                'customer_account_public_id' => $customerAccount->public_id,
                'amount_minor' => $amountMinor,
                'cash_amount_minor' => $tender['cash_amount_minor'],
                'currency' => $currency,
            ],
        );

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
            ->with(['ledgerAccount', 'accountProduct', 'client'])
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

        try {
            $tender = $this->resolveTenderContract($request, $tellerSession, $till, $amountMinor, $currency, TellerTransaction::TYPE_CASH_WITHDRAWAL);
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['operation_account_mapping' => [$exception->getMessage()]]);
        }
        if ($tender['ok'] !== true) {
            return $this->respondUnprocessable(errors: $tender['errors']);
        }

        if ($till->max_withdrawal_limit_minor !== null && $tender['cash_amount_minor'] > $till->max_withdrawal_limit_minor) {
            return $this->respondUnprocessable(errors: ['amount_minor' => ['Withdrawal amount exceeds the till maximum withdrawal limit.']]);
        }

        if ($tender['cash_amount_minor'] > $this->postedTillBalanceMinor($tellerSession)) {
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
                ->with(['tellerSession', 'till', 'customerAccount', 'journalEntry.lines', 'tenders'])
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existing instanceof TellerTransaction) {
                if (! $this->idempotentTenderMatches($existing, $tender)) {
                    return $this->respondUnprocessable(errors: ['idempotency_key' => ['Idempotency-Key has already been used for a different tender breakdown.']]);
                }

                return $this->transactionResponse($existing, 'Cash withdrawal already posted successfully');
            }
        }

        try {
            $result = DB::transaction(function () use ($request, $actor, $tellerSession, $till, $customerAccount, $customerLedger, $tillLedger, $amountMinor, $currency, $idempotencyKey, $initiator, $signatureCheck, $accountingDay, $tender): TellerTransaction {
                DB::table('customer_accounts')->where('id', $customerAccount->id)->lockForUpdate()->first();
                $lockedAccount = CustomerAccount::query()->whereKey($customerAccount->id)->firstOrFail();
                $availableBalance = $this->balanceCalculator->availableForCustomerAccount($lockedAccount, $currency)['available_balance_minor'];
                if ($amountMinor > $availableBalance) {
                    throw new DomainException('Withdrawal amount exceeds the customer account available balance.');
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
                    'payment_method' => $tender['payment_method'],
                    'cash_amount_minor' => $tender['cash_amount_minor'],
                    'cheque_amount_minor' => $tender['cheque_amount_minor'],
                    'transfer_amount_minor' => $tender['transfer_amount_minor'],
                    'channel' => $tender['channel'],
                    'external_reference' => $tender['external_reference'],
                    'fee_policy_key' => $tender['fee_policy_key'],
                    'fees_applied' => false,
                    'fee_amount_minor' => 0,
                    'notify_customer' => $tender['notify_customer'],
                    'notification_channels' => $tender['notification_channels'],
                    'notification_status' => TellerTransaction::NOTIFICATION_NOT_REQUESTED,
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

                $this->createWithdrawalTenderCredits($transaction, $journalEntry, $tender, $tillLedger, $tellerSession->agency_id, $currency);

                $transaction->update(['journal_entry_id' => $journalEntry->id]);
                $this->postSystemJournal($journalEntry, $actor);
                $this->queueCustomerNotificationIfRequested($transaction->refresh(), $customerAccount, $tender, 'cash_withdrawal_posted');

                return $transaction->refresh();
            });
        } catch (DomainException $exception) {
            return $this->respondUnprocessable(errors: ['amount_minor' => [$exception->getMessage()]]);
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['operation_account_mapping' => [$exception->getMessage()]]);
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
        $this->notifications->notifyAgency(
            agencyId: $result->agency_id,
            type: 'warning',
            category: 'cash_withdrawal_posted',
            title: 'Cash withdrawal posted',
            message: 'A cash withdrawal of '.$amountMinor.' '.$currency.' was posted.',
            sourceType: TellerTransaction::class,
            sourcePublicId: $result->public_id,
            actionUrl: '/teller-transactions?filter[transaction_type]=cash_withdrawal',
            metadata: [
                'teller_session_public_id' => $tellerSession->public_id,
                'customer_account_public_id' => $customerAccount->public_id,
                'amount_minor' => $amountMinor,
                'cash_amount_minor' => $tender['cash_amount_minor'],
                'currency' => $currency,
            ],
        );

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
        $this->notifications->notifyAgency(
            agencyId: $reversal->agency_id,
            type: 'warning',
            category: 'cash_transaction_reversed',
            title: 'Cash transaction reversed',
            message: 'A teller transaction was reversed.',
            sourceType: TellerTransaction::class,
            sourcePublicId: $reversal->public_id,
            actionUrl: '/teller-transactions?filter[transaction_type]=cash_reversal',
            metadata: [
                'original_transaction_public_id' => $tellerTransaction->public_id,
                'reversal_journal_entry_public_id' => $reversalJournal->public_id,
            ],
        );

        return $this->transactionResponse($reversal, 'Cash transaction reversed successfully');
    }

    /**
     * @return TenderContract|array{ok: false, errors: array<string, array<int, string>>}
     */
    private function resolveTenderContract(
        Request $request,
        TellerSession $session,
        Till $till,
        int $amountMinor,
        string $currency,
        string $transactionType,
    ): array {
        $paymentMethod = $request->input('payment_method');
        $paymentMethod = is_string($paymentMethod) && $paymentMethod !== '' ? $paymentMethod : TellerTransaction::PAYMENT_CASH;

        $cashAmount = $request->has('cash_amount_minor') ? $request->integer('cash_amount_minor') : 0;
        $chequeAmount = $request->has('cheque_amount_minor') ? $request->integer('cheque_amount_minor') : 0;
        $transferAmount = $request->has('transfer_amount_minor') ? $request->integer('transfer_amount_minor') : 0;

        if ($paymentMethod === TellerTransaction::PAYMENT_CASH && ! $request->has('cash_amount_minor')) {
            $cashAmount = $amountMinor;
        }
        if ($paymentMethod === TellerTransaction::PAYMENT_CHEQUE && ! $request->has('cheque_amount_minor')) {
            $chequeAmount = $amountMinor;
        }
        if ($paymentMethod === TellerTransaction::PAYMENT_TRANSFER && ! $request->has('transfer_amount_minor')) {
            $transferAmount = $amountMinor;
        }

        $errors = [];
        if ($paymentMethod === TellerTransaction::PAYMENT_CASH && ($cashAmount !== $amountMinor || $chequeAmount !== 0 || $transferAmount !== 0)) {
            $errors['payment_method'][] = 'Cash payments must have cash_amount_minor equal to amount_minor and no non-cash components.';
        }
        if ($paymentMethod === TellerTransaction::PAYMENT_CHEQUE && ($chequeAmount !== $amountMinor || $cashAmount !== 0 || $transferAmount !== 0)) {
            $errors['payment_method'][] = 'Cheque payments must have cheque_amount_minor equal to amount_minor and no other components.';
        }
        if ($paymentMethod === TellerTransaction::PAYMENT_TRANSFER && ($transferAmount !== $amountMinor || $cashAmount !== 0 || $chequeAmount !== 0)) {
            $errors['payment_method'][] = 'Transfer payments must have transfer_amount_minor equal to amount_minor and no other components.';
        }
        if ($paymentMethod === TellerTransaction::PAYMENT_MIXED) {
            $positiveComponents = (int) ($cashAmount > 0) + (int) ($chequeAmount > 0) + (int) ($transferAmount > 0);
            if ($positiveComponents < 2 || $cashAmount + $chequeAmount + $transferAmount !== $amountMinor) {
                $errors['payment_method'][] = 'Mixed payments require at least two positive components whose total equals amount_minor.';
            }
        }

        if ($chequeAmount > 0) {
            foreach (['cheque_number', 'cheque_bank_name', 'cheque_issue_date'] as $key) {
                $value = $request->input($key);
                if (! is_string($value) || $value === '') {
                    $errors[$key][] = 'Cheque metadata is required when a cheque component is present.';
                }
            }
        }

        if ($transferAmount > 0) {
            $externalReference = $request->input('external_reference');
            if (! is_string($externalReference) || $externalReference === '') {
                $errors['external_reference'][] = 'External reference is required when a transfer component is present.';
            }
        }

        $denominationCounts = $this->validatedDenominationCounts(
            $request->input('denomination_counts'),
            $currency,
            $cashAmount,
            $till->requires_denominations && $cashAmount > 0,
        );
        if ($denominationCounts['errors'] !== []) {
            $errors = array_merge($errors, $denominationCounts['errors']);
        }

        $channel = $request->input('channel');
        $externalReference = $request->input('external_reference');
        $feePolicyKey = $request->input('fee_policy_key');
        $notificationChannels = $request->input('notification_channels', []);
        $notificationChannels = is_array($notificationChannels) ? array_values(array_unique(array_filter($notificationChannels, 'is_string'))) : [];

        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }

        $tenders = [];
        if ($cashAmount > 0) {
            $tenders[] = [
                'method' => TellerTransactionTender::METHOD_CASH,
                'amount_minor' => $cashAmount,
                'debit_ledger_account_id' => $transactionType === TellerTransaction::TYPE_CASH_DEPOSIT ? $till->ledger_account_id : null,
                'credit_ledger_account_id' => $transactionType === TellerTransaction::TYPE_CASH_WITHDRAWAL ? $till->ledger_account_id : null,
                'ledger_mapping_evidence' => ['source' => 'till_ledger'],
                'denomination_counts' => $denominationCounts['lines'],
            ];
        }

        foreach ([TellerTransactionTender::METHOD_CHEQUE => $chequeAmount, TellerTransactionTender::METHOD_TRANSFER => $transferAmount] as $method => $componentAmount) {
            if ($componentAmount <= 0) {
                continue;
            }

            $operationCode = $transactionType === TellerTransaction::TYPE_CASH_DEPOSIT
                ? 'cash_deposit_'.$method
                : 'cash_withdrawal_'.$method;
            $ledgerId = $transactionType === TellerTransaction::TYPE_CASH_DEPOSIT
                ? $this->mappingResolver->debitLedgerId($operationCode, 'cash', $session->agency_id, $currency)
                : $this->mappingResolver->creditLedgerId($operationCode, 'cash', $session->agency_id, $currency);

            $tenders[] = [
                'method' => $method,
                'amount_minor' => $componentAmount,
                'debit_ledger_account_id' => $transactionType === TellerTransaction::TYPE_CASH_DEPOSIT ? $ledgerId : null,
                'credit_ledger_account_id' => $transactionType === TellerTransaction::TYPE_CASH_WITHDRAWAL ? $ledgerId : null,
                'ledger_mapping_evidence' => [
                    'source' => 'operation_account_mapping',
                    'operation_code' => $operationCode,
                    'module' => 'cash',
                    'leg' => $transactionType === TellerTransaction::TYPE_CASH_DEPOSIT ? AgencyLedgerMappingResolver::LEG_DEBIT : AgencyLedgerMappingResolver::LEG_CREDIT,
                ],
                'denomination_counts' => null,
            ];
        }

        return [
            'ok' => true,
            'payment_method' => $paymentMethod,
            'cash_amount_minor' => $cashAmount,
            'cheque_amount_minor' => $chequeAmount,
            'transfer_amount_minor' => $transferAmount,
            'channel' => is_string($channel) && $channel !== '' ? $channel : 'branch_counter',
            'external_reference' => is_string($externalReference) && $externalReference !== '' ? $externalReference : null,
            'fee_policy_key' => is_string($feePolicyKey) && $feePolicyKey !== '' ? $feePolicyKey : null,
            'notify_customer' => $request->boolean('notify_customer'),
            'notification_channels' => $notificationChannels,
            'cheque_number' => $request->input('cheque_number'),
            'cheque_bank_name' => $request->input('cheque_bank_name'),
            'cheque_issue_date' => $request->input('cheque_issue_date'),
            'tenders' => $tenders,
            'fingerprint' => $this->tenderFingerprint($paymentMethod, $cashAmount, $chequeAmount, $transferAmount, is_string($channel) ? $channel : 'branch_counter', is_string($externalReference) ? $externalReference : null, is_string($feePolicyKey) ? $feePolicyKey : null),
        ];
    }

    /**
     * @param  TenderContract  $tender
     */
    private function createDepositTenderDebits(TellerTransaction $transaction, JournalEntry $journalEntry, array $tender, LedgerAccount $tillLedger, int $agencyId, string $currency): void
    {
        foreach ($tender['tenders'] as $component) {
            $ledgerId = $component['method'] === TellerTransactionTender::METHOD_CASH ? $tillLedger->id : $component['debit_ledger_account_id'];
            if (! is_int($ledgerId)) {
                throw new InvalidArgumentException('Tender debit ledger could not be resolved.');
            }

            JournalLine::query()->create([
                'public_id' => (string) Str::ulid(),
                'agency_id' => $agencyId,
                'journal_entry_id' => $journalEntry->id,
                'ledger_account_id' => $ledgerId,
                'customer_account_id' => null,
                'debit_minor' => $component['amount_minor'],
                'credit_minor' => 0,
                'currency' => $currency,
                'line_memo' => ucfirst($component['method']).' received',
            ]);

            $this->createTenderRow($transaction, $component, $tender, $currency);
        }
    }

    /**
     * @param  TenderContract  $tender
     */
    private function createWithdrawalTenderCredits(TellerTransaction $transaction, JournalEntry $journalEntry, array $tender, LedgerAccount $tillLedger, int $agencyId, string $currency): void
    {
        foreach ($tender['tenders'] as $component) {
            $ledgerId = $component['method'] === TellerTransactionTender::METHOD_CASH ? $tillLedger->id : $component['credit_ledger_account_id'];
            if (! is_int($ledgerId)) {
                throw new InvalidArgumentException('Tender credit ledger could not be resolved.');
            }

            JournalLine::query()->create([
                'public_id' => (string) Str::ulid(),
                'agency_id' => $agencyId,
                'journal_entry_id' => $journalEntry->id,
                'ledger_account_id' => $ledgerId,
                'customer_account_id' => null,
                'debit_minor' => 0,
                'credit_minor' => $component['amount_minor'],
                'currency' => $currency,
                'line_memo' => ucfirst($component['method']).' paid out',
            ]);

            $this->createTenderRow($transaction, $component, $tender, $currency);
        }
    }

    /**
     * @param  TenderComponent  $component
     * @param  TenderContract  $contract
     */
    private function createTenderRow(TellerTransaction $transaction, array $component, array $contract, string $currency): void
    {
        TellerTransactionTender::query()->create([
            'public_id' => (string) Str::ulid(),
            'teller_transaction_id' => $transaction->id,
            'method' => $component['method'],
            'amount_minor' => $component['amount_minor'],
            'currency' => $currency,
            'status' => TellerTransactionTender::STATUS_POSTED,
            'channel' => $contract['channel'],
            'external_reference' => $contract['external_reference'],
            'cheque_number' => $component['method'] === TellerTransactionTender::METHOD_CHEQUE ? $contract['cheque_number'] : null,
            'cheque_bank_name' => $component['method'] === TellerTransactionTender::METHOD_CHEQUE ? $contract['cheque_bank_name'] : null,
            'cheque_issue_date' => $component['method'] === TellerTransactionTender::METHOD_CHEQUE ? $contract['cheque_issue_date'] : null,
            'debit_ledger_account_id' => $component['debit_ledger_account_id'],
            'credit_ledger_account_id' => $component['credit_ledger_account_id'],
            'ledger_mapping_evidence' => $component['ledger_mapping_evidence'],
            'denomination_counts' => $component['denomination_counts'],
        ]);
    }

    /**
     * @return array{errors: array<string, array<int, string>>, lines: array<int, array{denomination_public_id: string, count: int, amount_minor: int}>}
     */
    private function validatedDenominationCounts(mixed $rawCounts, string $currency, int $expectedTotalMinor, bool $required): array
    {
        if (! is_array($rawCounts)) {
            return $required
                ? ['errors' => ['denomination_counts' => ['Denomination counts are required for the cash component.']], 'lines' => []]
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
                return ['errors' => ['denomination_counts.'.$index.'.denomination_public_id' => ['The selected denomination must be active and match the transaction currency.']], 'lines' => []];
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
            return ['errors' => ['denomination_counts' => ['Denomination counts must equal cash_amount_minor.']], 'lines' => []];
        }

        return ['errors' => [], 'lines' => $lines];
    }

    /**
     * @param  TenderContract  $tender
     */
    private function idempotentTenderMatches(TellerTransaction $existing, array $tender): bool
    {
        return $this->tenderFingerprint(
            $existing->payment_method ?? TellerTransaction::PAYMENT_CASH,
            $existing->cash_amount_minor,
            $existing->cheque_amount_minor,
            $existing->transfer_amount_minor,
            is_string($existing->channel) && $existing->channel !== '' ? $existing->channel : 'branch_counter',
            $existing->external_reference,
            $existing->fee_policy_key,
        ) === $tender['fingerprint'];
    }

    private function tenderFingerprint(string $paymentMethod, int $cashAmount, int $chequeAmount, int $transferAmount, string $channel, ?string $externalReference, ?string $feePolicyKey): string
    {
        return hash('sha256', json_encode([
            'payment_method' => $paymentMethod,
            'cash_amount_minor' => $cashAmount,
            'cheque_amount_minor' => $chequeAmount,
            'transfer_amount_minor' => $transferAmount,
            'channel' => $channel,
            'external_reference' => $externalReference,
            'fee_policy_key' => $feePolicyKey,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param  TenderContract  $tender
     */
    private function queueCustomerNotificationIfRequested(TellerTransaction $transaction, CustomerAccount $account, array $tender, string $category): void
    {
        if ($tender['notify_customer'] !== true) {
            return;
        }

        $client = $account->client;
        if (! $client instanceof Client) {
            $transaction->forceFill(['notification_status' => TellerTransaction::NOTIFICATION_FAILED])->save();

            return;
        }

        $channels = $tender['notification_channels'] !== [] ? $tender['notification_channels'] : ['sms'];
        $queued = false;
        foreach ($channels as $channel) {
            if ($channel === '') {
                continue;
            }

            $destination = $channel === 'email' ? $client->email : $client->phone_number;
            if (! is_string($destination) || $destination === '') {
                continue;
            }

            DB::table('notification_deliveries')->insertOrIgnore([
                'public_id' => (string) Str::ulid(),
                'notification_template_id' => null,
                'recipient_type' => Client::class,
                'recipient_id' => $client->id,
                'channel' => $channel,
                'category' => $category,
                'idempotency_key' => 'cash-'.$transaction->public_id.'-'.$channel,
                'destination' => $destination,
                'subject' => 'Cash transaction posted',
                'body' => 'A cash transaction of '.$transaction->amount_minor.' '.$transaction->currency.' was posted.',
                'status' => 'pending',
                'retry_count' => 0,
                'max_attempts' => 3,
                'scheduled_at' => now(),
                'metadata' => json_encode([
                    'teller_transaction_public_id' => $transaction->public_id,
                    'customer_account_public_id' => $account->public_id,
                ], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $queued = true;
        }

        $transaction->forceFill([
            'notification_status' => $queued ? TellerTransaction::NOTIFICATION_QUEUED : TellerTransaction::NOTIFICATION_FAILED,
        ])->save();
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
            ->get(['transaction_type', 'amount_minor', 'cash_amount_minor']);

        $movement = 0;
        foreach ($transactions as $transaction) {
            $type = is_string($transaction->transaction_type) ? $transaction->transaction_type : '';
            $amount = is_numeric($transaction->cash_amount_minor ?? null)
                ? (int) $transaction->cash_amount_minor
                : (is_numeric($transaction->amount_minor) ? (int) $transaction->amount_minor : 0);

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
        $transaction->loadMissing(['tellerSession', 'till', 'customerAccount', 'initiatorProxy', 'customerAccountSignature', 'signatureCheckedBy', 'journalEntry.lines.ledgerAccount', 'journalEntry.lines.customerAccount', 'tenders']);
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
